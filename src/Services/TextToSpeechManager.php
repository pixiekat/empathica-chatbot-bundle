<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Turns a chatbot response into spoken audio using Gemini's TTS models.
 *
 * WHY A SERVER-SIDE SERVICE
 *
 * The browser must never see the Gemini API key, so synthesis cannot happen
 * in JavaScript — the request is proxied through TextToSpeechController.
 * That controller also decides *what* may be synthesised: it only ever passes
 * text the requesting user already has in their own session, so this class is
 * never handed arbitrary caller-supplied strings.
 *
 * WHAT COMES BACK FROM GOOGLE
 *
 * Gemini returns **raw PCM**, not a playable file: 24kHz, 16-bit, mono,
 * base64-encoded at candidates[0].content.parts[0].inlineData.data. Browsers
 * cannot play headerless PCM, so toWav() prepends a 44-byte RIFF header. That
 * is the entire conversion — no re-encoding, no audio library.
 *
 * COST AND SIZE
 *
 * PCM at 24kHz/16-bit/mono is 48,000 bytes per second, so a two-minute answer
 * is roughly 5.5MB. Two consequences, both handled here:
 *
 *   - MAX_CHARACTERS caps how much text is sent, because TTS is billed per
 *     character and Google's own docs note quality drifts on outputs longer
 *     than a few minutes.
 *   - Results are cached by content hash, so replaying an answer (the whole
 *     point of read-aloud for the users this app targets) costs nothing after
 *     the first play.
 *
 * The model is a *preview* model. Expect the name to change; it is
 * configurable via GEMINI_TTS_MODEL for exactly that reason.
 */
class TextToSpeechManager {

  /**
   * Gemini TTS output format, fixed by the API.
   */
  private const SAMPLE_RATE = 24000;
  private const BITS_PER_SAMPLE = 16;
  private const CHANNELS = 1;

  /**
   * Longest text we will synthesise in one request.
   *
   * Chat responses are capped at 1250 tokens, so this comfortably covers a
   * full answer while bounding the cost of any single call. Longer text is
   * truncated at a sentence boundary rather than mid-word.
   */
  private const MAX_CHARACTERS = 5000;

  /**
   * Longest text sent in a single request to Gemini.
   *
   * This is a *latency* limit, not a cost or API one. Synthesis is not
   * streaming — the whole clip comes back in one response — so request time
   * scales with how much audio is being produced. Roughly 900 characters is
   * something like 45 seconds of speech, which finishes well inside the 60s
   * timeout with room for a slow day at Google's end.
   */
  private const CHUNK_CHARACTERS = 900;

  /**
   * How long to keep synthesised audio, in seconds.
   *
   * Long enough that re-reading a conversation over a session or two is free,
   * short enough that the cache does not grow without bound. Entries are
   * multi-megabyte, so this is a real storage consideration rather than a
   * nominal one.
   */
  private const CACHE_TTL = 604800;

  private LoggerInterface $_logger;
  private CacheItemPoolInterface $_cache;

  /**
   * Used to build the Referer header Google's API key restrictions expect.
   * See requestAudio(). Null in CLI/test contexts.
   */
  private ?RequestStack $_requestStack = null;

  /**
   * Source of the read-aloud directive. See withReadInstruction().
   */
  private PromptRepository $_prompts;

  /**
   * @param string $apiKey
   *   The Gemini API key. Shared with the chat provider — TTS uses the same
   *   credential (see config/services.yaml).
   * @param bool $enabled
   *   Master switch. When false, synthesize() always returns null and the
   *   front end silently falls back to the browser's own speech synthesiser.
   * @param string $model
   *   Gemini TTS model id.
   * @param string $voice
   *   One of Gemini's 30 prebuilt voice names.
   */
  public function __construct(
    private readonly string $apiKey = '',
    private readonly bool $enabled = false,
    private readonly string $model = 'gemini-3.1-flash-tts-preview',
    private readonly string $voice = 'Kore',
    ?LoggerInterface $logger = null,
    ?CacheItemPoolInterface $cache = null,
    ?RequestStack $requestStack = null,
    ?PromptRepository $prompts = null,
  ) {
    $this->_logger = $logger ?? new NullLogger();
    $this->_cache = $cache ?? new ArrayAdapter();
    $this->_requestStack = $requestStack;
    $this->_prompts = $prompts ?? new PromptRepository();
  }

