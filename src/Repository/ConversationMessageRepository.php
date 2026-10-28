<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Repository;

use Pixiekat\EmpathicaChatbot\Entity\Conversation;
use Pixiekat\EmpathicaChatbot\Entity\ConversationMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConversationMessage>
 */
class ConversationMessageRepository extends ServiceEntityRepository {

  /**
   * Rough characters-per-token ratio for English prose.
   *
   * Deliberately conservative. Real tokenisers differ per provider and we are
   * not going to ship four of them to estimate a budget — the cost of being
   * wrong in the safe direction is one fewer exchange of context, while being
   * wrong the other way is a 400 from the provider mid-study.
   */
  private const CHARS_PER_TOKEN = 3.5;

  public function __construct(ManagerRegistry $registry) {
    parent::__construct($registry, ConversationMessage::class);
  }

  /**
   * The tail of a conversation that fits inside a token budget.
   *
   * WHY THIS REPLACES THE OLD HEURISTIC
   *
   * AppController used to keep "the last 4 messages, or 2 for OpenAI, once
   * history exceeds 10". Those numbers were a proxy for a token budget that
   * ignored the thing actually consuming the budget: the system prompt is
   * ~28,500 tokens on its own, dwarfing any four chat turns. The count also
   * had nothing to do with how long the individual messages were.
   *
   * This walks backwards from the newest message, accumulating until the
   * budget is spent, then returns what it collected in chronological order.
   * Walking backwards is what makes it correct — the most recent exchange is
   * the one the model most needs, so it should be the last thing dropped.
   *
   * Returned messages always start on a user turn: a thread handed to
   * Anthropic or Gemini beginning with an assistant turn is rejected, and
   * ChatbotMessage would have to discard it anyway.
   *
   * @param int $budgetTokens
   *   Tokens available for conversation history, i.e. the model's context
   *   window minus the system prompt minus the reply allowance.
   *
   * @return ConversationMessage[] Oldest first.
   */
  public function findRecentWithinBudget(Conversation $conversation, int $budgetTokens): array {
    $newestFirst = $this->createQueryBuilder('m')
      ->andWhere('m.conversation = :conversation')
      ->setParameter('conversation', $conversation)
      ->orderBy('m.sequence', 'DESC')
      // Hard ceiling so a very long thread cannot pull thousands of rows into
      // memory just to discard most of them.
      ->setMaxResults(100)
      ->getQuery()
      ->getResult();

    $budgetChars = (int) ($budgetTokens * self::CHARS_PER_TOKEN);
    $kept = [];
    $used = 0;

    foreach ($newestFirst as $message) {
      $cost = mb_strlen($message->getContent());
      // Always keep at least one message, even if a single turn is somehow
      // larger than the whole budget — sending no history at all would be a
      // stranger failure than sending one oversized turn.
      if ($kept !== [] && $used + $cost > $budgetChars) {
        break;
      }

      $kept[] = $message;
      $used += $cost;
    }

    $kept = array_reverse($kept);

    // Trim any leading assistant turns left by the cut.
    while ($kept !== [] && !$kept[0]->isFromUser()) {
      array_shift($kept);
    }

    return $kept;
  }

  /**
   * Estimated token count for a string, using the same ratio as the budget.
   *
   * Exposed so callers can log what they think they sent alongside what the
   * provider reports actually being sent — the gap between the two is how you
   * find out the estimate needs adjusting.
   */
  public static function estimateTokens(string $text): int {
    return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN);
  }
}
