<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Services;

use Pixiekat\EmpathicaChatbot\Chatbot\ChatReply;
use Pixiekat\EmpathicaChatbot\Chatbot\ChatRequest;
use Pixiekat\EmpathicaChatbot\Chatbot\Exception\ProviderRequestException;
use Pixiekat\EmpathicaChatbot\Chatbot\ProviderRegistry;
use Pixiekat\EmpathicaChatbot\Entity;
use Pixiekat\EmpathicaChatbot\Interfaces;
use Pixiekat\EmpathicaChatbot\Interfaces\Services\ChatProviderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Runs one exchange with whichever chatbot the user has selected.
 *
 * WHAT THIS CLASS IS NOW
 *
 * Three things, and nothing else: it assembles the prompt (via ChatbotMessage
 * and PromptRepository), it resolves the selected provider (via
 * ProviderRegistry), and it performs the HTTP call. Everything that differs
 * between providers — endpoints, credentials, models, request bodies, response
 * shapes — lives in Pixiekat\EmpathicaChatbot\Chatbot\Provider\*, one class each.
 *
 * WHAT IT USED TO BE
 *
 * The same three responsibilities plus five parallel `switch ($this->_chatbot)`
 * statements, with a sixth (response parsing) stranded in AppController. Adding
 * a provider meant finding all six. Missing one did not throw: it produced a
 * well-formed request that quietly ignored half of what we meant to say, which
 * in a study is worse than a crash, because the transcript still looks fine.
 *
 * The HTTP call stays here on purpose. Timeouts, timing, log redaction and
 * error handling are written once, and cannot drift apart between providers.
 *
 * Registered `shared: false` in services.yaml: configure() writes per-request
 * state onto the instance, so every injection gets a fresh object and that
 * state cannot leak between requests, or between two uses in one request.
 */
class ChatbotManager implements Interfaces\Services\ChatbotManagerInterface {

  /**
   * Seconds of silence before a provider call is abandoned.
   *
   * NOT the same knob as Guzzle's connect_timeout, which this replaced.
   * symfony/http-client has no separate connect timeout: `timeout` is an
   * inactivity timeout covering the whole exchange, connection included. It
   * therefore has to be generous enough for a slow model to think — these are
   * non-streaming requests, so nothing at all comes back until the answer is
   * complete — which means a dead host now takes this long to notice rather
   * than the old 5 seconds. max_duration is what stops a genuinely stuck call.
   */
  private const IDLE_TIMEOUT = 60;

  /**
   * Hard ceiling on a single provider call, in seconds.
   *
   * Without a ceiling a hung upstream ties up a PHP-FPM worker until Apache's
   * MaxRequestWorkers fills and the whole site stops accepting requests. Raise
   * it if reasoning-heavy models are ever put in front of users.
   */
  private const MAX_DURATION = 90;

  /**
   * The provider selected for this request, or null before configure().
   */
  private ?ChatProviderInterface $_provider = null;

  /**
   * The prompt being assembled for this request.
   */
  private ?ChatbotMessage $_chatbotMessage = null;

  /**
   * Per-request settings, kept so getPayload() can hand them to the provider.
   *
   * Only EmpathicaAgent reads the levels; see ChatRequest for why they travel
   * explicitly rather than being read back out of the Security service.
   */
  private string $_persona = Interfaces\Services\ChatbotManagerInterface::PERSONA_EMILY;

  private int $_literacyLevel = 2;

  private int $_empathyLevel = 2;

  /**
   * Source of the system-prompt text, handed down to ChatbotMessage.
   *
   * Also the thing to ask for a prompt version when stamping stored
   * exchanges: $this->_prompts->getVersion().
   */
  private PromptRepository $_prompts;

  /**
   * PSR-3 logger for the chatbot REST layer.
   *
   * Wired to the dedicated "chatbot" Monolog channel in services.yaml so every
   * outbound provider call lands in its own stream (see monolog.yaml).
   * Defaults to a NullLogger so the service is still constructable without DI
   * — a quick script or a unit test — without null-checks at every call.
   */
  private LoggerInterface $_logger;

