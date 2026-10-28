<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Tests\Chatbot;

use Pixiekat\EmpathicaChatbot\Chatbot\Provider\AnthropicProvider;
use Pixiekat\EmpathicaChatbot\Chatbot\Provider\GeminiProvider;
use Pixiekat\EmpathicaChatbot\Chatbot\Provider\OpenAiProvider;
use Pixiekat\EmpathicaChatbot\Chatbot\ProviderRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Tests for provider lookup.
 *
 * The registry is small, but it is the single point where a value out of the
 * database becomes a class that will be sent real money's worth of tokens. The
 * two things worth pinning are what it does with the historical 'Antropic'
 * typo, and what it does with a value it does not recognise at all — because
 * both come from stored user preferences rather than from code.
 */
final class ProviderRegistryTest extends TestCase {

  private function registry(): ProviderRegistry {
    return new ProviderRegistry([
      new AnthropicProvider(),
      new GeminiProvider(),
      new OpenAiProvider(),
    ]);
  }

  /**
   * Providers are indexed by their own name, not by anything declared
   * separately, so the key cannot drift away from the class.
   */
  public function testProvidersAreIndexedByTheirOwnName(): void {
    $registry = $this->registry();

    $this->assertSame(AnthropicProvider::NAME, $registry->get('Anthropic')->getName());
    $this->assertSame(GeminiProvider::NAME, $registry->get('Gemini')->getName());
    $this->assertSame(['Anthropic', 'Gemini', 'OpenAI'], $registry->names());
  }

  /**
   * 'Antropic' is a typo that reached production User.chatbot rows.
   *
   * It used to be checked for alongside the correct spelling in several
   * separate switch statements. Normalising in one place is only safe if it
   * genuinely happens on every path in, which is what this pins.
   */
  public function testTheHistoricalAntropicTypoResolvesToAnthropic(): void {
    $registry = $this->registry();

    $this->assertTrue($registry->has('Antropic'));
    $this->assertSame(AnthropicProvider::NAME, $registry->get('Antropic')->getName());
    $this->assertSame(AnthropicProvider::NAME, $registry->getOrDefault('Antropic')->getName());
  }

  /**
   * An unrecognised preference must not take the chat page down.
   *
   * This is reachable from real data: a provider removed from the codebase
   * leaves every user who had selected it pointing at nothing. They should get
   * a working chatbot, not a 500 — the same reasoning as
   * ChatbotMessage::setPersona().
   */
  public function testAnUnknownProviderFallsBackInsteadOfThrowing(): void {
    $this->assertSame(
      AnthropicProvider::NAME,
      $this->registry()->getOrDefault('AltaVistaGPT')->getName()
    );
  }

  /**
   * ...but the strict lookup still throws, and says what it does know about.
   *
   * getOrDefault() is for rendering a page; get() is for code that has just
   * been handed a name it believes in. Silently substituting there would hide
   * a genuine bug.
   */
  public function testStrictLookupThrowsAndNamesWhatIsRegistered(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Anthropic, Gemini, OpenAI');

    $this->registry()->get('AltaVistaGPT');
  }

  /**
   * If the configured fallback is itself missing, something still has to come
   * back — "no chatbot at all" is not a state this app can render.
   */
  public function testFallbackDegradesToWhateverIsRegistered(): void {
    $registry = new ProviderRegistry([new GeminiProvider()]);

    $this->assertSame(
      GeminiProvider::NAME,
      $registry->getOrDefault('AltaVistaGPT')->getName()
    );
  }
}
