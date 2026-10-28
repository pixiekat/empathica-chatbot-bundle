<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Chatbot\Provider;

use Pixiekat\EmpathicaChatbot\Chatbot\ChatReply;
use Pixiekat\EmpathicaChatbot\Chatbot\ChatRequest;

/**
 * Anthropic, via the Messages API.
 *
 * Two things make this provider unlike the others, and both are load-bearing:
 * the system prompt is a top-level request parameter with no 'system' role in
 * `messages` at all, and it is the only provider here where we pay to cache
 * that prompt between turns.
 *
 * @see https://platform.claude.com/docs/en/api/messages
 */
final class AnthropicProvider extends AbstractChatProvider {

  /**
   * Provider key as stored in ChatbotPreferences.provider.
   *
   * Historical rows also contain the typo 'Antropic'. That is normalised once,
   * in ProviderRegistry, rather than being checked for here — see the alias
   * map there.
   */
  public const NAME = 'Anthropic';

  /**
   * The API version header value.
   *
   * Anthropic versions by date and requires this on every request. It pins the
   * response shape, so it should only be moved forward deliberately, with
   * parseReply() re-read against the changelog.
   */
  private const API_VERSION = '2023-06-01';

  /**
   * @param string $apiKey
   *   ANTHROPIC_API_KEY.
   * @param string $promptCacheTtl
   *   '5m', '1h', or '' to disable prompt caching entirely. See
   *   buildPayload() for what each does and config/services.yaml for why 5m
   *   is the default.
   */
  public function __construct(
    string $apiKey = '',
    private readonly string $promptCacheTtl = '5m',
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
   * Haiku rather than Sonnet: the workload is a large fixed system prompt plus
   * a short exchange, which is where the cheaper model holds up best. The
   * "-latest" alias is used deliberately so security and quality updates are
   * picked up without a code change; if a study needs a frozen model for
   * reproducibility, pin the dated id here and note it in the prompt version.
   */
  public function getModel(): string {
    return 'claude-3-5-haiku-latest';
  }

  /**
   * {@inheritdoc}
   */
  public function getEndpoint(): string {
    $this->assertConfigured();

    return 'https://api.anthropic.com/v1/messages';
  }

  /**
   * {@inheritdoc}
   *
   * No anthropic-beta header. This used to send 'pdfs-2024-09-25', opting into
   * a PDF-input beta that has since gone GA — and we never send PDFs anyway.
   * Naming a retired beta is a liability: the API may reject an unrecognised
   * value outright. Prompt caching, the one beta we would otherwise want, is
   * itself GA and needs no header.
   */
  public function getHeaders(): array {
    return [
      'x-api-key' => $this->requireApiKey(),
      'anthropic-version' => self::API_VERSION,
      'Content-Type' => 'application/json',
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The system prompt is a top-level `system` parameter. Passing it as a
   * message — which this app used to do, tagged 'assistant' — meant the model
   * read the entire persona and guardrail block as its own previous output
   * rather than as instructions to follow.
   */
  public function buildPayload(ChatRequest $request): array {
    $payload = [
      'model' => $this->getModel(),
      'temperature' => static::TEMPERATURE,
      'max_tokens' => static::MAX_TOKENS,
      'messages' => $request->thread,
    ];

    if ($request->hasSystemInstruction()) {
      $payload['system'] = $this->buildSystem($request->systemInstruction);
    }

    return $payload;
  }

  /**
   * The `system` parameter, in string or cacheable-block form.
   *
   * PROMPT CACHING
   *
   * The system prompt is ~30,000 tokens and is resent verbatim on every turn,
   * so it dominates the bill: a typical exchange here is 30,067 prompt tokens
   * against 348 of answer. Marking it cacheable means subsequent turns read it
   * back at a tenth of the input price rather than paying full rate each time.
   *
   * cache_control has to sit on a content block, so `system` becomes a
   * one-element array rather than a string. Caching is GA, and a prompt below
   * the model's minimum cacheable length is simply processed uncached rather
   * than rejected — so this is safe even for short prompts.
   *
   * @see https://platform.claude.com/docs/en/build-with-claude/prompt-caching
   *
   * @return string|array<int, array<string, mixed>>
   */
  private function buildSystem(string $systemInstruction) {
    if ($this->promptCacheTtl === '') {
      // Caching off: the plain string form, exactly as before caching existed.
      return $systemInstruction;
    }

    $cacheControl = ['type' => 'ephemeral'];
    if ($this->promptCacheTtl === '1h') {
      $cacheControl['ttl'] = '1h';
    }

    return [
      [
        'type' => 'text',
        'text' => $systemInstruction,
        'cache_control' => $cacheControl,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Anthropic says input/output where the OpenAI-shaped APIs say
   * prompt/completion — and with prompt caching on, `input_tokens` counts only
   * what came *after* the last cache breakpoint. The cached prefix is reported
   * separately, so reading input_tokens alone would show ~50 prompt tokens for
   * a request that really sent 30,000, and every cached turn in the research
   * data would silently understate its own cost.
   *
   * The documented identity is:
   *   total input = cache_read + cache_creation + input_tokens
   */
  public function parseReply(array $response): ChatReply {
    $usage = $response['usage'] ?? [];

    $promptTokens = null;
    if (is_array($usage) && $usage !== []) {
      $promptTokens = (int) ($usage['cache_read_input_tokens'] ?? 0)
        + (int) ($usage['cache_creation_input_tokens'] ?? 0)
        + (int) ($usage['input_tokens'] ?? 0);
    }

    // `content` is a list of blocks. Only text blocks are of interest — a
    // future tool-use or thinking block would appear here too, and must not be
    // concatenated into something a participant reads.
    //
    // is_array() rather than a bare foreach: an error body may well have a
    // `content` key holding a plain string, and parsing must never raise.
    $blocks = $response['content'] ?? [];
    $text = '';
    foreach (is_array($blocks) ? $blocks : [] as $block) {
      if (is_array($block) && ($block['type'] ?? null) === 'text' && isset($block['text'])) {
        $text .= (string) $block['text'];
      }
    }

    return new ChatReply(
      $text !== '' ? $text : null,
      $promptTokens,
      isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : null,
      // 'end_turn' normally; 'max_tokens' when the cap was hit.
      isset($response['stop_reason']) ? (string) $response['stop_reason'] : null,
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getApiKeyEnvVar(): string {
    return 'ANTHROPIC_API_KEY';
  }
}