  /**
   * Is Gemini TTS configured and switched on?
   *
   * The controller checks this before doing any work, so a misconfigured
   * install degrades to the browser voice instead of erroring on every click.
   */
  public function isEnabled(): bool {
    return $this->enabled && $this->apiKey !== '';
  }

  /**
   * Split a message into the ordered list of pieces that will be spoken.
   *
   * Callers synthesise these one at a time so playback can start on the first
   * piece while the rest are still being generated — Gemini's TTS endpoint is
   * not streaming, so a whole long answer in one request means silence until
   * every second of audio exists. Splitting is deterministic, so part index N
   * always refers to the same text across requests.
   *
   * @return string[]
   */
  public function split(string $text): array {
    $text = $this->prepare($text);

    return $text === '' ? [] : $this->chunk($text);
  }

  /**
   * Synthesise one piece to WAV bytes, or null if TTS is unavailable.
   *
   * Returning null rather than throwing is deliberate: read-aloud is an
   * enhancement, and the caller's correct response to any failure is the same
   * — fall back to the browser voice. Failures are logged with enough detail
   * to diagnose, without the text itself (it is clinical conversation
   * content, and this log stream is not the place for it).
   */
  public function synthesizeChunk(string $chunk): ?string {
    if (!$this->isEnabled() || trim($chunk) === '') {
      return null;
    }

    // Cache key covers everything that changes the audio — including the
    // instruction template, so editing config/prompts/tts/instruction.md (to
    // fix a pronunciation, say) invalidates previously synthesised audio
    // instead of quietly serving the old rendering for another seven days.
    // Bump the version suffix if the WAV wrapping or request shape changes.
    $cacheKey = 'tts.v2.' . hash('sha256', implode('|', [
      $this->model,
      $this->voice,
      $this->_prompts->get('tts/instruction'),
      $chunk,
    ]));
    $item = $this->_cache->getItem($cacheKey);
    if ($item->isHit()) {
      return $item->get();
    }

    $pcm = $this->requestAudio($chunk);
    if ($pcm === null) {
      return null;
    }

    $wav = $this->toWav($pcm);

    $item->set($wav);
    $item->expiresAfter(self::CACHE_TTL);
    $this->_cache->save($item);

    return $wav;
  }

  /**
   * Wrap a chunk in an explicit "read this out" directive.
   *
   * Gemini's TTS models accept a *style instruction* ahead of the content,
   * and the documented example is literally "Say cheerfully: Have a wonderful
   * day!" — everything before the colon is consumed as direction and is not
   * spoken. That is convenient when you want it and dangerous when you do
   * not: given a chunk beginning "Important Safety Alert: Combining
   * Ondansetron and Sumatriptan...", the model can treat the label as
   * direction and simply never say it. We observed exactly this behaviour on
   * a response whose leading "Empathy Level: High | Literacy Level: Basic"
   * line was silently skipped.
   *
   * Losing a leading phrase is unacceptable when the phrase might be a safety
   * warning, and chunking multiplies the risk because every chunk is a fresh
   * request with its own leading text. Supplying our own instruction fills
   * that slot, so whatever the chunk starts with is content rather than a
   * candidate directive.
   *
   * The wording lives in config/prompts/tts/instruction.md rather than here,
   * for the same reason the chat prompts do: it is prompt text, it will be
   * tuned by whoever is tuning the voice, and that should not require a code
   * change. It is also where pronunciation guidance belongs — note that the
   * chat system prompt never reaches this model, so telling the *chat* model
   * how to pronounce a word does not affect how the *speech* model says it.
   */
  private function withReadInstruction(string $chunk): string {
    return $this->_prompts->render('tts/instruction', ['text' => $chunk]);
  }