  /**
   * @param ProviderRegistry $_providers
   *   All registered providers, resolved by the name in ChatbotPreferences.provider.
   * @param HttpClientInterface $_httpClient
   *   symfony/http-client, replacing the `new Client()` that used to be
   *   constructed inline on every call. Injecting it is what makes this class
   *   testable without a network: a MockHttpClient satisfies the same contract.
   */
  public function __construct(
    private readonly ProviderRegistry $_providers,
    private readonly HttpClientInterface $_httpClient,
    ?LoggerInterface $logger = null,
    ?PromptRepository $prompts = null,
  ) {
    // Passed on to each ChatbotMessage so the whole request shares one
    // repository (and one memoised prompt version). Defaults to a
    // self-locating instance for CLI/test use — see PromptRepository.
    $this->_prompts = $prompts ?? new PromptRepository();
    $this->_logger = $logger ?? new NullLogger();
  }

  /**
   * {@inheritdoc}
   */
  public function configure(
    string $chatbot,
    ?string $persona = null,
    int $literacyLevel = 2,
    int $empathyLevel = 2,
    ?Entity\ChatbotPreferences $preferences = null
  ): static {
    // getOrDefault() rather than get(): a stale ChatbotPreferences.provider row naming a
    // provider that no longer exists should degrade to a working chat, not
    // turn the whole page into a 500 from four layers down. Same reasoning as
    // ChatbotMessage::setPersona().
    $this->_provider = $this->_providers->getOrDefault($chatbot);
    $this->_persona = $persona ?? Interfaces\Services\ChatbotManagerInterface::PERSONA_EMILY;
    $this->_literacyLevel = $literacyLevel;
    $this->_empathyLevel = $empathyLevel;

    $this->_chatbotMessage = new ChatbotMessage(
      $this->_provider->getName(),
      $this->_persona,
      $literacyLevel,
      $empathyLevel,
      $preferences,
      $this->_prompts
    );

    // ChatbotMessage validates the persona and may substitute the default, so
    // read it back rather than trusting what was passed in — otherwise the
    // prompt and the payload could name different people.
    $this->_persona = $this->_chatbotMessage->getPersona();

    return $this;
  }

  /**
   * The selected provider.
   *
   * @throws \LogicException
   *   If configure() has not been called. This is a programming error rather
   *   than a runtime condition — every code path reaches configure() first —
   *   so it is worth failing loudly instead of returning a null that would
   *   surface later as a much less obvious error.
   */
  private function provider(): ChatProviderInterface {
    if ($this->_provider === null) {
      throw new \LogicException('ChatbotManager::configure() must be called before use.');
    }

    return $this->_provider;
  }

  /**
   * {@inheritdoc}
   */
  public function getApiEndpoint(): string {
    return $this->provider()->getEndpoint();
  }

