<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Chatbot\Provider;

use Pixiekat\EmpathicaChatbot\Chatbot\Exception\ProviderNotConfiguredException;
use Pixiekat\EmpathicaChatbot\Interfaces\Services\ChatProviderInterface;

/**
 * Shared plumbing for the providers that authenticate with a plain API key.
 *
 * That is all four commercial providers. EmpathicaAgent deliberately does not
 * extend this: it authenticates with a Google-signed OIDC identity token and
 * has no key at all, so inheriting key handling would only give it a field it
 * must never use.
 *
 * THE KEY IS INJECTED, NOT LOOKED UP
 *
 * Each provider receives its own key as a constructor argument, wired from
 * %env(...)% in config/services.yaml. This replaced a single array of every
 * key, indexed by provider name, handed to one object that then picked one out
 * — which meant every provider's credential was in scope for every request no
 * matter which one was actually being used. Now a GeminiProvider instance
 * simply has no way to see the Anthropic key.
 */
abstract class AbstractChatProvider implements ChatProviderInterface {

  /**
   * Sampling temperature.
   *
   * 0.4 is low enough to keep a simulated patient consistent across a
   * conversation, high enough that repeated questions do not come back
   * word-for-word identical. Shared so the experimental conditions differ by
   * prompt alone and not by an accidental per-provider sampling difference.
   */
  protected const TEMPERATURE = 0.4;

  /**
   * Cap on generated tokens.
   *
   * Sized for a few paragraphs of patient-facing explanation. Note that
   * GeminiProvider deliberately does not apply it — see the comment there,
   * it is not an oversight.
   */
  protected const MAX_TOKENS = 1250;

  /**
   * @param string $apiKey
   *   The provider's credential, or an empty string when unconfigured. Empty
   *   is a legitimate state: an install that only uses one provider should not
   *   have to hold keys for the other three, and nothing fails until somebody
   *   actually selects the unconfigured one in their preferences.
   */
  public function __construct(
    protected readonly string $apiKey = '',
  ) {
  }

  /**
   * The environment variable that holds this provider's key.
   *
   * Used only to write a useful error message. Keeping it next to the key
   * itself means the message cannot drift out of date the way a hardcoded
   * string in a central error handler would.
   */
  abstract protected function getApiKeyEnvVar(): string;

  /**
   * Fail early, and specifically, when there is no key to send.
   *
   * Called from getEndpoint() so that the failure happens before anything is
   * built or sent. Without this the request goes out unauthenticated and comes
   * back as a generic 401, which tells an operator nothing about *which* of
   * four variables they forgot.
   *
   * @throws ProviderNotConfiguredException
   */
  protected function assertConfigured(): void {
    if (trim($this->apiKey) === '') {
      throw new ProviderNotConfiguredException($this->getName(), $this->getApiKeyEnvVar());
    }
  }

  /**
   * The key, guaranteed non-empty.
   *
   * @throws ProviderNotConfiguredException
   */
  protected function requireApiKey(): string {
    $this->assertConfigured();

    return $this->apiKey;
  }
}
