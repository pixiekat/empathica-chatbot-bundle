<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Tests\Services;

use Pixiekat\EmpathicaChatbot\Chatbot\Provider\AnthropicProvider;
use Pixiekat\EmpathicaChatbot\Chatbot\Provider\EmpathicaProvider;
use Pixiekat\EmpathicaChatbot\Chatbot\Provider\GeminiProvider;
use Pixiekat\EmpathicaChatbot\Chatbot\Provider\MistralProvider;
use Pixiekat\EmpathicaChatbot\Chatbot\Provider\OpenAiProvider;
use Pixiekat\EmpathicaChatbot\Chatbot\ProviderRegistry;
use Pixiekat\EmpathicaChatbot\Interfaces\Services\ChatbotManagerInterface;
use Pixiekat\EmpathicaChatbot\Interfaces\Services\ChatbotMessageInterface;
use Pixiekat\EmpathicaChatbot\Services\ChatbotManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Tests for the request body sent to each provider.
 *
 * getPayload() does no I/O, so this needs neither the network nor a kernel —
 * it is the cheapest possible guard on the part of the app most likely to
 * break silently. A malformed payload does not throw; it comes back as a
 * plausible-looking but degraded answer, which is exactly the failure mode
 * that is hardest to notice by hand.
 *
 * testGeminiDoesNotCapOutputTokens() is here because of a real incident:
 * setting maxOutputTokens to 1250 for parity with the other providers
 * truncated every Gemini reply to about one sentence, because the model's
 * internal thinking tokens are billed against that same budget.
 */
final class ChatbotManagerPayloadTest extends TestCase {

  /**
   * A manager wired to real providers, but to nothing that can reach a network.
   *
   * Every provider is constructed with an empty API key on purpose: building a
   * payload must never need one, so this doubles as proof that no credential
   * can leak into a request body. MockHttpClient is passed for the same
   * reason — if any of this started making a call, the test would fail rather
   * than quietly hit the internet.
   */
  private function payloadFor(string $provider, string $cacheTtl = '5m'): array {
    $registry = new ProviderRegistry([
      new AnthropicProvider('', $cacheTtl),
      new GeminiProvider(''),
      new OpenAiProvider(''),
      new MistralProvider(''),
      new EmpathicaProvider(new MockHttpClient()),
    ]);

    $manager = new ChatbotManager($registry, new MockHttpClient());
    $manager->configure($provider, ChatbotManagerInterface::PERSONA_EMILY, 2, 2);
    $manager->getChatbotMessage()->addMessage(ChatbotMessageInterface::USER, 'What are the side effects?');

    return $manager->getPayload();
  }

  /**
   * Anthropic takes the system prompt as a top-level parameter. It has no
   * 'system' role inside messages at all.
   */
  public function testAnthropicSendsSystemAsATopLevelParameter(): void {
    $payload = $this->payloadFor('Anthropic');

    $this->assertArrayHasKey('system', $payload);
    // Encoded rather than read directly: with prompt caching on, `system` is
    // an array of content blocks rather than a string. The point of this test
    // is that the instructions travel top-level and never as a message role —
    // testAnthropicMarksTheSystemPromptCacheable() covers the exact shape.
    $this->assertStringContainsString('EMPATHICA', json_encode($payload['system']));
    $this->assertSame(
      [ChatbotMessageInterface::USER],
      array_column($payload['messages'], 'role')
    );
  }

  /**
   * With caching on, the system prompt must be content blocks carrying a
   * cache_control breakpoint — cache_control cannot sit on a bare string.
   *
   * Worth pinning because the failure is invisible: send the string form and
   * Anthropic accepts it happily, charges full price for ~30,000 prompt
   * tokens on every single turn, and reports nothing unusual.
   */
  public function testAnthropicMarksTheSystemPromptCacheable(): void {
    $payload = $this->payloadFor('Anthropic');

    $this->assertIsArray($payload['system'], 'cache_control requires block form, not a string.');
    $this->assertCount(1, $payload['system']);
    $this->assertSame('text', $payload['system'][0]['type']);
    $this->assertStringContainsString('EMPATHICA', $payload['system'][0]['text']);
    $this->assertSame(['type' => 'ephemeral'], $payload['system'][0]['cache_control']);
  }

