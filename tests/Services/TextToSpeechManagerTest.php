<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Tests\Services;

use Pixiekat\EmpathicaChatbot\Services\TextToSpeechManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Gemini text-to-speech wrapper.
 *
 * The network call itself is not exercised here — that needs a billable API
 * key. What is tested is everything around it that can silently go wrong:
 * the RIFF header (a wrong byte here gives silence or noise rather than an
 * error) and the enable/disable logic that decides whether the front end
 * falls back to the browser voice.
 */
final class TextToSpeechManagerTest extends TestCase {

  /**
   * Read a little-endian integer out of the generated header.
   */
  private function le(string $wav, int $offset, int $bytes): int {
    return unpack($bytes === 4 ? 'V' : 'v', substr($wav, $offset, $bytes))[1];
  }

  /**
   * The RIFF header must describe Gemini's actual output format: 24kHz,
   * 16-bit, mono, uncompressed PCM. Browsers do not validate this — a wrong
   * sample rate plays at the wrong speed, and a wrong size field truncates
   * playback, both without any error.
   */
  public function testWavHeaderDescribesGeminiAudioFormat(): void {
    // 100 samples of silence: 16-bit mono means 2 bytes per sample.
    $pcm = str_repeat("\x00\x00", 100);
    $wav = (new TextToSpeechManager())->toWav($pcm);

    $this->assertSame('RIFF', substr($wav, 0, 4));
    $this->assertSame('WAVE', substr($wav, 8, 4));
    $this->assertSame('fmt ', substr($wav, 12, 4));
    $this->assertSame('data', substr($wav, 36, 4));

    $this->assertSame(16, $this->le($wav, 16, 4), 'fmt chunk size should be 16 for PCM.');
    $this->assertSame(1, $this->le($wav, 20, 2), 'Audio format should be 1 (uncompressed PCM).');
    $this->assertSame(1, $this->le($wav, 22, 2), 'Gemini returns mono.');
    $this->assertSame(24000, $this->le($wav, 24, 4), 'Gemini returns 24kHz.');
    $this->assertSame(48000, $this->le($wav, 28, 4), 'Byte rate = 24000 * 1 * 2.');
    $this->assertSame(2, $this->le($wav, 32, 2), 'Block align = channels * bytes per sample.');
    $this->assertSame(16, $this->le($wav, 34, 2), 'Gemini returns 16-bit samples.');
  }

  /**
   * The two size fields must agree with the payload, and the payload must be
   * preserved byte for byte — this is a header prepend, not a re-encode.
   */
  public function testWavSizeFieldsAndPayloadAreCorrect(): void {
    $pcm = random_bytes(512);
    $wav = (new TextToSpeechManager())->toWav($pcm);

    $this->assertSame(44 + 512, strlen($wav), 'A canonical WAV header is 44 bytes.');
    $this->assertSame(36 + 512, $this->le($wav, 4, 4), 'RIFF size counts everything after itself.');
    $this->assertSame(512, $this->le($wav, 40, 4), 'data size should be the PCM length.');
    $this->assertSame($pcm, substr($wav, 44), 'PCM must survive untouched.');
  }

  /**
   * Short text is one request — no splitting, no seams.
   */
  public function testShortTextIsNotChunked(): void {
    $text = 'Take one tablet in the morning. Call your provider if you feel dizzy.';

    $this->assertSame([$text], (new TextToSpeechManager())->chunk($text));
  }

  /**
   * split() is what the controller drives playback from, so part numbering
   * must be stable: the same message always yields the same pieces in the
   * same order, or a part fetched later would play the wrong audio.
   */
  public function testSplitIsDeterministicAndNormalisesWhitespace(): void {
    $tts = new TextToSpeechManager();
    $text = "Take one tablet.\n\n   Call your provider   if you feel dizzy.";

    $first = $tts->split($text);
    $second = $tts->split($text);

    $this->assertSame($first, $second, 'Part numbering must be reproducible.');
    $this->assertSame(['Take one tablet. Call your provider if you feel dizzy.'], $first);
  }

  /**
   * Empty content yields no parts at all, so the controller answers 204 and
   * the browser voice takes over instead of an empty audio file playing.
   */
  public function testEmptyTextYieldsNoParts(): void {
    $this->assertSame([], (new TextToSpeechManager())->split('   '));
  }

  /**
   * Long text splits, and every piece must end on a sentence boundary.
   *
   * Each chunk is synthesised independently, so the model gives each one its
   * own closing intonation. Splitting mid-sentence is audible as a seam;
   * splitting after a full stop lands where a speaker would have paused.
   */
  public function testLongTextSplitsOnSentenceBoundaries(): void {
    $sentence = str_repeat('This is a sentence about your medication. ', 40);
    $chunks = (new TextToSpeechManager())->chunk(trim($sentence));

    $this->assertGreaterThan(1, count($chunks), 'Long text should be split.');

    foreach ($chunks as $chunk) {
      $this->assertMatchesRegularExpression('/[.!?]$/', $chunk, 'Chunk ended mid-sentence.');
    }

    // Nothing lost and nothing duplicated: rejoining reproduces the input.
    $this->assertSame(trim($sentence), implode(' ', $chunks));
  }

  /**
   * A single sentence longer than the chunk limit is emitted whole rather
   * than severed — one slow request beats an audible splice mid-clause.
   */
  public function testAnOverlongSentenceIsNotSevered(): void {
    $text = str_repeat('word ', 400) . 'end.';
    $chunks = (new TextToSpeechManager())->chunk($text);

    $this->assertCount(1, $chunks);
    $this->assertSame($text, $chunks[0]);
  }

  /**
   * Disabled means disabled: synthesize() returns null so the caller falls
   * back to the browser voice, and no request is attempted.
   */
  public function testDisabledByDefault(): void {
    $tts = new TextToSpeechManager();

    $this->assertFalse($tts->isEnabled());
    $this->assertNull($tts->synthesizeChunk('Take one tablet in the morning.'));
  }

  /**
   * Enabled but unconfigured is still disabled. Without this, an install that
   * flips the flag without setting a key would fail on every click instead of
   * quietly using the browser voice.
   */
  public function testEnabledWithoutAnApiKeyStaysDisabled(): void {
    $this->assertFalse((new TextToSpeechManager('', true))->isEnabled());
    $this->assertTrue((new TextToSpeechManager('a-key', true))->isEnabled());
  }
}