  /**
   * Normalise and bound the text before spending money on it.
   *
   * Collapsing whitespace matters because the rendered response is HTML that
   * has been flattened back to text, which leaves ragged runs of spaces and
   * newlines that the model would otherwise pause on.
   */
  private function prepare(string $text): string {
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

    if (mb_strlen($text) <= self::MAX_CHARACTERS) {
      return $text;
    }

    // Truncate at the last sentence end inside the limit, so playback stops
    // somewhere natural rather than mid-word.
    $clipped = mb_substr($text, 0, self::MAX_CHARACTERS);
    $lastStop = max(
      (int) mb_strrpos($clipped, '. '),
      (int) mb_strrpos($clipped, '! '),
      (int) mb_strrpos($clipped, '? ')
    );

    return $lastStop > 0 ? mb_substr($clipped, 0, $lastStop + 1) : $clipped;
  }

  /**
   * Split text into request-sized pieces, breaking at sentence ends.
   *
   * Breaking on sentences rather than a hard character count matters audibly:
   * each chunk is synthesised independently, so the model applies its own
   * intonation and a trailing fall to whatever it is given. Cut mid-sentence
   * and you hear the seam. Cut after a full stop and the join lands where a
   * speaker would have paused anyway.
   *
   * A single sentence longer than the limit is emitted whole rather than
   * severed — better one slow request than an audible splice mid-clause.
   *
   * Public only so it can be tested directly — it is a pure function and the
   * splitting rules are worth pinning, same reasoning as toWav().
   *
   * @return string[]
   */
  public function chunk(string $text): array {
    if (mb_strlen($text) <= self::CHUNK_CHARACTERS) {
      return [$text];
    }

    // Split after ., ! or ? followed by whitespace, keeping the punctuation
    // attached to the sentence it ends.
    // PREG_SPLIT_NO_EMPTY means no empty pieces come back, and the early
    // return above guarantees $text is longer than the chunk limit, so the
    // result is always a non-empty list of non-empty strings. false is still
    // possible if the pattern itself fails.
    $sentences = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if ($sentences === false) {
      return [$text];
    }

    $chunks = [];
    $current = '';
    foreach ($sentences as $sentence) {
      $candidate = $current === '' ? $sentence : $current . ' ' . $sentence;

      if (mb_strlen($candidate) > self::CHUNK_CHARACTERS && $current !== '') {
        $chunks[] = $current;
        $current = $sentence;
        continue;
      }

      $current = $candidate;
    }

    // $sentences is guaranteed non-empty (see above), so the loop always ran
    // at least once and $current always holds a trailing chunk.
    $chunks[] = $current;

    return $chunks;
  }

