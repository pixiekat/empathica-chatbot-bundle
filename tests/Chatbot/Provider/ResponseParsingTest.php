<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Tests\Chatbot\Provider;

use Pixiekat\EmpathicaChatbot\Chatbot\Provider\AnthropicProvider;
use Pixiekat\EmpathicaChatbot\Chatbot\Provider\EmpathicaProvider;
use Pixiekat\EmpathicaChatbot\Chatbot\Provider\GeminiProvider;
use Pixiekat\EmpathicaChatbot\Chatbot\Provider\MistralProvider;
use Pixiekat\EmpathicaChatbot\Chatbot\Provider\OpenAiProvider;
use Pixiekat\EmpathicaChatbot\Interfaces\Services\ChatProviderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Tests for reading a provider's answer back out.
 *
 * NEW COVERAGE. Response parsing had none: it lived in a private method on
 * AppController, reachable only through a controller action that needed a
 * kernel, a database, a logged-in user and a live provider. So the half of the
 * integration most likely to break silently — a provider renaming a field, or
 * a token count being read from the wrong key — was the half nobody could
 * test. Moving it onto the provider classes is what made these possible, and
 * they are arguably the most valuable tests in the suite.
 *
 * The fixtures are trimmed real responses, not invented shapes.
 */
final class ResponseParsingTest extends TestCase {

  /**
   * Every provider, keyed by name, ready to parse.
   *
   * @return array<string, ChatProviderInterface>
   */
  private function providers(): array {
    return [
      'Anthropic' => new AnthropicProvider(),
      'Gemini' => new GeminiProvider(),
      'OpenAI' => new OpenAiProvider(),
      'Mistral' => new MistralProvider(),
      'EmpathicaAgent' => new EmpathicaProvider(new MockHttpClient()),
    ];
  }

  /**
   * Anthropic reports cached and uncached input tokens SEPARATELY, and the
   * three must be summed.
   *
   * This is the assertion this whole file exists for. With prompt caching on,
   * `input_tokens` counts only what came after the last cache breakpoint — so
   * reading it alone reports ~42 prompt tokens for a request that really sent
   * 30,042. Nothing errors, nothing looks wrong, and every cached turn in the
   * research data quietly understates its own cost by three orders of
   * magnitude.
   */
  public function testAnthropicSumsCachedAndUncachedInputTokens(): void {
    $reply = (new AnthropicProvider())->parseReply([
      'content' => [['type' => 'text', 'text' => 'Take it with food.']],
      'stop_reason' => 'end_turn',
      'usage' => [
        'input_tokens' => 42,
        'cache_creation_input_tokens' => 1000,
        'cache_read_input_tokens' => 29000,
        'output_tokens' => 348,
      ],
    ]);

    $this->assertSame('Take it with food.', $reply->content);
    $this->assertSame(30042, $reply->promptTokens, 'The cached prefix was not counted.');
    $this->assertSame(348, $reply->completionTokens);
    $this->assertSame('end_turn', $reply->finishReason);
  }

  /**
   * Uncached responses have no cache keys at all, and must not become null.
   */
  public function testAnthropicHandlesAnUncachedResponse(): void {
    $reply = (new AnthropicProvider())->parseReply([
      'content' => [['type' => 'text', 'text' => 'Hello.']],
      'usage' => ['input_tokens' => 512, 'output_tokens' => 20],
    ]);

    $this->assertSame(512, $reply->promptTokens);
  }

  /**
   * Only text blocks are read. A thinking or tool-use block appearing in
   * `content` must never be concatenated into what a participant reads.
   */
  public function testAnthropicIgnoresNonTextContentBlocks(): void {
    $reply = (new AnthropicProvider())->parseReply([
      'content' => [
        ['type' => 'thinking', 'thinking' => 'The user seems anxious about side effects.'],
        ['type' => 'text', 'text' => 'That is a very common worry.'],
      ],
    ]);

    $this->assertSame('That is a very common worry.', $reply->content);
  }

  /**
   * Gemini keeps its counts under usageMetadata with entirely different names.
   *
   * Several text parts are joined, not just the first: a long answer can
   * legitimately arrive split, and reading parts[0] alone would truncate it
   * without any sign that it had.
   */
  public function testGeminiJoinsPartsAndReadsUsageMetadata(): void {
    $reply = (new GeminiProvider())->parseReply([
      'candidates' => [
        [
          'content' => [
            'parts' => [
              ['text' => 'Hello Emily. '],
              ['text' => 'Metformin is usually taken with a meal.'],
            ],
          ],
          'finishReason' => 'STOP',
        ],
      ],
      'usageMetadata' => [
        'promptTokenCount' => 29500,
        'candidatesTokenCount' => 210,
        'thoughtsTokenCount' => 1400,
      ],
    ]);

    $this->assertSame('Hello Emily. Metformin is usually taken with a meal.', $reply->content);
    $this->assertSame(29500, $reply->promptTokens);
    $this->assertSame(210, $reply->completionTokens);
    $this->assertSame('STOP', $reply->finishReason);
  }

