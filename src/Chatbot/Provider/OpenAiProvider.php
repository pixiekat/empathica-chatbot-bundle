<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Chatbot\Provider;

/**
 * OpenAI, via the Chat Completions API.
 *
 * The reference implementation of the dialect in OpenAiCompatibleProvider —
 * there is nothing here but the endpoint, the model, and bearer auth.
 *
 * @see https://platform.openai.com/docs/api-reference/chat
 */
final class OpenAiProvider extends OpenAiCompatibleProvider {

  /**
   * Provider key as stored in ChatbotPreferences.provider.
   */
  public const NAME = 'OpenAI';

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return self::NAME;
  }

  /**
   * {@inheritdoc}
   *
   * gpt-4o-mini rather than gpt-4o: this is a high-volume, long-prompt
   * workload (~30,000 tokens of system prompt per turn) where the full model
   * costs an order of magnitude more for no measurable benefit on the
   * simulated-patient task.
   */
  public function getModel(): string {
    return 'gpt-4o-mini';
  }

  /**
   * {@inheritdoc}
   */
  public function getEndpoint(): string {
    $this->assertConfigured();

    return 'https://api.openai.com/v1/chat/completions';
  }

  /**
   * {@inheritdoc}
   */
  public function getHeaders(): array {
    return [
      'Authorization' => 'Bearer ' . $this->requireApiKey(),
      'Content-Type' => 'application/json',
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getApiKeyEnvVar(): string {
    return 'OPENAI_API_KEY';
  }
}
