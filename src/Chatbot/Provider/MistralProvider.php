<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Chatbot\Provider;

use Pixiekat\EmpathicaChatbot\Chatbot\ChatRequest;

/**
 * Mistral, via its OpenAI-compatible chat completions API.
 *
 * @see https://docs.mistral.ai/api/#tag/chat
 */
final class MistralProvider extends OpenAiCompatibleProvider {

  /**
   * Provider key as stored in ChatbotPreferences.provider.
   */
  public const NAME = 'Mistral';

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return self::NAME;
  }

  /**
   * {@inheritdoc}
   */
  public function getModel(): string {
    return 'open-mistral-7b';
  }

  /**
   * {@inheritdoc}
   */
  public function getEndpoint(): string {
    $this->assertConfigured();

    return 'https://api.mistral.ai/v1/chat/completions';
  }

  /**
   * {@inheritdoc}
   *
   * BEHAVIOUR CHANGE, and a deliberate one. Before this class existed the
   * shared header block sent Mistral `x-api-key`, and only OpenAI was switched
   * over to `Authorization: Bearer`. Mistral does not recognise `x-api-key` —
   * its API documents bearer auth and nothing else — so every Mistral request
   * was going out effectively unauthenticated and coming back 401. Sending the
   * documented header cannot make that worse, and the payload tests cover the
   * body either way. Kept as a note rather than silently corrected because it
   * means Mistral has not actually been exercised in a while: treat its first
   * successful response as new, not as a regression baseline.
   */
  public function getHeaders(): array {
    return [
      'Authorization' => 'Bearer ' . $this->requireApiKey(),
      'Content-Type' => 'application/json',
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Mistral accepts the OpenAI body as-is; these extras are all explicit
   * statements of what would otherwise be defaults, with two exceptions worth
   * knowing about:
   *
   *   - safe_prompt: true asks Mistral to prepend its own safety system
   *     prompt. That is additive to our guardrails block, not a replacement
   *     for it, and it is on because this app discusses medication.
   *   - random_seed: null leaves sampling unseeded. Pinning it would make
   *     replies reproducible, which is tempting for a study — but it would
   *     also make a participant who asks the same question twice get a
   *     verbatim repeat, which reads as broken.
   */
  public function buildPayload(ChatRequest $request): array {
    return array_merge(parent::buildPayload($request), [
      'top_p' => 1,
      'stream' => false,
      'random_seed' => null,
      'response_format' => [
        'type' => 'text',
      ],
      'tools' => [],
      'tool_choice' => 'auto',
      'presence_penalty' => 0,
      'frequency_penalty' => 0,
      'n' => 1,
      'safe_prompt' => true,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function getApiKeyEnvVar(): string {
    return 'MISTRAL_API_KEY';
  }
}