  /**
   * Gemini's private reasoning must never reach a participant.
   *
   * This app does not request thinking summaries, so `thought` parts should
   * not appear — but a model default could change that, and the cost of being
   * wrong is showing someone the model's unfiltered deliberation about their
   * own medication.
   */
  public function testGeminiSkipsThoughtParts(): void {
    $reply = (new GeminiProvider())->parseReply([
      'candidates' => [[
        'content' => [
          'parts' => [
            ['text' => 'She may be non-adherent; probe gently.', 'thought' => TRUE],
            ['text' => 'How have you been getting on with it?'],
          ],
        ],
      ]],
    ]);

    $this->assertSame('How have you been getting on with it?', $reply->content);
  }

  /**
   * REGRESSION GUARD, and the reason ChatReply keeps metadata on a failure.
   *
   * A thinking model that exhausts its output budget on thoughts returns a
   * candidate with no text at all and finishReason MAX_TOKENS. That happened
   * here for real. If parsing returned a blank reply and dropped the reason
   * with it, the log would say only "empty or unrecognised" — which is exactly
   * how that incident stayed puzzling for as long as it did.
   */
  public function testGeminiKeepsTheFinishReasonWhenThereIsNoText(): void {
    $reply = (new GeminiProvider())->parseReply([
      'candidates' => [['finishReason' => 'MAX_TOKENS']],
      'usageMetadata' => ['promptTokenCount' => 29500, 'thoughtsTokenCount' => 1197],
    ]);

    $this->assertTrue($reply->isEmpty());
    $this->assertSame('MAX_TOKENS', $reply->finishReason, 'The diagnosis was thrown away with the answer.');
  }

  /**
   * OpenAI and Mistral share one envelope, so one implementation serves both.
   */
  public function testOpenAiAndMistralReadTheSameShape(): void {
    $response = [
      'choices' => [[
        'message' => ['role' => 'assistant', 'content' => 'Take one tablet twice daily.'],
        'finish_reason' => 'stop',
      ]],
      'usage' => ['prompt_tokens' => 28900, 'completion_tokens' => 61],
    ];

    foreach ([new OpenAiProvider(), new MistralProvider()] as $provider) {
      $reply = $provider->parseReply($response);

      $this->assertSame('Take one tablet twice daily.', $reply->content, $provider->getName());
      $this->assertSame(28900, $reply->promptTokens, $provider->getName());
      $this->assertSame(61, $reply->completionTokens, $provider->getName());
      $this->assertSame('stop', $reply->finishReason, $provider->getName());
    }
  }

  /**
   * EmpathicaAgent answers with one field and reports no usage at all.
   *
   * Null rather than zero matters here: a stored 0 would read as "this request
   * genuinely cost nothing", which is not the same claim as "the agent does
   * not tell us". In a research dataset that difference is the difference
   * between a measurement and a fabrication.
   */
  public function testEmpathicaAgentReportsNoUsageRatherThanZero(): void {
    $reply = (new EmpathicaProvider(new MockHttpClient()))->parseReply([
      'answer' => 'I understand that sounds worrying.',
    ]);

    $this->assertSame('I understand that sounds worrying.', $reply->content);
    $this->assertNull($reply->promptTokens);
    $this->assertNull($reply->completionTokens);
    $this->assertNull($reply->finishReason);
  }

  /**
   * Parsing must be total. Any array at all is valid input — including an
   * upstream error body — and none of them may throw, because an exception
   * here would lose the user's own message along with the failed reply.
   */
  public function testNoProviderThrowsOnAnUnrecognisedBody(): void {
    $rubbish = [
      [],
      ['error' => ['message' => 'invalid_api_key', 'type' => 'authentication_error']],
      ['choices' => []],
      ['content' => 'a string where a list belongs'],
      ['candidates' => [['content' => ['parts' => []]]]],
    ];

    foreach ($this->providers() as $name => $provider) {
      foreach ($rubbish as $index => $body) {
        $reply = $provider->parseReply($body);

        $this->assertTrue(
          $reply->isEmpty(),
          "{$name} found an answer in unrecognisable body #{$index}."
        );
      }
    }
  }

  /**
   * A whitespace-only answer is a failure, not a reply.
   *
   * Storing it would put a blank assistant turn in the transcript, which reads
   * as the chatbot having said nothing on purpose.
   */
  public function testWhitespaceOnlyContentCountsAsEmpty(): void {
    $reply = (new OpenAiProvider())->parseReply([
      'choices' => [['message' => ['content' => "  \n  "]]],
    ]);

    $this->assertTrue($reply->isEmpty());
  }
}
