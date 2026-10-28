<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Chatbot\Provider;

use Pixiekat\EmpathicaChatbot\Chatbot\ChatReply;
use Pixiekat\EmpathicaChatbot\Chatbot\ChatRequest;
use Pixiekat\EmpathicaChatbot\Interfaces\Services\ChatProviderInterface;
use Pixiekat\EmpathicaChatbot\Interfaces\Services\ChatbotMessageInterface;
use Google\Auth\ApplicationDefaultCredentials;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * EmpathicaAgent — DCI's own in-house agent, running on Google Cloud Run.
 *
 * This is not a commercial LLM API and does not pretend to be one. It exposes
 * a single /chat endpoint with a bespoke, flat contract: one string containing
 * the whole exchange, plus literacy and empathy as its own fields because it
 * applies those itself server-side rather than taking them as prompt text.
 * Everything about it is different from the other four providers, which is
 * exactly why it benefits most from having a class of its own.
 *
 * It implements ChatProviderInterface directly rather than extending
 * AbstractChatProvider: there is no API key here at all. Authentication is a
 * Google-signed OIDC identity token, and there is no fixed URL either — the
 * service URI is discovered from the Cloud Run Admin API at runtime.
 *
 * STATUS: experimental. Treat its results as provisional, and see the lossy
 * empathy mapping documented on toAgentEmpathyLevel().
 */
final class EmpathicaProvider implements ChatProviderInterface {

  /**
   * Provider key as stored in ChatbotPreferences.provider.
   */
  public const NAME = 'EmpathicaAgent';

  /**
   * Cache key for the discovered Cloud Run service URI.
   *
   * Versioned: bump the suffix if what gets cached changes shape (e.g. if we
   * start storing scheme+host+path instead of the bare base URI), so a stale
   * entry from the previous contract cannot be read back as if it were valid.
   */
  private const DISCOVERY_CACHE_KEY = 'chatbot.empathica.service_uri.v1';

  /**
   * How long a discovered URI is trusted, in seconds.
   *
   * Cloud Run URIs only change if a service is deleted and recreated, which is
   * rare and operator-initiated — so this could be far longer. One hour is a
   * deliberate compromise: it caps the worst-case staleness after such a
   * recreate without an operator having to know to run cache:pool:clear to
   * recover.
   */
  private const DISCOVERY_TTL = 3600;

  /**
   * Memoises the discovered service URI. Not promoted in the constructor
   * because it needs a fallback when null is passed.
   */
  private readonly CacheItemPoolInterface $cache;

  /**
   * The dedicated "chatbot" Monolog channel, so discovery lands in the same
   * log stream as the chat calls it enables. Same reason as $cache for not
   * being promoted.
   */
  private readonly LoggerInterface $logger;

