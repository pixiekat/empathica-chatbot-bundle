<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Pixiekat\EmpathicaChatbot\Interfaces\Services\ChatbotManagerInterface;
use Pixiekat\EmpathicaChatbot\Repository\ChatbotPreferencesRepository;
use Pixiekat\SymfonyHelpers\Interfaces\Entity\HelpersUserInterface;
use Pixiekat\SymfonyHelpers\Traits as PixieTraits;

/**
 * One user's chatbot settings: provider, persona, tone and remembered notes.
 *
 * WHY THIS IS ITS OWN ENTITY
 * --------------------------
 * In the standalone chatbot-app these were columns on App\Entity\User. A bundle
 * cannot do that — it does not own the host's user table, and asking every host
 * to add six columns (and a migration) to its User before the chat works is the
 * opposite of plug-and-play.
 *
 * So the bundle keeps them in its own table, keyed by the user. The host's User
 * only has to implement HelpersUserInterface, which it already does if it uses
 * the SymfonyHelpers bundle at all.
 *
 * WHY THE RELATION IS ONE-WAY
 * ---------------------------
 * There is no `inversedBy` and no matching property on the user. Doctrine is
 * perfectly happy with a unidirectional OneToOne, and it means the host never
 * has to know this table exists. Always go through
 * ChatbotPreferencesManager::forUser() to get a user's preferences — it returns
 * an unsaved, defaulted instance for someone who has never changed anything, so
 * merely viewing the chat never writes a row.
 *
 * WHAT IS NOT HERE
 * ----------------
 * Dark mode, larger font and theme lived on the old User too. Those are
 * site-wide accessibility settings that belong to the host application, so the
 * chat follows whatever the host's layout does rather than keeping its own copy
 * that could disagree with it.
 */
#[ORM\Entity(repositoryClass: ChatbotPreferencesRepository::class)]
#[ORM\Table(name: 'empathica_chatbot_preferences')]
#[ORM\HasLifecycleCallbacks]
class ChatbotPreferences {

  use PixieTraits\Entity\EntityIdTrait;

  /**
   * Fallbacks used when nothing better is known.
   *
   * The provider default here is only the last resort: ChatbotPreferencesManager
   * seeds new instances from the `empathica_chatbot.default_provider` config
   * value, so a host can change it without touching this class.
   */
  public const DEFAULT_PROVIDER = 'Anthropic';
  public const DEFAULT_PERSONA = ChatbotManagerInterface::PERSONA_EMILY;
  public const DEFAULT_LEVEL = 2;

  /**
   * Human labels for the stored integer levels.
   *
   * Kept on the entity (rather than in a template) so the chat page, the
   * preferences form and the admin transcript all describe a level the same
   * way. Empathy 4 and 5 are research-only arms; see PreferencesType.
   */
  public const LITERACY_LABELS = [
    1 => 'Basic',
    2 => 'Intermediate',
    3 => 'Advanced',
    4 => 'Clinical',
  ];

  public const EMPATHY_LABELS = [
    1 => 'Basic',
    2 => 'Intermediate',
    3 => 'Advanced',
    4 => 'Anti empathy',
    5 => 'Confrontational',
  ];

  /**
   * The user these preferences belong to.
   *
   * Resolved to the host's concrete user class by the SymfonyHelpers bundle's
   * resolve_target_entities mapping. Unique, because one user has exactly one
   * set of preferences; CASCADE, so deleting a user leaves no orphan rows.
   */
  #[ORM\OneToOne(targetEntity: HelpersUserInterface::class)]
  #[ORM\JoinColumn(name: 'user_id', nullable: false, unique: true, onDelete: 'CASCADE')]
  private HelpersUserInterface $user;

  /**
   * Canonical provider name, as returned by ChatProviderInterface::getName().
   *
   * Stored as text rather than an enum so a provider can be added or removed
   * without a migration. A stale value is harmless: ProviderRegistry::getOrDefault()
   * falls back to a working provider.
   */
  #[ORM\Column(length: 64)]
  private string $provider = self::DEFAULT_PROVIDER;

  #[ORM\Column(length: 64)]
  private string $persona = self::DEFAULT_PERSONA;

  #[ORM\Column(type: Types::SMALLINT, options: ['default' => self::DEFAULT_LEVEL])]
  private int $literacyLevel = self::DEFAULT_LEVEL;

  #[ORM\Column(type: Types::SMALLINT, options: ['default' => self::DEFAULT_LEVEL])]
  private int $empathyLevel = self::DEFAULT_LEVEL;

  /**
   * Whether the model announces the active empathy/literacy levels.
   *
   * Invaluable while testing; disqualifying in a study, because it tells a
   * participant which experimental arm they are in. Off by default and only
   * offered to admins in PreferencesType.
   */
  #[ORM\Column(options: ['default' => false])]
  private bool $debugModeEnabled = false;

  /**
   * Free-text notes the user wants the chatbot to remember about them.
   *
   * Rendered into the system prompt via config/prompts/user-memory.md.
   */
  #[ORM\Column(type: Types::TEXT, nullable: true)]
  private ?string $memory = null;

  // Both traits register their own PrePersist / PreUpdate callbacks (hence
  // HasLifecycleCallbacks above), so the timestamps need no help from here
  // and no dependency on Gedmo being installed in the host.
  use PixieTraits\Entity\EntityCreatedAtTrait;
  use PixieTraits\Entity\EntityUpdatedAtTrait;

  /**
   * @param HelpersUserInterface $user
   *   Required up front: a preferences row without an owner is meaningless,
   *   and making it a constructor argument means one can never be persisted
   *   without it.
   */
  public function __construct(HelpersUserInterface $user) {
    $this->user = $user;
  }

  public function getUser(): HelpersUserInterface {
    return $this->user;
  }

  public function getProvider(): string {
    return $this->provider;
  }

  public function setProvider(string $provider): static {
    $this->provider = $provider;

    return $this;
  }

  public function getPersona(): string {
    return $this->persona;
  }

  public function setPersona(string $persona): static {
    $this->persona = $persona;

    return $this;
  }

  public function getLiteracyLevel(): int {
    return $this->literacyLevel;
  }

  public function setLiteracyLevel(int $literacyLevel): static {
    $this->literacyLevel = $literacyLevel;

    return $this;
  }

  /**
   * The literacy level as a word, e.g. "Intermediate".
   */
  public function getLiteracyLabel(): string {
    return self::LITERACY_LABELS[$this->literacyLevel] ?? self::LITERACY_LABELS[self::DEFAULT_LEVEL];
  }

  public function getEmpathyLevel(): int {
    return $this->empathyLevel;
  }

  public function setEmpathyLevel(int $empathyLevel): static {
    $this->empathyLevel = $empathyLevel;

    return $this;
  }

  /**
   * The empathy level as a word, e.g. "Intermediate".
   */
  public function getEmpathyLabel(): string {
    return self::EMPATHY_LABELS[$this->empathyLevel] ?? self::EMPATHY_LABELS[self::DEFAULT_LEVEL];
  }

  public function isDebugModeEnabled(): bool {
    return $this->debugModeEnabled;
  }

  public function setDebugModeEnabled(bool $debugModeEnabled): static {
    $this->debugModeEnabled = $debugModeEnabled;

    return $this;
  }

  public function getMemory(): ?string {
    return $this->memory;
  }

  public function setMemory(?string $memory): static {
    $this->memory = $memory;

    return $this;
  }
}