  /**
   * Call Gemini and return raw PCM bytes, or null on any failure.
   */
  private function requestAudio(string $text): ?string {
    $endpoint = sprintf(
      'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
      $this->model
    );

    // Shape per the Gemini speech-generation docs. Note the language is not
    // specified: the TTS models detect it from the text, which is what makes
    // the Spanish-speaking Maria persona work without extra plumbing.
    $payload = [
      'contents' => [
        ['parts' => [['text' => $this->withReadInstruction($text)]]],
      ],
      'generationConfig' => [
        'responseModalities' => ['AUDIO'],
        'speechConfig' => [
          'voiceConfig' => [
            'prebuiltVoiceConfig' => ['voiceName' => $this->voice],
          ],
        ],
      ],
    ];

    $start = microtime(true);

    try {
      $response = (new Client())->request('POST', $endpoint, [
        // Key goes in the header, never the URL — a URL ends up in exception
        // messages and logs. Same rule as the chat path.
        'headers' => [
          'x-goog-api-key' => $this->apiKey,
          'Content-Type' => 'application/json',
          // Required because the Gemini key carries an HTTP-referrer
          // restriction in Google Cloud. Without this header Google sees an
          // empty referrer and rejects the call with 403
          // API_KEY_HTTP_REFERRER_BLOCKED — even though the same key works
          // for chat, which has always sent it. Same value and same reason as
          // ChatbotManager::getResponse(); resolved via Symfony's
          // trusted-host config rather than the raw Host header, which is
          // client-controlled.
          'Referer' => $this->_requestStack?->getCurrentRequest()?->getSchemeAndHttpHost() ?? 'localhost',
        ],
        'json' => $payload,
        'connect_timeout' => 5,
        // Synthesis is slower than chat completion for long text, and this
        // request blocks a PHP-FPM worker, so the ceiling is deliberate.
        'timeout' => 60,
      ]);
    }
    // TransferException, not RequestException. Guzzle throws ConnectException
    // for connect and read timeouts (cURL 28), and ConnectException extends
    // TransferException *directly* — it is not a RequestException. Catching
    // only the latter let a timeout escape this method, propagate out of the
    // controller, and render a 500 instead of the quiet fall-back to the
    // browser voice that every other failure here produces.
    catch (TransferException $e) {
      // getResponse() only exists on RequestException; a ConnectException has
      // no response at all, by definition.
      $upstream = ($e instanceof RequestException && $e->hasResponse()) ? $e->getResponse() : null;
      $this->_logger->error('Gemini TTS request failed', [
        'model' => $this->model,
        'voice' => $this->voice,
        'characters' => mb_strlen($text),
        'status' => $upstream?->getStatusCode(),
        'error' => $e->getMessage(),
        'body' => $upstream ? substr((string) $upstream->getBody(), 0, 1000) : null,
      ]);

      return null;
    }

    $data = json_decode((string) $response->getBody(), true);
    $encoded = $data['candidates'][0]['content']['parts'][0]['inlineData']['data'] ?? null;

    if (!is_string($encoded) || $encoded === '') {
      $this->_logger->error('Gemini TTS response contained no audio', [
        'model' => $this->model,
        // Structure only, never the payload: this is conversation content.
        'response_keys' => is_array($data) ? array_keys($data) : null,
      ]);

      return null;
    }

    $pcm = base64_decode($encoded, true);
    if ($pcm === false) {
      $this->_logger->error('Gemini TTS returned undecodable base64 audio', ['model' => $this->model]);

      return null;
    }

    $this->_logger->info('Gemini TTS synthesised', [
      'model' => $this->model,
      'voice' => $this->voice,
      'characters' => mb_strlen($text),
      'audio_bytes' => strlen($pcm),
      'duration_ms' => (int) round((microtime(true) - $start) * 1000),
    ]);

    return $pcm;
  }

  /**
   * Wrap raw PCM in a 44-byte RIFF/WAVE header so a browser can play it.
   *
   * Gemini hands back headerless PCM; <audio> needs a container. This writes
   * the canonical WAV header by hand rather than pulling in an audio library,
   * because the format is fixed and fully known:
   *
   *   "RIFF" <chunk size> "WAVE"
   *   "fmt " <16> <PCM=1> <channels> <sample rate> <byte rate> <align> <bits>
   *   "data" <data size> <samples...>
   *
   * pack() codes: V = 32-bit little-endian, v = 16-bit little-endian. WAV is
   * little-endian throughout, which is why none of these are the big-endian
   * variants.
   */
  public function toWav(string $pcm): string {
    $byteRate = self::SAMPLE_RATE * self::CHANNELS * (self::BITS_PER_SAMPLE / 8);
    $blockAlign = self::CHANNELS * (self::BITS_PER_SAMPLE / 8);
    $dataSize = strlen($pcm);

    return 'RIFF'
      // Size of everything after this field: 36 bytes of header + the data.
      . pack('V', 36 + $dataSize)
      . 'WAVE'
      . 'fmt '
      . pack('V', 16)                        // fmt chunk size, 16 for PCM
      . pack('v', 1)                         // audio format, 1 = uncompressed PCM
      . pack('v', self::CHANNELS)
      . pack('V', self::SAMPLE_RATE)
      . pack('V', (int) $byteRate)
      . pack('v', (int) $blockAlign)
      . pack('v', self::BITS_PER_SAMPLE)
      . 'data'
      . pack('V', $dataSize)
      . $pcm;
  }
}
