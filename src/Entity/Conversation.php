<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Entity;

use Pixiekat\EmpathicaChatbot\Repository\ConversationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Pixiekat\SymfonyHelpers\Interfaces\Entity\HelpersUserInterface;
use Pixiekat\SymfonyHelpers\Traits as PixieTraits;

/**
 * A single chat thread belonging to one user.
 *
 * WHY THIS EXISTS
 *
 * The conversation used to live only in the PHP session, with UserChatHistory
 * kept alongside purely as an evaluation record. That had three consequences
 * worth fixing:
 *
 *   - session garbage collection could drop a participant's context mid-study,
 *     with no way to tell that it had happened;
 *   - there was exactly one conversation per browser session, so nothing could
 *     be revisited or resumed — including from another device;
 *   - the context sent to the provider and the record kept for analysis were
 *     two different things that could silently diverge.
 *
 * Now the thread is the durable object and the session holds at most a pointer
 * to the active one.
 *
 * WHY THE SETTINGS ARE DUPLICATED HERE *AND* ON EACH MESSAGE
 *
 * The columns below record the conditions the thread was *started* under, so a
 * conversation can be listed and filtered without touching its messages. Each
 * ConversationMessage separately records the conditions that produced *it*,
 * because a user can change their preferences mid-thread. For analysis, always
 * trust the per-message values — these are a convenience, not the record.
 */
#[ORM\Entity(repositoryClass: ConversationRepository::class)]
#[ORM\Table(name: 'empathica_chatbot_conversation')]
#[ORM\Index(name: 'idx_empathica_conversation_user_activity', columns: ['user_id', 'last_activity_at'])]
#[ORM\HasLifecycleCallbacks]
class Conversation {

  use PixieTraits\Entity\EntityIdTrait;

  /**
   * The user who owns this thread.
   *
   * Mapped against HelpersUserInterface rather than a concrete class, because
   * a bundle cannot name App\Entity\User — that class only exists inside the
   * host application. The SymfonyHelpers bundle writes the
   * doctrine.orm.resolve_target_entities entry that swaps the interface for
   * the real class (symfony_helpers.user_class), so the column is still a
   * genuine foreign key to the host's user table.
   *
   * Unidirectional on purpose: there is no `inversedBy`, so the host's User
   * entity needs no `$conversations` collection and no edits at all to use
   * this bundle. Ask ConversationRepository for a user's threads instead.
   */
  #[ORM\ManyToOne(targetEntity: HelpersUserInterface::class)]
  #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
  private ?HelpersUserInterface $user = null;

  /**
   * Short human label for the conversation list.
   *
   * Derived from the first user message rather than asked for, because
   * prompting a patient to name a conversation is friction for no benefit.
   */
  #[ORM\Column(length: 255, nullable: true)]
  private ?string $title = null;

  #[ORM\Column(length: 255, nullable: true)]
  private ?string $chatbotModel = null;

  #[ORM\Column(length: 255, nullable: true)]
  private ?string $persona = null;

  #[ORM\Column(type: 'integer', nullable: true)]
  private ?int $literacyLevel = null;

  #[ORM\Column(type: 'integer', nullable: true)]
  private ?int $empathyLevel = null;

  /**
   * PromptRepository::getVersion() at the time the thread started.
   */
  #[ORM\Column(length: 255, nullable: true)]
  private ?string $promptVersion = null;

  /**
   * Marks a thread reconstructed from the legacy user_chat_history log.
   *
   * Two jobs. It is provenance — imported threads were grouped by a heuristic
   * (same user, same calendar day) rather than being real sessions, so anyone
   * analysing thread structure needs to know which ones are synthetic. And,
   * being unique, it makes the import re-runnable: a second pass cannot
   * duplicate a thread it already created.
   *
   * Null for every conversation actually started in the app.
   */
  #[ORM\Column(name: 'legacy_import_key', length: 64, nullable: true, unique: true)]
  private ?string $legacyImportKey = null;

  /**
   * Sort key for the conversation list, and the basis for "resume the thread
   * I was last in". Distinct from createdAt, which never moves.
   */
  #[ORM\Column(name: 'last_activity_at', nullable: false)]
  private \DateTimeImmutable $lastActivityAt;

