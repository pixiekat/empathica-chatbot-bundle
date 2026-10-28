<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Chatbot\Provider;

use Pixiekat\EmpathicaChatbot\Chatbot\ChatReply;
use Pixiekat\EmpathicaChatbot\Chatbot\ChatRequest;
use Pixiekat\EmpathicaChatbot\Interfaces\Services\ChatbotMessageInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Google Gemini, via the generateContent endpoint.
 *
 * Gemini disagrees with the other three on nearly every name: the conversation
 * is `contents` rather than `messages`, the model's turn is called 'model'
 * rather than 'assistant', a turn's text lives in a `parts` list rather than a
 * `content` string, the system prompt is `system_instruction`, and the
 * generation settings are camelCase (`generationConfig`, `maxOutputTokens`)
 * rather than snake_case. Every one of those has caused a bug in this app at
 * some point, which is the best possible argument for them all living in one
 * file.
 *
 * @see https://ai.google.dev/api/generate-content
 */
final class GeminiProvider extends AbstractChatProvider {

  /**
   * Provider key as stored in ChatbotPreferences.provider.
   */
  public const NAME = 'Gemini';

  /**
   * @param string $apiKey
   *   GEMINI_API_KEY. Shared with text-to-speech — see TextToSpeechManager.
   * @param RequestStack|null $requestStack
   *   Used only to build the Referer header. Null in CLI and test contexts,
   *   where there is no request to read.
   */
  public function __construct(
    string $apiKey = '',
    private readonly ?RequestStack $requestStack = null,
  ) {
    parent::__construct($apiKey);
  }

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return self::NAME;
  }

  /**
   * {@inheritdoc}
   *
   * 2026-05-27, KEB: bumped from gemini-2.5-flash to gemini-3.5-flash, which
   * shows strong performance on this use case. If more Gemini models are ever
   * offered at once, this wants to become a preference rather than a constant.
   */
  public function getModel(): string {
    return 'gemini-3.5-flash';
  }

  /**
   * {@inheritdoc}
   *
   * The key is NOT in this URL. Gemini accepts a `?key=` query parameter and
   * this app used to use it, which meant a live credential appeared in every
   * logged endpoint and in the text of any exception raised against it. It
   * goes in the x-goog-api-key header instead — see getHeaders() — and
   * sanitizeEndpointForLog() in ChatbotManager exists as a second line of
   * defence for exactly this class of mistake.
   */
  public function getEndpoint(): string {
    $this->assertConfigured();

    return sprintf(
      'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
      $this->getModel()
    );
  }

  /**
   * {@inheritdoc}
   *
   * The Referer is this app's own origin, sent to satisfy any HTTP referrer
   * restriction configured on the Gemini API key. (Katy's key is restricted to
   * *.dcinetwork.org and the local .test host, so without this a valid key
   * still comes back 403 API_KEY_HTTP_REFERRER_BLOCKED.)
   *
   * It reads Request::getSchemeAndHttpHost() rather than $_SERVER['HTTP_HOST']
   * — the latter is just the client's Host header, i.e. attacker-controlled.
   * Anyone able to reach the app could have set it to a domain of their
   * choosing and had us assert that origin to Google. getSchemeAndHttpHost()
   * resolves against Symfony's trusted-host and trusted-proxy configuration,
   * so it reflects where the app actually lives.
   */
  public function getHeaders(): array {
    return [
      'x-goog-api-key' => $this->requireApiKey(),
      'Content-Type' => 'application/json',
      'Referer' => $this->requestStack?->getCurrentRequest()?->getSchemeAndHttpHost() ?? 'localhost',
    ];
  }

  /**
   * {@inheritdoc}
   *
   * NO maxOutputTokens, deliberately. The Gemini models used here are thinking
   * models, and thoughtsTokenCount is billed against maxOutputTokens alongside
   * the visible answer. Setting it to 1250 to match the other providers
   * produced finishReason: MAX_TOKENS with ~1200 tokens of thinking and ~49
   * tokens of answer — replies cut off after a sentence, sometimes starting
   * mid-sentence. Leaving it unset uses the model default.
   *
   * If a cap is ever needed, budget for thinking too (observed ~1200-2200
   * thought tokens per turn) or constrain thinking directly via
   * generationConfig.thinkingConfig rather than the output cap.
   * ChatbotManagerPayloadTest guards this.
   */
  public function buildPayload(ChatRequest $request): array {
    $payload = [
      'contents' => array_map(
        fn (array $turn): array => [
          'role' => $turn['role'] === ChatbotMessageInterface::USER
            ? ChatbotMessageInterface::USER
            : ChatbotMessageInterface::MODEL,
          'parts' => $this->toParts($turn['content']),
        ],
        $request->thread
      ),
      'generationConfig' => [
        'temperature' => static::TEMPERATURE,
      ],
    ];

    if ($request->hasSystemInstruction()) {
      // Same parts-list shape as an ordinary turn, but with no role: it is not
      // something anyone said. It was previously injected into `contents` with
      // role 'model', i.e. as something Gemini had already told itself.
      $payload['system_instruction'] = [
        'parts' => $this->toParts($request->systemInstruction),
      ];
    }

    return $payload;
  }

  /**
   * Wrap text in Gemini's parts structure.
   *
   * `parts` is a LIST of part objects — [{"text": "..."}] — not a single
   * object. The distinction is invisible in PHP, where ['text' => ...] and
   * [['text' => ...]] are both "arrays", and only shows up once json_encode()
   * turns the first into {"text":"..."} — which the v1beta generateContent
   * endpoint rejects. ChatbotMessageTest asserts on the encoded JSON for
   * precisely this reason.
   *
   * @return list<array{text: string}>
   */
  private function toParts(string $text): array {
    return [
      ['text' => $text],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * All text parts of the first candidate are joined, not just parts[0]. A
   * long answer can legitimately arrive split across several parts, and
   * reading only the first would silently truncate it.
   *
   * Parts flagged `thought` are skipped. They only appear when thinking
   * summaries have been requested, which this app does not do — but a model
   * default could change that, and a participant must never be shown the
   * model's private reasoning about their medication.
   */
  public function parseReply(array $response): ChatReply {
    // Defensive at every step: an error body is also "a decoded response", and
    // parsing one must produce an empty reply rather than a PHP warning.
    $candidate = $response['candidates'][0] ?? [];
    $candidate = is_array($candidate) ? $candidate : [];
    $parts = $candidate['content']['parts'] ?? [];

    $text = '';
    foreach (is_array($parts) ? $parts : [] as $part) {
      if (!is_array($part) || ($part['thought'] ?? false) === true) {
        continue;
      }
      if (isset($part['text'])) {
        $text .= (string) $part['text'];
      }
    }

    $usage = $response['usageMetadata'] ?? [];
    $usage = is_array($usage) ? $usage : [];

    return new ChatReply(
      $text !== '' ? $text : null,
      isset($usage['promptTokenCount']) ? (int) $usage['promptTokenCount'] : null,
      isset($usage['candidatesTokenCount']) ? (int) $usage['candidatesTokenCount'] : null,
      // 'STOP' normally. 'MAX_TOKENS' is the one to watch — see buildPayload().
      isset($candidate['finishReason']) ? (string) $candidate['finishReason'] : null,
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getApiKeyEnvVar(): string {
    return 'GEMINI_API_KEY';
  }
}
