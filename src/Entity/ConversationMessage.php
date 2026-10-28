<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Entity;

use Pixiekat\EmpathicaChatbot\Repository\ConversationMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Pixiekat\SymfonyHelpers\Traits as PixieTraits;

/**
 * One turn in a conversation — either the user's or the model's.
 *
 * WHY ONE ROW PER MESSAGE
 *
 * UserChatHistory stored a user message and its reply together in a single
 * row. That matches a strictly turn-taking chat, but it cannot represent the
 * cases that actually matter when something goes wrong: a question that got no
 * answer because the provider timed out, a reply that was regenerated, or a
 * system notice shown in the transcript. Splitting the turns means the stored
 * record is the conversation, rather than a summary of it.
 *
 * WHY THE METADATA LIVES HERE
 *
 * This is a research application: a transcript is only useful if you can say
 * what produced it. Every assistant turn records the model, the literacy and
 * empathy levels in force, the prompt version, token usage, latency and finish
 * reason — so a result can be traced back to its exact conditions without
 * cross-referencing logs that have since rotated away.
 *
 * finishReason in particular is worth keeping: a truncated reply is not
 * obviously truncated when you read it later, and "was this cut off?" is
 * otherwise unanswerable after the fact.
 */
#[ORM\Entity(repositoryClass: ConversationMessageRepository::class)]
#[ORM\Table(name: 'empathica_chatbot_conversation_message')]
#[ORM\Index(name: 'idx_empathica_message_conversation_sequence', columns: ['conversation_id', 'sequence'])]
#[ORM\HasLifecycleCallbacks]
class ConversationMessage {

  /**
   * Roles as stored. Deliberately provider-neutral: Gemini's 'model' and
   * Anthropic's 'assistant' both normalise to ASSISTANT on the way in, so the
   * stored transcript reads the same whichever provider produced it.
   * ChatbotMessage::normalizeRole() re-maps them on the way back out.
   */
  public const ROLE_USER = 'user';
  public const ROLE_ASSISTANT = 'assistant';
  public const ROLE_SYSTEM = 'system';

  use PixieTraits\Entity\EntityIdTrait;

  #[ORM\ManyToOne(inversedBy: 'messages')]
  #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
  private ?Conversation $conversation = null;

  #[ORM\Column(length: 32)]
  private string $role = self::ROLE_USER;

  #[ORM\Column(type: Types::TEXT)]
  private string $content = '';

  /**
   * Position within the conversation, zero-based.
   *
   * Ordering is by this rather than by id or timestamp: ids are a storage
   * detail, and two rows written in the same second have no reliable order by
   * timestamp alone.
   */
  #[ORM\Column(type: 'integer')]
  private int $sequence = 0;

  // --- What produced this message. Assistant turns only; null on user turns.

  #[ORM\Column(length: 255, nullable: true)]
  private ?string $provider = null;

  #[ORM\Column(length: 255, nullable: true)]
  private ?string $modelId = null;

  #[ORM\Column(type: 'integer', nullable: true)]
  private ?int $literacyLevel = null;

  #[ORM\Column(type: 'integer', nullable: true)]
  private ?int $empathyLevel = null;

  #[ORM\Column(length: 255, nullable: true)]
  private ?string $promptVersion = null;

  #[ORM\Column(type: 'integer', nullable: true)]
  private ?int $promptTokens = null;

  #[ORM\Column(type: 'integer', nullable: true)]
  private ?int $completionTokens = null;

  #[ORM\Column(type: 'integer', nullable: true)]
  private ?int $latencyMs = null;

  /**
   * The provider's stop reason, normalised only as far as being a string:
   * 'stop', 'end_turn', 'MAX_TOKENS', 'length'. Kept verbatim rather than
   * mapped, because the mapping would lose exactly the detail you want when
   * investigating a specific provider's behaviour.
   */
  #[ORM\Column(length: 64, nullable: true)]
  private ?string $finishReason = null;

  use PixieTraits\Entity\EntityCreatedAtTrait;

  public function __construct() {
    $this->setCreatedAt(new \DateTimeImmutable());
  }

  /**
   * Convenience constructor for a user turn.
   */
  public static function fromUser(string $content): self {
    return (new self())->setRole(self::ROLE_USER)->setContent($content);
  }

  /**
   * Convenience constructor for a model turn.
   */
  public static function fromAssistant(string $content): self {
    return (new self())->setRole(self::ROLE_ASSISTANT)->setContent($content);
  }

  public function getConversation(): ?Conversation {
    return $this->conversation;
  }

  public function setConversation(?Conversation $conversation): static {
    $this->conversation = $conversation;

    return $this;
  }

  public function getRole(): string {
    return $this->role;
  }

  public function setRole(string $role): static {
    $this->role = $role;

    return $this;
  }

  public function isFromUser(): bool {
    return $this->role === self::ROLE_USER;
  }

  public function getContent(): string {
    return $this->content;
  }

  public function setContent(string $content): static {
    $this->content = $content;

    return $this;
  }

  public function getSequence(): int {
    return $this->sequence;
  }

  public function setSequence(int $sequence): static {
    $this->sequence = $sequence;

    return $this;
  }

  public function getProvider(): ?string {
    return $this->provider;
  }

  public function setProvider(?string $provider): static {
    $this->provider = $provider;

    return $this;
  }

  public function getModelId(): ?string {
    return $this->modelId;
  }

  public function setModelId(?string $modelId): static {
    $this->modelId = $modelId;

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

  public function getPromptTokens(): ?int {
    return $this->promptTokens;
  }

  public function setPromptTokens(?int $promptTokens): static {
    $this->promptTokens = $promptTokens;

    return $this;
  }

  public function getCompletionTokens(): ?int {
    return $this->completionTokens;
  }

  public function setCompletionTokens(?int $completionTokens): static {
    $this->completionTokens = $completionTokens;

    return $this;
  }

  public function getTotalTokens(): ?int {
    if ($this->promptTokens === null && $this->completionTokens === null) {
      return null;
    }

    return (int) $this->promptTokens + (int) $this->completionTokens;
  }

  public function getLatencyMs(): ?int {
    return $this->latencyMs;
  }

  public function setLatencyMs(?int $latencyMs): static {
    $this->latencyMs = $latencyMs;

    return $this;
  }

  public function getFinishReason(): ?string {
    return $this->finishReason;
  }

  public function setFinishReason(?string $finishReason): static {
    $this->finishReason = $finishReason;

    return $this;
  }

  /**
   * Did the provider stop because it ran out of output budget?
   *
   * Covers the spellings the four providers use. Worth surfacing in the admin
   * transcript: a truncated answer reads as a complete one.
   */
  public function wasTruncated(): bool {
    return in_array(strtolower((string) $this->finishReason), ['max_tokens', 'length'], true);
  }
}
