<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Chatbot\Exception;

/**
 * A provider answered, but with an HTTP error status.
 *
 * WHY THIS EXISTS AT ALL
 *
 * Guzzle threw RequestException on a 4xx/5xx because its `http_errors` option
 * is on by default. symfony/http-client does not work that way: responses are
 * lazy, and a 500 only surfaces as an exception at the moment you ask for the
 * content. Since ChatbotManager deliberately reads the body with
 * getContent(false) — it wants to *log* the upstream error text, which is
 * usually the only explanation of what went wrong — nothing would throw on its
 * own. This restores the contract callers already rely on: a failed provider
 * call raises, it does not return a misleading null.
 *
 * The message carries the status code and provider only. The upstream body
 * frequently echoes back part of the request, which here means the simulated
 * clinical conversation, so it is logged (truncated) rather than embedded in
 * an exception that could end up rendered on an error page.
 */
final class ProviderRequestException extends \RuntimeException {

  public function __construct(
    string $provider,
    private readonly int $statusCode,
  ) {
    parent::__construct(sprintf(
      'The "%s" chatbot returned HTTP %d. See the chatbot log channel for the response body.',
      $provider,
      $statusCode
    ));
  }

  /**
   * The upstream status code, for callers that want to distinguish (say) a
   * 429 from a 401 without parsing the message.
   */
  public function getStatusCode(): int {
    return $this->statusCode;
  }
}
