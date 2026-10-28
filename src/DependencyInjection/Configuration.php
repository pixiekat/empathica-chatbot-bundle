<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration tree for the bundle.
 *
 * Configure in config/packages/empathica_chatbot.yaml. Every key is optional and
 * defaulted, so an application that only registers the bundle still boots —
 * it just cannot reach a provider until it supplies a key for one.
 *
 *   empathica_chatbot:
 *     default_provider: Gemini
 *     providers:
 *       gemini:
 *         api_key: '%env(GEMINI_API_KEY)%'
 *
 * WHY THE KEYS ARE CONFIG AND NOT %env()% INSIDE THE BUNDLE
 * ---------------------------------------------------------
 * In the standalone app, services.yaml read GEMINI_API_KEY and friends
 * directly. A bundle doing that would dictate the host's environment variable
 * names. Taking the value as configuration lets the host decide where it comes
 * from — an env var, a Symfony secret, a vault — with an %env()% reference in
 * its own YAML, which is the standard Symfony bundle convention.
 *
 * The alias `empathica_chatbot` is derived by Symfony from the extension class
 * name (EmpathicaChatbotExtension → empathica_chatbot).
 */
class Configuration implements ConfigurationInterface {

  /**
   * {@inheritdoc}
   */
  public function getConfigTreeBuilder(): TreeBuilder {
    $treeBuilder = new TreeBuilder('empathica_chatbot');
    $rootNode = $treeBuilder->getRootNode();

    $rootNode
      ->children()
        ->scalarNode('default_provider')
          ->defaultValue('Gemini')
          ->cannotBeEmpty()
          ->info('Provider a user starts on before they (or an admin) choose one. Must match a provider\'s getName(): Anthropic, Gemini, OpenAI, Mistral or EmpathicaAgent.')
        ->end()
        ->arrayNode('selectable_providers')
          ->info('Providers an admin may pick on the preferences page. Only list ones this deployment has credentials for.')
          ->scalarPrototype()->end()
          ->defaultValue(['Gemini', 'EmpathicaAgent'])
        ->end()
        ->scalarNode('admin_role')
          ->defaultValue('ROLE_ADMIN')
          ->cannotBeEmpty()
          ->info('Role required for the admin screens and the research-only preference controls. The host\'s role_hierarchy applies.')
        ->end()
        ->scalarNode('layout')
          ->defaultValue('base.html.twig')
          ->cannotBeEmpty()
          ->info('Template the full-page chat screens extend. Must define a `body` block. Published as the chatbot_layout Twig global.')
          ->example('layouts/app.html.twig')
        ->end()
        ->scalarNode('prompts_dir')
          ->defaultNull()
          ->info('Directory of system-prompt Markdown files. Null uses the bundle\'s own config/prompts. Point it at a copy to edit prompts without forking the bundle.')
        ->end()
        // Same idea as symfony_helpers.features: a switched-off feature
        // disappears from the menu AND its voter denies access, so its routes
        // answer 403 rather than staying reachable to anyone who guesses the
        // URL. Tables are unaffected.
        ->arrayNode('features')
          ->addDefaultsIfNotSet()
          ->children()
            ->booleanNode('admin')->defaultTrue()->info('Researcher screens: users with conversations, transcripts, CSV export.')->end()
          ->end()
        ->end()
        ->arrayNode('providers')
          ->addDefaultsIfNotSet()
          ->info('Per-provider credentials. Leave a key empty for any provider you do not use — nothing fails until somebody selects it, and then the error names what is missing.')
          ->children()
            ->arrayNode('anthropic')
              ->addDefaultsIfNotSet()
              ->children()
                ->scalarNode('api_key')->defaultValue('')->end()
                ->enumNode('prompt_cache_ttl')
                  ->values(['5m', '1h', ''])
                  ->defaultValue('5m')
                  ->info('Prompt caching for the ~30k-token system prompt. 5m: writes 1.25x, reads 0.1x. 1h: writes 2x. Empty disables it.')
                ->end()
              ->end()
            ->end()
            ->arrayNode('gemini')
              ->addDefaultsIfNotSet()
              ->children()
                ->scalarNode('api_key')->defaultValue('')->info('Also used for text-to-speech unless tts.api_key is set.')->end()
              ->end()
            ->end()
            ->arrayNode('openai')
              ->addDefaultsIfNotSet()
              ->children()
                ->scalarNode('api_key')->defaultValue('')->end()
              ->end()
            ->end()
            ->arrayNode('mistral')
              ->addDefaultsIfNotSet()
              ->children()
                ->scalarNode('api_key')->defaultValue('')->end()
              ->end()
            ->end()
            ->arrayNode('empathica')
              ->addDefaultsIfNotSet()
              ->info('EmpathicaAgent on Cloud Run. No API key: it authenticates with Application Default Credentials (GOOGLE_APPLICATION_CREDENTIALS off GCP) and discovers its URL from the Cloud Run Admin API.')
              ->children()
                ->scalarNode('project')->defaultValue('')->end()
                ->scalarNode('region')->defaultValue('us-east4')->end()
                ->scalarNode('service_name')->defaultValue('empathica-agents')->end()
              ->end()
            ->end()
          ->end()
        ->end()
        ->arrayNode('tts')
          ->addDefaultsIfNotSet()
          ->info('Gemini read-aloud. Off by default: the browser\'s own speechSynthesis is used instead, which is free and needs no configuration.')
          ->children()
            ->booleanNode('enabled')->defaultFalse()->end()
            ->scalarNode('api_key')->defaultNull()->info('Null reuses providers.gemini.api_key.')->end()
            ->scalarNode('model')->defaultValue('gemini-3.1-flash-tts-preview')->info('A preview model; expect the name to change.')->end()
            ->scalarNode('voice')->defaultValue('Kore')->info('One of Gemini\'s prebuilt voices.')->end()
            ->integerNode('prefetch')->defaultValue(1)->min(0)->info('Audio pieces fetched ahead of the one playing. 0 fetches strictly on demand.')->end()
          ->end()
        ->end()
        ->arrayNode('rate_limits')
          ->addDefaultsIfNotSet()
          ->info('Sliding-window limits, keyed on the user. Registered as framework.rate_limiter entries for you.')
          ->children()
            ->arrayNode('chat_message')
              ->addDefaultsIfNotSet()
              ->children()
                ->integerNode('limit')->defaultValue(12)->min(1)->end()
                ->scalarNode('interval')->defaultValue('1 minute')->end()
              ->end()
            ->end()
            ->arrayNode('text_to_speech')
              ->addDefaultsIfNotSet()
              ->children()
                ->integerNode('limit')->defaultValue(90)->min(1)->end()
                ->scalarNode('interval')->defaultValue('1 minute')->end()
              ->end()
            ->end()
          ->end()
        ->end()
      ->end()
    ;

    return $treeBuilder;
  }
}
