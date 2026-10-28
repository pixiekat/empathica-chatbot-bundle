<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Tests\Chatbot;

use Pixiekat\EmpathicaChatbot\Chatbot\ProviderRegistry;
use Pixiekat\EmpathicaChatbot\Interfaces\Services\ChatbotManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Boots the container to prove every provider was actually discovered.
 *
 * Providers are collected by service tag, which is what makes adding one a
 * single-file job — and also what makes a missing one invisible. Nothing
 * references them by class name any more, so a provider that fails to
 * autowire, or whose tag stops matching because the _instanceof rule was
 * edited, simply is not there. The first sign would be a user selecting it in
 * their preferences and silently getting somebody else's chatbot.
 *
 * This is the same reasoning as TextToSpeechWiringTest: a kernel boot is
 * expensive, and worth it exactly where the failure mode is a runtime one that
 * no unit test can see.
 */
final class ProviderWiringTest extends KernelTestCase {

  public function testEveryProviderIsRegistered(): void {
    self::bootKernel();
    $registry = self::getContainer()->get(ProviderRegistry::class);

    $this->assertInstanceOf(ProviderRegistry::class, $registry);

    // Sorted so the assertion reports a readable diff rather than depending on
    // the order the container happened to collect the tag in.
    $names = $registry->names();
    sort($names);

    $this->assertSame(
      ['Anthropic', 'EmpathicaAgent', 'Gemini', 'Mistral', 'OpenAI'],
      $names
    );
  }

  /**
   * The names offered in the preferences form must all resolve.
   *
   * PreferencesType and UserFormType build their dropdowns from the
   * CHATBOT_MODEL_* constants and bare strings. If those and the provider
   * classes ever disagree, a user can save a preference that resolves to
   * nothing — which now falls back silently rather than erroring, so it would
   * show up as "why is Gemini answering as Claude" and nothing else.
   */
  public function testEveryNameOfferedInPreferencesResolves(): void {
    self::bootKernel();
    $registry = self::getContainer()->get(ProviderRegistry::class);

    $offered = [
      'Mistral',
      'Anthropic',
      'OpenAI',
      ChatbotManagerInterface::CHATBOT_MODEL_GEMINI,
      ChatbotManagerInterface::CHATBOT_MODEL_EMPATHICA,
      // The historical typo, still present in stored rows.
      'Antropic',
    ];

    foreach ($offered as $name) {
      $this->assertTrue($registry->has($name), "No provider is registered for \"{$name}\".");
    }
  }
}