  /**
   * {@inheritdoc}
   */
  public function getChatbot(): string {
    return $this->_provider?->getName() ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function getChatbotMessage(): ChatbotMessage {
    if ($this->_chatbotMessage === null) {
      throw new \LogicException('ChatbotManager::configure() must be called before use.');
    }

    return $this->_chatbotMessage;
  }

  /**
   * {@inheritdoc}
   */
  public function getModel(): ?string {
    return $this->provider()->getModel();
  }

  /**
   * The neutral request handed to the provider.
   *
   * ChatbotMessage keeps the system instruction out of the conversation (see
   * its $_system docblock); this is where the two halves are packaged up for a
   * provider to reassemble in whatever shape its API wants.
   */
  private function toRequest(): ChatRequest {
    return new ChatRequest(
      $this->getChatbotMessage()->getSystemInstruction(),
      $this->getChatbotMessage()->getThread(),
      $this->_persona,
      $this->_literacyLevel,
      $this->_empathyLevel,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getPayload(?array $extra = []): array {
    return array_merge($this->provider()->buildPayload($this->toRequest()), $extra ?? []);
  }

  /**
   * Send the exchange and return the answer, normalised.
   *
   * This is what callers should use. getResponse() below returns the provider's
   * raw body and is kept for debugging and for the interface contract, but
   * every provider names its fields differently and picking through them at the
   * call site is what produced the parallel ladder in AppController.
   *
   * @throws ProviderRequestException
   *   On an HTTP error status from the provider.
   * @throws TransportExceptionInterface
   *   On a network-level failure.
   */
  public function getReply(): ChatReply {
    $response = $this->getResponse();

    if ($response === null) {
      return ChatReply::none();
    }

    return $this->provider()->parseReply($response);
  }

  /**
   * {@inheritdoc}
   *
   * Returns the decoded body, or null if the provider sent something that is
   * not a JSON object. We deliberately do NOT return an error message as a
   * string: the caller would have no clean way to tell a valid response from
   * an error one, and provider error text can carry request URLs, headers or
   * upstream bodies that must never reach a user.
   */
  public function getResponse(): ?array {
    $provider = $this->provider();

    // Built before the request so a configuration failure (a missing API key)
    // raises here, named, rather than as a 401 from upstream.
    $endpoint = $provider->getEndpoint();
    $headers = $provider->getHeaders();
    $payload = $this->getPayload();

    // What we log about the *request*. Deliberately omitted, because this log
    // stream is not a place for secrets or PII:
    //   - headers: they hold x-api-key / Bearer tokens / the OIDC id_token.
    //   - any query string: sanitizeEndpointForLog() strips it, as a second
    //     line of defence against a key finding its way back into a URL.
    //   - the payload body: it is the simulated clinical conversation. Its
    //     size in bytes is logged instead, as a cheap proxy for "how much did
    //     we send" that persists nothing anyone said.
    $context = [
      'provider' => $provider->getName(),
      'model' => $provider->getModel(),
      'endpoint' => self::sanitizeEndpointForLog($endpoint),
      'message_count' => count($this->getChatbotMessage()->getThread()),
      'payload_bytes' => strlen((string) json_encode($payload)),
    ];
    $this->_logger->info('Chatbot REST request', $context);

    // The full payload at debug level. Disabled by default; turning it on is a
    // deliberate act, and should only be done in a safe environment, because
    // this writes the conversation itself to disk.
    $this->_logger->debug('Chatbot REST request payload', ['payload' => $payload] + $context);

    // Wall clock, which is what matters for an external HTTP call.
    $start = microtime(true);

    try {
      $response = $this->_httpClient->request('POST', $endpoint, [
        'headers' => $headers,
        'json' => $payload,
        'timeout' => self::IDLE_TIMEOUT,
        'max_duration' => self::MAX_DURATION,
      ]);

      // Unlike Guzzle, symfony/http-client does not throw on a 4xx/5xx unless
      // you ask for the content without suppressing it. getStatusCode() and
      // getContent(false) both complete the exchange without raising on
      // status, which is what we want: the upstream error body is usually the
      // only explanation of *why* a request failed, and we would rather log it
      // than have it replaced by an exception. So the only thing this catch
      // sees is a genuine transport failure — DNS, TLS, timeout.
      $status = $response->getStatusCode();
      $body = $response->getContent(false);
    }
    catch (TransportExceptionInterface $e) {
      $this->_logger->error('Chatbot REST request failed', $context + [
        'duration_ms' => (int) round((microtime(true) - $start) * 1000),
        'error' => $e->getMessage(),
      ]);
      // Rethrown, not swallowed: ChatController's catch is what owns the
      // user-facing generic error and the flash message, and returning null
      // here would rob it of the distinction between "provider unreachable"
      // and "provider answered with nothing".
      throw $e;
    }

    $elapsedMs = (int) round((microtime(true) - $start) * 1000);

    if ($status >= 400) {
      // Provider error bodies explain the failure — bad key, rate limit,
      // malformed payload — but can be large, so they are capped.
      $this->_logger->error('Chatbot REST request failed', $context + [
        'duration_ms' => $elapsedMs,
        'status' => $status,
        'body' => substr($body, 0, 2000),
      ]);
      throw new ProviderRequestException($provider->getName(), $status);
    }

    $data = json_decode($body, true);

    $this->_logger->info('Chatbot REST response', $context + [
      'duration_ms' => $elapsedMs,
      'status' => $status,
      'response_bytes' => strlen($body),
      // Token usage lives under a different key per provider; surface
      // whichever is present. Metadata only — no message text.
      'usage' => $data['usage'] ?? $data['usageMetadata'] ?? null,
    ]);

    $this->_logger->debug('Chatbot REST response body', ['response_body' => $data] + $context);

    return is_array($data) ? $data : null;
  }

  /**
   * {@inheritdoc}
   */
  public function setChatbot(string $chatbot): void {
    $this->_provider = $this->_providers->getOrDefault($chatbot);
  }

  /**
   * Strip the query string from an endpoint before it is logged.
   *
   * No provider should be putting credentials in a URL — GeminiProvider
   * documents at length why its key goes in a header instead — but this is the
   * kind of mistake that is cheap to prevent and expensive to discover, since
   * the evidence would be a live key sitting in a log file. Scheme, host and
   * path are all that debugging needs, so the query goes entirely rather than
   * being selectively redacted.
   */
  private static function sanitizeEndpointForLog(string $endpoint): string {
    $parts = parse_url($endpoint);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
      return '[unparseable endpoint]';
    }

    return $parts['scheme'] . '://' . $parts['host'] . ($parts['path'] ?? '');
  }
}
