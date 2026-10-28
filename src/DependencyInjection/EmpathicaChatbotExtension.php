<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Wires the bundle into the host's container.
 *
 * Two jobs, same split as SymfonyHelpersExtension:
 *   prepend() — contribute configuration to OTHER bundles (Doctrine, migrations,
 *               Monolog, the rate limiter, Twig) so the host does not have to
 *               copy any of it into its own config/packages files.
 *   load()    — register this bundle's services and publish its configuration
 *               as container parameters for config/services.yaml to use.
 *
 * Everything prepended merges UNDERNEATH the host's own configuration, so a
 * host can still override any of it — e.g. to give the chatbot Monolog channel
 * its own log file.
 */
class EmpathicaChatbotExtension extends Extension implements PrependExtensionInterface {

  /**
   * Rate limiter names. Prefixed because framework.rate_limiter is one flat
   * namespace shared with the host — Infosage could easily already have a
   * limiter called "chat_message".
   *
   * Symfony registers each as the service `limiter.<name>`, and
   * config/services.yaml binds those to the controller arguments.
   */
  public const LIMITER_CHAT = 'empathica_chatbot_chat_message';
  public const LIMITER_TTS = 'empathica_chatbot_text_to_speech';

  /**
   * The Monolog channel every outbound provider call is logged to.
   */
  public const LOG_CHANNEL = 'chatbot';

  /**
   * {@inheritdoc}
   */
  public function prepend(ContainerBuilder $container): void {
    $config = $this->processConfiguration(
      new Configuration(),
      $container->getExtensionConfig($this->getAlias()),
    );

    $bundleRoot = \dirname(__DIR__, 2);

    $this->prependDoctrine($container, $bundleRoot);
    $this->prependAssets($container, $bundleRoot);
    $this->prependMonolog($container);
    $this->prependRateLimiters($container, $config);
    $this->prependTwigGlobals($container, $config);
  }

  /**
   * Entity mapping and migrations path.
   *
   * The mapping is declared explicitly rather than left to DoctrineBundle's
   * auto_mapping, which guesses an entity directory from the bundle's path and
   * has changed its guesses between versions. One explicit entry means the
   * bundle works the same in a host that has auto_mapping turned off.
   *
   * The migrations path is what saves the host a line in
   * doctrine_migrations.yaml — `doctrine:migrations:migrate` simply finds the
   * bundle's migrations alongside its own.
   */
  private function prependDoctrine(ContainerBuilder $container, string $bundleRoot): void {
    if ($container->hasExtension('doctrine')) {
      $container->prependExtensionConfig('doctrine', [
        'orm' => [
          'mappings' => [
            'PixiekatEmpathicaChatbot' => [
              'type' => 'attribute',
              'is_bundle' => false,
              'dir' => $bundleRoot . '/src/Entity',
              'prefix' => 'Pixiekat\\EmpathicaChatbot\\Entity',
              'alias' => 'EmpathicaChatbot',
            ],
          ],
        ],
      ]);
    }

    if ($container->hasExtension('doctrine_migrations')) {
      $container->prependExtensionConfig('doctrine_migrations', [
        'migrations_paths' => [
          'Pixiekat\\EmpathicaChatbot\\Migrations' => $bundleRoot . '/migrations',
        ],
      ]);
    }
  }

  /**
   * Exposes the bundle's assets/ directory to AssetMapper.
   *
   * Under the logical prefix `empathica-chatbot/`, so templates reference
   * {{ asset('empathica-chatbot/styles/chatbot.css') }} and get a versioned,
   * cache-busted URL in production — with no importmap entry and no copy step
   * in the host. The stylesheet is then loaded with a plain <link>, and the
   * script with a plain <script type="module" src>, by the bundle's own layout.
   */
  private function prependAssets(ContainerBuilder $container, string $bundleRoot): void {
    if (!interface_exists(\Symfony\Component\AssetMapper\AssetMapperInterface::class)) {
      return;
    }

    $container->prependExtensionConfig('framework', [
      'asset_mapper' => [
        'paths' => [
          $bundleRoot . '/assets' => 'empathica-chatbot',
        ],
      ],
    ]);
  }

  /**
   * Declares the `chatbot` channel, so `monolog.logger.chatbot` exists.
   *
   * Without the channel that service id does not exist and the container
   * fails to compile. Route it wherever you like from the host:
   *
   *   monolog:
   *     handlers:
   *       chatbot:
   *         type: rotating_file
   *         path: '%kernel.logs_dir%/chatbot.log'
   *         channels: ['chatbot']
   */
  private function prependMonolog(ContainerBuilder $container): void {
    if (!$container->hasExtension('monolog')) {
      return;
    }

    $container->prependExtensionConfig('monolog', [
      'channels' => [self::LOG_CHANNEL],
    ]);
  }