  /**
   * @var Collection<int, ConversationMessage>
   */
  #[ORM\OneToMany(mappedBy: 'conversation', targetEntity: ConversationMessage::class, orphanRemoval: true)]
  #[ORM\OrderBy(['sequence' => 'ASC'])]
  private Collection $messages;

  use PixieTraits\Entity\EntityCreatedAtTrait;

  public function __construct() {
    $this->messages = new ArrayCollection();
    $this->setCreatedAt(new \DateTimeImmutable());
    $this->lastActivityAt = new \DateTimeImmutable();
  }

  public function getUser(): ?HelpersUserInterface {
    return $this->user;
  }

  public function setUser(?HelpersUserInterface $user): static {
    $this->user = $user;

    return $this;
  }

  public function getTitle(): ?string {
    return $this->title;
  }

  public function setTitle(?string $title): static {
    $this->title = $title;

    return $this;
  }

  /**
   * Build a list label from the first thing the user said.
   *
   * Truncated on a word boundary so the list does not show half a word, and
   * only set once — a thread keeps the title it was born with even as it grows.
   */
  public function deriveTitleFrom(string $firstUserMessage, int $maxLength = 80): static {
    if ($this->title !== null) {
      return $this;
    }

    $clean = trim(preg_replace('/\s+/u', ' ', strip_tags($firstUserMessage)) ?? '');
    if ($clean === '') {
      return $this;
    }

    if (mb_strlen($clean) > $maxLength) {
      $clipped = mb_substr($clean, 0, $maxLength);
      $lastSpace = mb_strrpos($clipped, ' ');
      $clean = ($lastSpace !== false && $lastSpace > 0 ? mb_substr($clipped, 0, $lastSpace) : $clipped) . '…';
    }

    return $this->setTitle($clean);
  }

  public function getChatbotModel(): ?string {
    return $this->chatbotModel;
  }

  public function setChatbotModel(?string $chatbotModel): static {
    $this->chatbotModel = $chatbotModel;

    return $this;
  }

  public function getPersona(): ?string {
    return $this->persona;
  }

  public function setPersona(?string $persona): static {
    $this->persona = $persona;

    return $this;
  }

  public function getLiteracyLevel(): ?int {
    return $this->literacyLevel;
  }

  public function setLiteracyLevel(?int $literacyLevel): static {
    $this->literacyLevel = $literacyLevel;

    return $this;
  }

  public function getEmpathyLevel(): ?int {
    return $this->empathyLevel;
  }

  public function setEmpathyLevel(?int $empathyLevel): static {
    $this->empathyLevel = $empathyLevel;

    return $this;
  }

  public function getPromptVersion(): ?string {
    return $this->promptVersion;
  }

  public function setPromptVersion(?string $promptVersion): static {
    $this->promptVersion = $promptVersion;

    return $this;
  }

  public function getLegacyImportKey(): ?string {
    return $this->legacyImportKey;
  }

  public function setLegacyImportKey(?string $legacyImportKey): static {
    $this->legacyImportKey = $legacyImportKey;

    return $this;
  }

  /**
   * Was this thread reconstructed from the legacy log rather than lived?
   */
  public function isImported(): bool {
    return $this->legacyImportKey !== null;
  }

  public function getLastActivityAt(): \DateTimeImmutable {
    return $this->lastActivityAt;
  }

  public function setLastActivityAt(\DateTimeImmutable $lastActivityAt): static {
    $this->lastActivityAt = $lastActivityAt;

    return $this;
  }

  public function touch(): static {
    return $this->setLastActivityAt(new \DateTimeImmutable());
  }

  /**
   * @return Collection<int, ConversationMessage>
   */
  public function getMessages(): Collection {
    return $this->messages;
  }

  /**
   * Append a message, assigning it the next sequence number.
   *
   * Sequence is maintained here rather than relying on the id, because ids are
   * a storage detail and analysis code should not have to assume they are
   * gapless or ordered the same way.
   */
  public function addMessage(ConversationMessage $message): static {
    if (!$this->messages->contains($message)) {
      $message->setConversation($this);
      $message->setSequence($this->messages->count());
      $this->messages->add($message);
      $this->touch();
    }

    return $this;
  }
}