  /**
   * @param array<string, string> $config
   *   GCP coordinates: project, region, service_name. From the
   *   empathica_chatbot.providers.empathica configuration.
   * @param CacheItemPoolInterface|null $cache
   *   Memoises the discovered URI. Defaults to an in-memory adapter so the
   *   class is constructable without the container; in a real request the
   *   container injects cache.app, so the lookup is shared across PHP-FPM
   *   workers rather than repeated per process.
   */
  public function __construct(
    private readonly HttpClientInterface $httpClient,
    private readonly array $config = [],
    ?CacheItemPoolInterface $cache = null,
    ?LoggerInterface $logger = null,
  ) {
    $this->cache = $cache ?? new ArrayAdapter();
    $this->logger = $logger ?? new NullLogger();
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
   * Null: the agent picks its own model server-side, so there is nothing for
   * this app to name. Stored ConversationMessage rows therefore have a
   * provider but no model id, which is accurate rather than missing data.
   */
  public function getModel(): ?string {
    return null;
  }

  /**
   * {@inheritdoc}
   *
   * NOTE: unlike every other provider, this performs I/O — the URI is
   * discovered from the Cloud Run Admin API (and then cached for an hour).
   * Callers should expect it to be able to throw and to take a round-trip on a
   * cold cache.
   */
  public function getEndpoint(): string {
    return rtrim($this->discoverServiceUri(), '/') . '/chat';
  }

  /**
   * {@inheritdoc}
   *
   * Private Cloud Run services authenticate with a Google-signed OIDC identity
   * token, NOT an API key. The token's audience claim must match the service's
   * base URL — scheme and host, no path — which is why the audience is derived
   * from the discovered endpoint rather than configured separately.
   *
   * ApplicationDefaultCredentials looks for credentials in order:
   *   1. the GOOGLE_APPLICATION_CREDENTIALS env var (path to a JSON key)
   *   2. gcloud user credentials in ~/.config/gcloud (development only)
   *   3. the GCE/Cloud Run metadata server, when running on GCP
   *
   * On a non-GCP host, point GOOGLE_APPLICATION_CREDENTIALS at a service
   * account key whose account holds roles/run.invoker on the target service.
   */
  public function getHeaders(): array {
    $idTokenCreds = ApplicationDefaultCredentials::getIdTokenCredentials($this->getAudience());
    $token = $idTokenCreds->fetchAuthToken();

    if (empty($token['id_token'])) {
      throw new \RuntimeException(
        'Could not obtain a Google ID token for the Empathica service. Check '
        . 'GOOGLE_APPLICATION_CREDENTIALS and that the service account has '
        . 'roles/run.invoker.'
      );
    }

    return [
      'Authorization' => 'Bearer ' . $token['id_token'],
      'Content-Type' => 'application/json',
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The agent takes the whole exchange as one flat, role-prefixed string
   * rather than a structured message list. That is reproduced here byte for
   * byte as it was before system content was split out of the thread, so
   * transcripts recorded either side of that change remain comparable.
   */
  public function buildPayload(ChatRequest $request): array {
    $message = '';

    if ($request->hasSystemInstruction()) {
      $message .= ChatbotMessageInterface::SYSTEM . ': ' . $request->systemInstruction . "\n";
    }
    foreach ($request->thread as $turn) {
      $message .= $turn['role'] . ': ' . $turn['content'] . "\n";
    }

    return [
      'message' => $message,
      // Not wired up yet. The agent uses this to scope which drug information
      // it may draw on; an empty list means "no restriction".
      'drug_set_ids' => [],
      'user_id' => $this->toAgentUserId($request->persona),
      'literacy_level' => $this->toAgentLiteracyLevel($request->literacyLevel),
      'empathy_level' => $this->toAgentEmpathyLevel($request->empathyLevel),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * One field, no usage metadata. The agent does not report token counts, so
   * those stay null on the stored turn rather than being recorded as zero —
   * see ChatReply for why that distinction is kept.
   */
  public function parseReply(array $response): ChatReply {
    $answer = $response['answer'] ?? null;

    return new ChatReply(is_string($answer) ? $answer : null);
  }

  /**
   * The Cloud Run service base URL, used as the OIDC token audience.
   */
  private function getAudience(): string {
    $endpoint = $this->getEndpoint();
    $parts = parse_url($endpoint);

    if (!isset($parts['scheme'], $parts['host'])) {
      throw new \RuntimeException("Cannot derive Cloud Run audience from endpoint: {$endpoint}");
    }

    return $parts['scheme'] . '://' . $parts['host'];
  }

  /**
   * Ask Cloud Run where the service lives, and remember the answer.
   *
   * This is the PHP equivalent of:
   *   gcloud run services describe empathica-agents \
   *     --region us-east4 --format='value(status.url)'
   *
   * It calls the Admin REST API rather than shelling out to gcloud so the
   * production host does not need the CLI installed — only a service account
   * key (via GOOGLE_APPLICATION_CREDENTIALS) with at minimum roles/run.viewer
   * on the project.
   *
   * @throws \RuntimeException
   *   If the GCP coordinates are unset, if ADC cannot produce a token, if the
   *   Admin API fails, or if it returns no `uri` field.
   */
  private function discoverServiceUri(): string {
    $cacheItem = $this->cache->getItem(self::DISCOVERY_CACHE_KEY);
    if ($cacheItem->isHit()) {
      return (string) $cacheItem->get();
    }

    $project = $this->config['project'] ?? '';
    $region = $this->config['region'] ?? '';
    $service = $this->config['service_name'] ?? '';
    if ($project === '' || $region === '' || $service === '') {
      throw new \RuntimeException(
        'Empathica service discovery requires GCP_PROJECT, GCP_REGION, and '
        . 'EMPATHICA_SERVICE_NAME to be set in .env.local '
        . '(see empathica_chatbot.providers.empathica in config/packages/empathica_chatbot.yaml).'
      );
    }

    // An ADC *access* token, not an ID token — the Admin API is an ordinary
    // Google API, unlike the service itself.
    //
    // Scope note: Cloud Run v2 rejects cloud-platform.read-only with
    // ACCESS_TOKEN_SCOPE_INSUFFICIENT. Some Google APIs honour the read-only
    // variant; Run does not, so the full cloud-platform scope is required.
    // That widens the OAuth-layer surface, but the real access control here is
    // IAM: the service account behind these credentials should hold only
    // roles/run.viewer and roles/run.invoker, and that is what actually caps
    // what this token can do.
    $creds = ApplicationDefaultCredentials::getCredentials('https://www.googleapis.com/auth/cloud-platform');
    $token = $creds->fetchAuthToken();
    if (empty($token['access_token'])) {
      throw new \RuntimeException(
        'Could not obtain a Google access token via ApplicationDefaultCredentials.'
      );
    }

    // Cloud Run Admin v2 — services.get. v2 is the current API surface; v1 is
    // feature-frozen and the docs steer new integrations to v2.
    // https://cloud.google.com/run/docs/reference/rest/v2/projects.locations.services/get
    $adminUrl = sprintf(
      'https://run.googleapis.com/v2/projects/%s/locations/%s/services/%s',
      rawurlencode($project),
      rawurlencode($region),
      rawurlencode($service)
    );

    $start = microtime(true);

    try {
      $response = $this->httpClient->request('GET', $adminUrl, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token['access_token'],
          'Accept' => 'application/json',
        ],
        'timeout' => 10,
        'max_duration' => 15,
      ]);

      // toArray(false) does not throw on a 4xx/5xx, so the error body is
      // available to log below rather than being replaced by an exception.
      $status = $response->getStatusCode();
      $data = $response->toArray(false);
    }
    catch (HttpClientExceptionInterface $e) {
      // Transport-level failure (DNS, TLS, timeout) or an unparseable body.
      // The URL is safe to log — it holds only the GCP coordinates. The bearer
      // token is not, and is not included.
      $this->logger->error('Empathica service discovery failed', [
        'admin_url' => $adminUrl,
        'error' => $e->getMessage(),
      ]);
      throw $e;
    }

    if ($status >= 400) {
      $this->logger->error('Empathica service discovery failed', [
        'admin_url' => $adminUrl,
        'status' => $status,
        'body' => substr((string) json_encode($data), 0, 1000),
      ]);
      throw new \RuntimeException(
        "Cloud Run Admin API returned HTTP {$status} for service {$service} in {$region}."
      );
    }

    $uri = $data['uri'] ?? null;
    if (!is_string($uri) || $uri === '') {
      throw new \RuntimeException(
        "Cloud Run Admin API returned no `uri` for service {$service} in {$region}."
      );
    }

    $cacheItem->set($uri);
    $cacheItem->expiresAfter(self::DISCOVERY_TTL);
    $this->cache->save($cacheItem);

    $this->logger->info('Empathica service discovered', [
      'service' => $service,
      'region' => $region,
      'uri' => $uri,
      'duration_ms' => (int) round((microtime(true) - $start) * 1000),
    ]);

    return $uri;
  }

  /**
   * Map our persona onto the agent's own user identifier.
   *
   * A placeholder mapping that has outlived its "adjust as needed" comment:
   * the agent identifies simulated patients by id, and these two are what it
   * currently knows about.
   */
  private function toAgentUserId(string $persona): string {
    return match ($persona) {
      'Mary' => '000-002',
      default => '000-001',
    };
  }

  /**
   * Our 1-4 literacy integers to the agent's string levels.
   *
   * See PreferencesType::buildForm() for what each integer means to a user.
   */
  private function toAgentLiteracyLevel(int $level): string {
    return match ($level) {
      1 => 'low',
      2 => 'medium',
      3 => 'high',
      4 => 'very_high',
      default => 'medium',
    };
  }

  /**
   * Our 1-5 empathy integers to the agent's string levels.
   *
   * LOSSY — worth knowing before reading any EmpathicaAgent results. The app
   * models five empathy conditions but the agent accepts only three, so levels
   * 1 (low), 4 (anti-empathy) and 5 (confrontational) all arrive as "low".
   * Those are three quite different experimental arms and the agent cannot
   * currently tell them apart: a transcript recorded under level 4 or 5 is
   * really a level 1 transcript.
   *
   * The fix belongs on the agent side — it needs distinct values — so this
   * collapse is deliberate and documented rather than papered over here.
   */
  private function toAgentEmpathyLevel(int $level): string {
    return match ($level) {
      1, 4, 5 => 'low',
      2 => 'medium',
      3 => 'high',
      default => 'medium',
    };
  }
}
