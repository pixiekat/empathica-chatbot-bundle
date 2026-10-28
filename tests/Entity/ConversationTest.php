<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Tests\Entity;

use Pixiekat\EmpathicaChatbot\Entity\Conversation;
use Pixiekat\EmpathicaChatbot\Entity\ConversationMessage;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the conversation model's own logic.
 *
 * Deliberately free of the database: what is worth pinning here is the
 * behaviour that would otherwise only be noticed as a subtly wrong transcript
 * — sequence numbering, title derivation, and the truncation flag.
 */
final class ConversationTest extends TestCase {

  /**
   * Messages must be numbered in the order they are added.
   *
   * Ordering is by sequence rather than by id or timestamp: ids are a storage
   * detail, and two turns written in the same second have no reliable order by
   * timestamp alone. Get this wrong and a transcript reads out of order.
   */
  public function testMessagesAreSequencedInInsertionOrder(): void {
    $conversation = new Conversation();
    $conversation->addMessage(ConversationMessage::fromUser('First question.'));
    $conversation->addMessage(ConversationMessage::fromAssistant('First answer.'));
    $conversation->addMessage(ConversationMessage::fromUser('Second question.'));

    $sequences = array_map(
      static fn (ConversationMessage $m): int => $m->getSequence(),
      $conversation->getMessages()->toArray()
    );

    $this->assertSame([0, 1, 2], $sequences);
  }

  /**
   * Adding a message links it back and moves the activity timestamp, which is
   * what the conversation list sorts on.
   */
  public function testAddingAMessageLinksItAndTouchesActivity(): void {
    $conversation = new Conversation();
    $message = ConversationMessage::fromUser('Hello');

    $conversation->addMessage($message);

    $this->assertSame($conversation, $message->getConversation());
    $this->assertGreaterThanOrEqual(
      $conversation->getCreatedAt()->getTimestamp(),
      $conversation->getLastActivityAt()->getTimestamp()
    );
  }

  /**
   * The list label comes from the first thing the user said.
   */
  public function testTitleIsDerivedFromTheFirstMessage(): void {
    $conversation = new Conversation();
    $conversation->deriveTitleFrom('What are the side effects of my chemotherapy?');

    $this->assertSame('What are the side effects of my chemotherapy?', $conversation->getTitle());
  }

  /**
   * A long opening message is cut on a word boundary, not mid-word.
   */
  public function testLongTitlesAreTruncatedOnAWordBoundary(): void {
    $conversation = new Conversation();
    $conversation->deriveTitleFrom(str_repeat('medication ', 20));

    $title = $conversation->getTitle();

    $this->assertLessThanOrEqual(81, mb_strlen($title));
    $this->assertStringEndsWith('…', $title);
    $this->assertStringNotContainsString('medicati…', $title, 'Title was cut mid-word.');
  }

  /**
   * A thread keeps the title it was born with. Re-deriving on every message
   * would make the conversation list shuffle its own labels as people type.
   */
  public function testTitleIsOnlySetOnce(): void {
    $conversation = new Conversation();
    $conversation->deriveTitleFrom('First question.');
    $conversation->deriveTitleFrom('A completely different second question.');

    $this->assertSame('First question.', $conversation->getTitle());
  }

  /**
   * Markup and ragged whitespace must not reach the label.
   */
  public function testTitleIsCleanedOfMarkupAndWhitespace(): void {
    $conversation = new Conversation();
    $conversation->deriveTitleFrom("  <b>What   about</b>\n\n food?  ");

    $this->assertSame('What about food?', $conversation->getTitle());
  }

  /**
   * Imported threads are distinguishable from lived ones — their grouping is
   * a heuristic, so anyone analysing thread structure needs to know.
   */
  public function testImportedThreadsAreMarked(): void {
    $this->assertFalse((new Conversation())->isImported());
    $this->assertTrue((new Conversation())->setLegacyImportKey('1:2026-08-11')->isImported());
  }
}
