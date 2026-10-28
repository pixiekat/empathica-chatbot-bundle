<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Chatbot\Exception;

/**
 * A provider was selected that has no API key configured.
 *
 * This is an operator error, not a user error: the environment variable is
 * missing (or blank) in .env.local. Raising it *before* the request is built
 * turns what used to be an opaque 401 from upstream — or, for Gemini, a
 * request to the empty-string URL — into a log line that names the variable to
 * go and set.
 *
 * The user still sees the ordinary "temporarily unavailable" message:
 * ChatController catches \Exception around the whole exchange, so this
 * inherits the existing generic-error-plus-full-log behaviour for free.
 */
final class ProviderNotConfiguredException extends \RuntimeException {

  /**
   * @param string $provider
   *   The provider key, e.g. 'Anthropic'.
   * @param string $envVar
   *   The environment variable that should hold the key, e.g.
   *   'ANTHROPIC_API_KEY'. Named explicitly so the fix is in the message
   *   rather than requiring a trip through services.yaml to work out.
   */
  public function __construct(string $provider, string $envVar) {
    parent::__construct(sprintf(
      'No API key configured for the "%s" chatbot. Set %s in .env.local (see .env.example).',
      $provider,
      $envVar
    ));
  }
}
