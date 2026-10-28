<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Tests\Services;

use Pixiekat\EmpathicaChatbot\Services\TextToSpeechManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Boots the container to prove the TTS service is actually wired.
 *
 * This is the only test here that boots a kernel, and it earns it: the
 * service's arguments come from %env(bool:default::GEMINI_TTS_ENABLED)% and
 * friends, and env processors are resolved at instantiation — so a typo in
 * services.yaml produces a runtime failure on first click, not a config
 * error anyone would notice earlier.
 */
final class TextToSpeechWiringTest extends KernelTestCase {

  public function testServiceIsWiredAndOffUnlessExplicitlyEnabled(): void {
    self::bootKernel();
    $tts = self::getContainer()->get(TextToSpeechManager::class);

    $this->assertInstanceOf(TextToSpeechManager::class, $tts);
    // The env processors resolved without error, which is the real assertion.
    // Off by default so read-aloud uses the free browser voice until an
    // operator opts in — see .env.example.
    $this->assertFalse(
      $tts->isEnabled(),
      'Gemini TTS should be off unless GEMINI_TTS_ENABLED is set.'
    );
  }
}
