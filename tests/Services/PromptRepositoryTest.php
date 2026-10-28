<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Tests\Services;

use Pixiekat\EmpathicaChatbot\Services\PromptRepository;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the prompt loader.
 *
 * Most of these use a throwaway directory rather than config/prompts/, so
 * they test the mechanism without pinning the wording of the real prompts —
 * which should be free to change without breaking the build.
 */
final class PromptRepositoryTest extends TestCase {

  private string $dir;

  protected function setUp(): void {
    $this->dir = sys_get_temp_dir() . '/prompt-test-' . bin2hex(random_bytes(6));
    mkdir($this->dir . '/empathy', 0775, true);
    mkdir($this->dir . '/tts', 0775, true);
    file_put_contents($this->dir . '/tts/instruction.md', 'Read this aloud: {{ text }}');
    file_put_contents($this->dir . '/base.md', 'BASE');
    file_put_contents($this->dir . '/empathy/1.md', 'ONE');
    file_put_contents($this->dir . '/empathy/default.md', 'DEFAULT');
    file_put_contents($this->dir . '/greeting.md', 'Hello {{ name }}, welcome.');
    file_put_contents($this->dir . '/VERSION', "test label\n");
  }

  protected function tearDown(): void {
    foreach (glob($this->dir . '/{*.md,*/*.md,VERSION}', GLOB_BRACE) as $f) { unlink($f); }
    @rmdir($this->dir . '/empathy');
    @rmdir($this->dir . '/tts');
    @rmdir($this->dir);
  }

  public function testReadsAPromptFile(): void {
    $this->assertSame('BASE', (new PromptRepository($this->dir))->get('base'));
  }

  /**
   * A missing prompt must be fatal, not an empty string. Silently dropping the
   * guardrails block would send an unguarded medical prompt to a live
   * provider — much worse than an error page.
   */
  public function testMissingPromptThrows(): void {
    $this->expectException(\RuntimeException::class);
    (new PromptRepository($this->dir))->get('does-not-exist');
  }

  public function testFallbackCoversOutOfRangeLevels(): void {
    $repo = new PromptRepository($this->dir);

    $this->assertSame('ONE', $repo->getWithFallback('empathy/1', 'empathy/default'));
    $this->assertSame('DEFAULT', $repo->getWithFallback('empathy/97', 'empathy/default'));
  }

  public function testRenderSubstitutesPlaceholders(): void {
    $this->assertSame(
      'Hello Emily, welcome.',
      (new PromptRepository($this->dir))->render('greeting', ['name' => 'Emily'])
    );
  }

  /**
   * A prompt name must not be able to escape the prompt directory.
   */
  public function testPathTraversalIsRejected(): void {
    $this->expectException(\RuntimeException::class);
    (new PromptRepository($this->dir))->get('../../composer');
  }

  /**
   * The version carries the human label from VERSION.
   */
  public function testVersionIncludesTheLabel(): void {
    $this->assertStringStartsWith('test label+', (new PromptRepository($this->dir))->getVersion());
  }

  /**
   * The whole point of the content hash: editing a prompt changes the version
   * even when nobody remembers to bump the label. Without this, a transcript
   * cannot be traced back to the prompt that produced it.
   */
  public function testVersionChangesWhenAPromptChanges(): void {
    $before = (new PromptRepository($this->dir))->getVersion();

    file_put_contents($this->dir . '/base.md', 'BASE, revised');
    $after = (new PromptRepository($this->dir))->getVersion();

    $this->assertNotSame($before, $after);
    // The label half is unchanged; only the fingerprint moved.
    $this->assertStringStartsWith('test label+', $after);
  }

  /**
   * A speech-prompt edit must not move the chat prompt version.
   *
   * This is the whole reason getVersion() is scoped. The version is stamped
   * onto every stored chat message as provenance, so bumping it for an
   * unrelated change makes two runs under identical chat conditions look like
   * different ones — a false positive in exactly the data the app exists to
   * collect.
   */
  public function testEditingASpeechPromptDoesNotChangeTheChatVersion(): void {
    $repo = new PromptRepository($this->dir);
    $chatBefore = $repo->getVersion();
    $ttsBefore = $repo->getVersion(PromptRepository::SCOPE_TTS);

    file_put_contents($this->dir . '/tts/instruction.md', 'Read this aloud, warmly: {{ text }}');
    $after = new PromptRepository($this->dir);

    $this->assertSame($chatBefore, $after->getVersion(), 'A speech edit moved the chat version.');
    $this->assertNotSame($ttsBefore, $after->getVersion(PromptRepository::SCOPE_TTS));
  }

  /**
   * ...and the converse: a chat edit must not move the speech version.
   */
  public function testEditingAChatPromptDoesNotChangeTheSpeechVersion(): void {
    $repo = new PromptRepository($this->dir);
    $chatBefore = $repo->getVersion();
    $ttsBefore = $repo->getVersion(PromptRepository::SCOPE_TTS);

    file_put_contents($this->dir . '/base.md', 'BASE, revised again');
    $after = new PromptRepository($this->dir);

    $this->assertNotSame($chatBefore, $after->getVersion());
    $this->assertSame($ttsBefore, $after->getVersion(PromptRepository::SCOPE_TTS), 'A chat edit moved the speech version.');
  }

  /**
   * Sanity check against the real directory: every block the thread builder
   * asks for must actually exist, or the chat page 500s on first load.
   */
  public function testRealPromptDirectoryHasEveryRequiredBlock(): void {
    $repo = new PromptRepository();

    $required = ['base', 'guardrails', 'announce-levels', 'user-memory',
                 'tts/instruction',
                 'persona/emily', 'persona/mary', 'empathy/default', 'literacy/default'];
    foreach ([1, 2, 3, 4, 5] as $level) { $required[] = "empathy/{$level}"; }
    foreach ([1, 2, 3, 4] as $level) { $required[] = "literacy/{$level}"; }

    foreach ($required as $name) {
      $this->assertNotSame('', trim($repo->get($name)), "Prompt {$name} is empty or missing.");
    }

    // The read-aloud directive is a template: without the placeholder the
    // message text would never be substituted in, and Gemini would be asked
    // to speak the instruction alone.
    $this->assertStringContainsString('{{ text }}', $repo->get('tts/instruction'));
  }
}
