<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Twig\Runtime;

use Pixiekat\EmpathicaChatbot\Entity\Conversation;
use Pixiekat\EmpathicaChatbot\Entity\ChatbotPreferences;
use Pixiekat\EmpathicaChatbot\Repository\ConversationRepository;
use Pixiekat\EmpathicaChatbot\Services\ChatbotPreferencesManager;
use Pixiekat\SymfonyHelpers\Interfaces\Entity\HelpersUserInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\RuntimeExtensionInterface;

class ConversationExtensionRuntime implements RuntimeExtensionInterface {

  /**
   * How many threads the switcher lists.
   *
   * The panel is a quick way back to a recent conversation, not an archive —
   * the paginated history page is for that. Capping it keeps the navbar query
   * bounded no matter how long someone's history gets.
   */
  private const LIMIT = 20;

  /**
   * Per-request memo. The layout asks for the list and the counts
   * separately, and the switcher renders once per page, so without this the
   * same query would run twice on every single request.
   *
   * @var Conversation[]|null
   */
  private ?array $conversations = null;

  /**
   * @var array<int, int>|null
   */
  private ?array $counts = null;

  public function __construct(
    private readonly Security $security,
    private readonly ConversationRepository $conversations_repository,
    private readonly ChatbotPreferencesManager $preferences,
  ) {
  }

  /**
   * The current user's chatbot preferences, or null for an anonymous visitor.
   *
   * Lets the host layout (and the floating chat widget) read things like the
   * active persona without the host's User knowing preferences exist. Never
   * writes: a user who has not saved gets unsaved defaults.
   */
  public function getPreferences(): ?ChatbotPreferences {
    $user = $this->security->getUser();

    return $user instanceof HelpersUserInterface ? $this->preferences->forUser($user) : null;
  }

  /**
   * The current user's most recent threads, newest activity first.
   *
   * Always returns an array — including for anonymous visitors, so templates
   * never need an `is defined` guard around it.
   *
   * @return Conversation[]
   */
  public function getConversations(): array {
    if ($this->conversations !== null) {
      return $this->conversations;
    }

    $user = $this->security->getUser();
    if (!$user instanceof HelpersUserInterface) {
      return $this->conversations = [];
    }

    return $this->conversations = iterator_to_array(
      $this->conversations_repository->findByUserPaginated($user, 1, self::LIMIT)
    );
  }

  /**
   * Message counts for those threads, keyed by conversation id.
   *
   * One grouped query rather than a COUNT per row — otherwise the switcher
   * quietly becomes N+1 as someone's history grows.
   *
   * @return array<int, int>
   */
  public function getMessageCounts(): array {
    if ($this->counts !== null) {
      return $this->counts;
    }

    return $this->counts = $this->conversations_repository->countMessagesFor($this->getConversations());
  }
}