  /**
   * Registers the chat and read-aloud limiters.
   *
   * Chat: every accepted message is a provider call costing ~30,000 prompt
   * tokens, so this is cost control as much as abuse control — the likeliest
   * trigger is a stuck retry or a double-submitted form, not a malicious user.
   *
   * Read-aloud: one long answer can be six chunk requests, and replaying a
   * conversation is expected behaviour for this audience, so the default
   * ceiling is much higher.
   *
   * Sliding window for both: a fixed window lets someone spend the whole
   * allowance at 10:59:59 and again at 11:00:00.
   */
  private function prependRateLimiters(ContainerBuilder $container, array $config): void {
    $container->prependExtensionConfig('framework', [
      'rate_limiter' => [
        self::LIMITER_CHAT => [
          'policy' => 'sliding_window',
          'limit' => $config['rate_limits']['chat_message']['limit'],
          'interval' => $config['rate_limits']['chat_message']['interval'],
        ],
        self::LIMITER_TTS => [
          'policy' => 'sliding_window',
          'limit' => $config['rate_limits']['text_to_speech']['limit'],
          'interval' => $config['rate_limits']['text_to_speech']['interval'],
        ],
      ],
    ]);
  }

  /**
   * Publishes the values templates need as Twig globals.
   *
   *   chatbot_layout        — what the full-page chat screens extend.
   *   chatbot_tts_prefetch  — rendered into a data attribute for chatbot.js.
   *
   * Inlined values rather than %parameter% references, for the reason
   * SymfonyHelpersExtension::escapeTwigGlobal() documents: a template name
   * starting with '@' would otherwise be read as a service reference.
   */
  private function prependTwigGlobals(ContainerBuilder $container, array $config): void {
    if (!$container->hasExtension('twig')) {
      return;
    }

    $layout = (string) $config['layout'];

    $container->prependExtensionConfig('twig', [
      'globals' => [
        // TwigBundle's escape for a literal leading '@' is to double it.
        'chatbot_layout' => str_starts_with($layout, '@') ? '@' . $layout : $layout,
        'chatbot_tts_prefetch' => $config['tts']['prefetch'],
      ],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function load(array $configs, ContainerBuilder $container): void {
    $config = $this->processConfiguration(new Configuration(), $configs);

    // Flat scalar parameters, not one nested array: '%a.b.c%' is a lookup for
    // a parameter literally named "a.b.c", so you cannot reach *into* an array
    // parameter with dots. The empathica block is the exception — it is passed
    // whole, as one argument, which works.
    $providers = $config['providers'];
    $container->setParameter('empathica_chatbot.default_provider', $config['default_provider']);
    $container->setParameter('empathica_chatbot.selectable_providers', $config['selectable_providers']);
    $container->setParameter('empathica_chatbot.admin_role', $config['admin_role']);
    $container->setParameter('empathica_chatbot.features.admin', $config['features']['admin']);
    $container->setParameter('empathica_chatbot.prompts_dir', $config['prompts_dir'] ?? \dirname(__DIR__, 2) . '/config/prompts');

    $container->setParameter('empathica_chatbot.anthropic.api_key', (string) $providers['anthropic']['api_key']);
    $container->setParameter('empathica_chatbot.anthropic.prompt_cache_ttl', $providers['anthropic']['prompt_cache_ttl']);
    $container->setParameter('empathica_chatbot.gemini.api_key', (string) $providers['gemini']['api_key']);
    $container->setParameter('empathica_chatbot.openai.api_key', (string) $providers['openai']['api_key']);
    $container->setParameter('empathica_chatbot.mistral.api_key', (string) $providers['mistral']['api_key']);
    $container->setParameter('empathica_chatbot.empathica', $providers['empathica']);

    $container->setParameter('empathica_chatbot.tts.enabled', $config['tts']['enabled']);
    $container->setParameter('empathica_chatbot.tts.api_key', (string) ($config['tts']['api_key'] ?? $providers['gemini']['api_key']));
    $container->setParameter('empathica_chatbot.tts.model', $config['tts']['model']);
    $container->setParameter('empathica_chatbot.tts.voice', $config['tts']['voice']);

    $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 2) . '/config'));
    $loader->load('services.yaml');
  }
}