  /**
   * The one-hour TTL is opt-in and adds a ttl key rather than changing type.
   */
  public function testAnthropicHonoursTheOneHourCacheTtl(): void {
    $payload = $this->payloadFor('Anthropic', '1h');

    $this->assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $payload['system'][0]['cache_control']);
  }

  /**
   * Caching off falls back to the plain string, so the feature can be turned
   * off without changing anything else about the request.
   */
  public function testAnthropicCachingCanBeDisabled(): void {
    $payload = $this->payloadFor('Anthropic', '');

    $this->assertIsString($payload['system']);
    $this->assertStringContainsString('EMPATHICA', $payload['system']);
  }

  /**
   * Caching is Anthropic-specific plumbing and must not leak into the others.
   */
  public function testCachingDoesNotAffectOtherProviders(): void {
    foreach (['OpenAI', 'Mistral', ChatbotManagerInterface::CHATBOT_MODEL_GEMINI] as $provider) {
      $this->assertStringNotContainsString(
        'cache_control',
        json_encode($this->payloadFor($provider)),
        "{$provider} payload picked up Anthropic cache_control."
      );
    }
  }

  /**
   * OpenAI and Mistral take it as the first message, with role 'system'.
   */
  public function testOpenAiAndMistralPrependASystemMessage(): void {
    foreach (['OpenAI', 'Mistral'] as $provider) {
      $payload = $this->payloadFor($provider);

      $this->assertArrayNotHasKey('system', $payload, "{$provider} should not use a top-level system key.");
      $this->assertSame(
        ChatbotMessageInterface::SYSTEM,
        $payload['messages'][0]['role'],
        "{$provider} should lead with a system message."
      );
      $this->assertStringContainsString('EMPATHICA', $payload['messages'][0]['content']);
      $this->assertSame(ChatbotMessageInterface::USER, $payload['messages'][1]['role']);
    }
  }

  /**
   * Gemini uses its own system_instruction field, shaped like a message.
   */
  public function testGeminiUsesSystemInstruction(): void {
    $payload = $this->payloadFor(ChatbotManagerInterface::CHATBOT_MODEL_GEMINI);

    $this->assertArrayHasKey('system_instruction', $payload);
    $this->assertStringContainsString(
      'EMPATHICA',
      $payload['system_instruction']['parts'][0]['text']
    );
    // Same list-not-object requirement as a normal turn's parts.
    $this->assertStringStartsWith('[{"text":', json_encode($payload['system_instruction']['parts']));
    $this->assertArrayHasKey('contents', $payload);
  }

  /**
   * REGRESSION GUARD. Gemini models here are thinking models, and
   * thoughtsTokenCount is charged against maxOutputTokens alongside the
   * visible answer. Capping it at the 1250 used for the other providers left
   * roughly 50 tokens for the reply, so answers stopped after a sentence and
   * sometimes began mid-sentence. Leave the cap unset unless you have also
   * budgeted for thinking.
   */
  public function testGeminiDoesNotCapOutputTokens(): void {
    $payload = $this->payloadFor(ChatbotManagerInterface::CHATBOT_MODEL_GEMINI);

    $this->assertArrayNotHasKey(
      'maxOutputTokens',
      $payload['generationConfig'] ?? [],
      'Capping Gemini output truncates replies: thinking tokens share this budget.'
    );
  }

  /**
   * EmpathicaAgent has a bespoke, non-LLM-shaped contract: one flat string
   * plus its own level fields. It must not acquire the other providers' keys.
   */
  public function testEmpathicaAgentKeepsItsOwnSchema(): void {
    $payload = $this->payloadFor(ChatbotManagerInterface::CHATBOT_MODEL_EMPATHICA);

    $this->assertSame(
      ['message', 'drug_set_ids', 'user_id', 'literacy_level', 'empathy_level'],
      array_keys($payload)
    );
    $this->assertIsString($payload['message']);
    $this->assertStringContainsString('What are the side effects?', $payload['message']);
    // No Security service is wired in a plain unit test, so these fall back
    // to the anonymous default rather than fatalling on a null user.
    $this->assertSame('medium', $payload['literacy_level']);
    $this->assertSame('medium', $payload['empathy_level']);
  }

  /**
   * Every provider must actually receive the conversation. A payload that
   * carries instructions but loses the question would still look valid.
   */
  public function testEveryProviderReceivesTheUserMessage(): void {
    foreach (['Anthropic', 'OpenAI', 'Mistral', ChatbotManagerInterface::CHATBOT_MODEL_GEMINI] as $provider) {
      $this->assertStringContainsString(
        'What are the side effects?',
        json_encode($this->payloadFor($provider)),
        "{$provider} payload lost the user's message."
      );
    }
  }
}
