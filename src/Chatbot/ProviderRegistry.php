<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Chatbot;

use Pixiekat\EmpathicaChatbot\Interfaces\Services\ChatProviderInterface;

/**
 * Looks a provider up by the name stored in ChatbotPreferences.provider.
 *
 * Providers are collected by service tag rather than listed here, so adding
 * one is a matter of writing the class — autoconfiguration tags anything
 * implementing ChatProviderInterface (see config/services.yaml) and it appears
 * in this registry automatically. There is no central list to forget to
 * update, which was the whole failure mode of the switch statements this
 * replaced.
 */
final class ProviderRegistry {

  /**
   * Historical spellings, mapped to the canonical provider name.
   *
   * 'Antropic' is a typo that made it into production rows of the standalone app's User.chatbot column. It
   * used to be checked for alongside 'Anthropic' in several switch statements;
   * normalising once, here, means no provider class ever has to know the typo
   * existed. Delete the entry once a migration has rewritten the stored rows —
   * and check for stale values first, because anyone whose preference still
   * says 'Antropic' would silently fall back to the default provider.
   */
  private const ALIASES = [
    'Antropic' => 'Anthropic',
  ];

  /**
   * @var array<string, ChatProviderInterface>
   */
  private array $providers = [];

  /**
   * @param iterable<ChatProviderInterface> $providers
   *   All tagged providers. Indexed here by their own getName() rather than by
   *   a service-tag attribute, so the key can never disagree with the class.
   */
  public function __construct(iterable $providers) {
    foreach ($providers as $provider) {
      $this->providers[$provider->getName()] = $provider;
    }
  }

  /**
   * The provider for a stored preference value.
   *
   * @throws \InvalidArgumentException
   *   If the name matches no registered provider. This is reachable from real
   *   data — a ChatbotPreferences.provider row naming a provider that has since been removed
   *   — so callers that render a page should use getOrDefault() instead.
   */
  public function get(string $name): ChatProviderInterface {
    $canonical = self::canonicalize($name);

    if (!isset($this->providers[$canonical])) {
      throw new \InvalidArgumentException(sprintf(
        'Unknown chatbot provider "%s". Registered: %s.',
        $name,
        implode(', ', $this->names())
      ));
    }

    return $this->providers[$canonical];
  }

  /**
   * The provider for a stored preference value, or a sensible substitute.
   *
   * A stale or mistyped ChatbotPreferences.provider value should degrade to a working chat,
   * not take the page down — the same reasoning as
   * ChatbotMessage::setPersona(), and for the same reason: nothing at the call
   * site could usefully act on an exception here.
   *
   * @param string $fallback
   *   Provider to use when $name is unknown. Must itself be registered; if it
   *   is not, the first registered provider is used, because "no chatbot at
   *   all" is not a state this app can render.
   */
  public function getOrDefault(string $name, string $fallback = 'Anthropic'): ChatProviderInterface {
    $canonical = self::canonicalize($name);

    if (isset($this->providers[$canonical])) {
      return $this->providers[$canonical];
    }
    if (isset($this->providers[$fallback])) {
      return $this->providers[$fallback];
    }

    $first = reset($this->providers);
    if ($first === false) {
      throw new \LogicException(
        'No chat providers are registered. Check that the bundle\'s config/services.yaml '
        . 'still tags Pixiekat\EmpathicaChatbot\Interfaces\Services\ChatProviderInterface.'
      );
    }

    return $first;
  }

  /**
   * Whether a provider is registered under this name.
   */
  public function has(string $name): bool {
    return isset($this->providers[self::canonicalize($name)]);
  }

  /**
   * Every registered provider name.
   *
   * @return list<string>
   */
  public function names(): array {
    return array_keys($this->providers);
  }

  /**
   * Resolve historical spellings to the canonical provider name.
   */
  private static function canonicalize(string $name): string {
    return self::ALIASES[$name] ?? $name;
  }
}
