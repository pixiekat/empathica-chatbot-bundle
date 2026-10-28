<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Services;

use Doctrine\ORM\EntityManagerInterface;
use Pixiekat\EmpathicaChatbot\Entity\ChatbotPreferences;
use Pixiekat\EmpathicaChatbot\Repository\ChatbotPreferencesRepository;
use Pixiekat\SymfonyHelpers\Interfaces\Entity\HelpersUserInterface;

/**
 * The one way in to a user's chatbot preferences.
 *
 * WHY A MANAGER AND NOT JUST THE REPOSITORY
 * -----------------------------------------
 * Because preferences live in the bundle's own table, most users will not have
 * a row until the first time they press "Save" on the settings page. Every
 * caller would otherwise have to handle "null means defaults" on its own, and
 * one forgetting to do so is a 500 on the chat page.
 *
 * forUser() always returns an object. For a user who has never saved, that
 * object is new, filled from the bundle configuration, and NOT persisted — so
 * merely opening the chat (or rendering the widget on every Infosage page) never
 * writes to the database. save() is what makes it real.
 *
 * Results are memoised per user for the life of the service, which matters for
 * the Step 2 widget: the layout, the chat controller and a Twig function can all
 * ask in one request and get the same instance back, so an edit made by one is
 * seen by the others.
 */
class ChatbotPreferencesManager {

  /**
   * Preferences already resolved during this request, keyed by user id.
   *
   * @var array<int|string, ChatbotPreferences>
   */
  private array $resolved = [];

  /**
   * @param string $defaultProvider
   *   `empathica_chatbot.default_provider`, the provider a brand-new user starts on.
   */
  public function __construct(
    private readonly ChatbotPreferencesRepository $repository,
    private readonly EntityManagerInterface $entityManager,
    private readonly string $defaultProvider = ChatbotPreferences::DEFAULT_PROVIDER,
  ) {
  }

  /**
   * A user's preferences, saved or defaulted.
   *
   * @param HelpersUserInterface $user
   *   The host application's user. Any class implementing the interface works.
   *
   * @return ChatbotPreferences
   *   Their saved row if there is one, otherwise a new unsaved instance seeded
   *   with the configured defaults.
   */
  public function forUser(HelpersUserInterface $user): ChatbotPreferences {
    // An unsaved user has no id; fall back to object identity so the memo
    // still works (and can never collide with a real id).
    $key = $user->getId() ?? spl_object_id($user) . '#unsaved';

    return $this->resolved[$key] ??= $this->repository->findOneForUser($user)
      ?? (new ChatbotPreferences($user))->setProvider($this->defaultProvider);
  }

  /**
   * Persist preferences, creating the row on first save.
   *
   * persist() is a no-op for an entity Doctrine already manages, so this is
   * safe to call for both new and existing preferences.
   */
  public function save(ChatbotPreferences $preferences): void {
    $this->entityManager->persist($preferences);
    $this->entityManager->flush();
  }
}
