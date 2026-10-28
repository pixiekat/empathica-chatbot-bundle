<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Tests\Entity;

use Pixiekat\EmpathicaChatbot\Entity\ConversationMessage;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the per-message research metadata.
 */
final class ConversationMessageTest extends TestCase {

  /**
   * Token totals must distinguish "nothing recorded" from "zero tokens".
   *
   * User turns carry no usage at all, and reporting 0 for them would quietly
   * skew any aggregate that averages token counts.
   */
  public function testTotalTokensIsNullWhenNothingWasRecorded(): void {
    $this->assertNull(ConversationMessage::fromUser('Hello')->getTotalTokens());
  }

  public function testTotalTokensSumsWhatIsPresent(): void {
    $message = ConversationMessage::fromAssistant('Reply')
      ->setPromptTokens(28500)
      ->setCompletionTokens(321);

    $this->assertSame(28821, $message->getTotalTokens());

    // A half-recorded response still totals rather than returning null: some
    // providers report only one side.
    $partial = ConversationMessage::fromAssistant('Reply')->setCompletionTokens(49);
    $this->assertSame(49, $partial->getTotalTokens());
  }

  /**
   * Truncation detection has to cover every provider's spelling.
   *
   * This is the flag that answers "was this answer cut off?" after the fact —
   * a truncated reply reads exactly like a complete one, which is how the
   * Gemini maxOutputTokens incident went unnoticed until the logs were read.
   */
  public function testTruncationIsDetectedAcrossProviderSpellings(): void {
    foreach (['MAX_TOKENS', 'max_tokens', 'length'] as $reason) {
      $this->assertTrue(
        ConversationMessage::fromAssistant('Cut off')->setFinishReason($reason)->wasTruncated(),
        "Did not recognise '{$reason}' as truncation."
      );
    }

    foreach (['STOP', 'stop', 'end_turn', null] as $reason) {
      $this->assertFalse(
        ConversationMessage::fromAssistant('Complete')->setFinishReason($reason)->wasTruncated(),
        "Wrongly treated '" . var_export($reason, true) . "' as truncation."
      );
    }
  }

  public function testRoleHelpers(): void {
    $this->assertTrue(ConversationMessage::fromUser('Hi')->isFromUser());
    $this->assertFalse(ConversationMessage::fromAssistant('Hello')->isFromUser());
    $this->assertSame(ConversationMessage::ROLE_ASSISTANT, ConversationMessage::fromAssistant('Hello')->getRole());
  }
}
