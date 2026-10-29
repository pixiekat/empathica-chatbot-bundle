<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Pixiekat\EmpathicaChatbot\DependencyInjection\EmpathicaChatbotExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The bundle configuration: its defaults, and how it lands in parameters.
 *
 * A bare ContainerBuilder, no kernel. load() validates the config tree, sets
 * the empathica_chatbot.* parameters and loads services.yaml, which is all
 * this needs to check.
 */
final class ExtensionTest extends TestCase {

  /**
   * @param array<string, mixed> $config What a host would write under empathica_chatbot:.
   */
  private function load(array $config = []): ContainerBuilder {
    $container = new ContainerBuilder();
    (new EmpathicaChatbotExtension())->load([$config], $container);
    return $container;
  }

  /**
   * An empty config block must give a safe, working install: admin screens
   * on but role-gated, read-aloud off (it's billed), and the bundle's own
   * prompts.
   */
  public function testDefaultsAreSafe(): void {
    $container = $this->load();

    $this->assertTrue($container->getParameter('empathica_chatbot.features.admin'));
    $this->assertSame('ROLE_ADMIN', $container->getParameter('empathica_chatbot.admin_role'));
    $this->assertFalse($container->getParameter('empathica_chatbot.tts.enabled'), 'Read-aloud costs money, so it must be opt-in.');
    $this->assertStringEndsWith('config/prompts', str_replace('\\', '/', $container->getParameter('empathica_chatbot.prompts_dir')));
  }

  /**
   * Read-aloud uses Gemini, so with no key of its own it borrows the Gemini
   * chat key rather than needing the same secret configured twice.
   */
  public function testTextToSpeechKeyFallsBackToTheGeminiKey(): void {
    $container = $this->load(['providers' => ['gemini' => ['api_key' => 'chat-key']]]);
    $this->assertSame('chat-key', $container->getParameter('empathica_chatbot.tts.api_key'));

    $container = $this->load([
      'providers' => ['gemini' => ['api_key' => 'chat-key']],
      'tts' => ['api_key' => 'tts-key'],
    ]);
    $this->assertSame('tts-key', $container->getParameter('empathica_chatbot.tts.api_key'));
  }

  public function testAdminScreensCanBeSwitchedOff(): void {
    $this->assertFalse($this->load(['features' => ['admin' => false]])->getParameter('empathica_chatbot.features.admin'));
  }

  /**
   * The prompt cache TTL goes into Anthropic's API verbatim, so a typo must
   * fail when the container is built, not as an API error mid-conversation.
   */
  public function testInvalidPromptCacheTtlIsRejected(): void {
    $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
    $this->load(['providers' => ['anthropic' => ['prompt_cache_ttl' => '10m']]]);
  }
}
