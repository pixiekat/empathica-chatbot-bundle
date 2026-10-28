<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Tests\Services;

use Pixiekat\EmpathicaChatbot\Entity\ChatbotPreferences;
use Pixiekat\EmpathicaChatbot\Interfaces\Services\ChatbotManagerInterface;
use Pixiekat\EmpathicaChatbot\Interfaces\Services\ChatbotMessageInterface;
use Pixiekat\EmpathicaChatbot\Services\ChatbotMessage;
use Pixiekat\SymfonyHelpers\Interfaces\Entity\HelpersUserInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the thread builder.
 *
 * ChatbotMessage is the best possible first test target in this codebase: it
 * has no database, no HTTP, and no container, so it is a plain TestCase with
 * no kernel boot — but it now carries the logic that decides what each
 * provider actually receives. Every assertion below corresponds to a bug that
 * was live in production:
 *
 *   - the system prompt was delivered to Anthropic and OpenAI as an
 *     "assistant" turn, so it read as the model's own prior output rather
 *     than as instructions;
 *   - Gemini's `parts` was built as an object instead of a list, which the
 *     v1beta endpoint rejects;
 *   - a session recorded against one provider replayed roles the next
 *     provider would not accept;
 *   - AppController's array_slice() trim could hand Anthropic a thread that
 *     opened on an assistant turn, which it refuses.
 *
 * If you extend this file, keep it free of I/O — the value here is that it
 * runs in milliseconds and can be run on every save.
 */
final class ChatbotMessageTest extends TestCase {

  /**
   * Build a message object with a conversation already in it.
   */
  private function withConversation(string $provider, array $turns = []): ChatbotMessage {
    $message = new ChatbotMessage($provider, ChatbotManagerInterface::PERSONA_EMILY, 2, 2);
    foreach ($turns as [$role, $text]) {
      $message->addMessage($role, $text);
    }

    return $message;
  }

  /**
   * System instructions must not leak into the conversation thread.
   *
   * This is the core of the fix: the persona, empathy, literacy and guardrail
   * blocks belong in a provider-specific system field, so getThread() should
   * contain only what the user and the model actually said.
   */
  public function testSystemContentIsKeptOutOfTheThread(): void {
    $message = $this->withConversation('Anthropic', [
      [ChatbotMessageInterface::USER, 'What are the side effects?'],
    ]);

    $thread = $message->getThread();

    $this->assertCount(1, $thread, 'Only the user turn belongs in the thread.');
    $this->assertSame(ChatbotMessageInterface::USER, $thread[0]['role']);
    $this->assertSame('What are the side effects?', $thread[0]['content']);

    // The persona text still has to reach the model — just somewhere else.
    $this->assertStringContainsString('Emily', $message->getSystemInstruction());
    $this->assertStringContainsString('EMPATHICA', $message->getSystemInstruction());
  }

  /**
   * No thread entry may ever carry the 'system' role.
   *
   * Anthropic rejects it outright, and for the others it silently changes
   * meaning. Belt-and-braces check across every provider.
   */
  public function testNoProviderEverGetsASystemRoleInTheThread(): void {
    foreach (['Anthropic', 'OpenAI', 'Mistral', ChatbotManagerInterface::CHATBOT_MODEL_GEMINI] as $provider) {
      $message = $this->withConversation($provider, [
        [ChatbotMessageInterface::USER, 'Hello'],
      ]);

      foreach ($message->getThread() as $turn) {
        $this->assertNotSame(
          ChatbotMessageInterface::SYSTEM,
          $turn['role'],
          "{$provider} received a system-role message inside the thread."
        );
      }
    }
  }

  /**
   * The thread speaks one vocabulary: 'user' and 'assistant', for everybody.
   *
   * Gemini calls the model's turn 'model', but that rename now happens in
   * GeminiProvider on the way out — see GeminiProviderTest. What matters here
   * is that whatever comes *in* is folded to the canonical pair, because
   * replayed history carries whatever role the provider of the day used. A
   * conversation started on Gemini and continued on Anthropic contains 'model'
   * rows, and Anthropic rejects those outright.
   */
  public function testModelRolesAreCanonicalisedWhateverTheyArrivedAs(): void {
    foreach (['Anthropic', ChatbotManagerInterface::CHATBOT_MODEL_GEMINI] as $provider) {
      foreach ([ChatbotMessageInterface::MODEL, ChatbotMessageInterface::ASSISTANT] as $incoming) {
        $message = $this->withConversation($provider, [
          [ChatbotMessageInterface::USER, 'Hi'],
          [$incoming, 'Hello Emily.'],
        ]);

        $this->assertSame(
          ChatbotMessageInterface::ASSISTANT,
          $message->getThread()[1]['role'],
          "{$provider} kept the raw '{$incoming}' role instead of canonicalising it."
        );
      }
    }
  }

  /**
   * Every provider gets the same neutral turn shape.
   *
   * ChatbotMessage used to emit Gemini's `parts` structure when the selected
   * provider was Gemini, which meant one API's wire format was baked into the
   * thread builder — and had to be undone again the moment a user switched
   * provider mid-conversation. Shaping is a provider's job now, so a turn is
   * always role plus content and nothing else.
   */
  public function testTheThreadShapeDoesNotVaryByProvider(): void {
    foreach (['Anthropic', 'OpenAI', 'Mistral', ChatbotManagerInterface::CHATBOT_MODEL_GEMINI] as $provider) {
      $thread = $this->withConversation($provider, [
        [ChatbotMessageInterface::USER, 'Hi'],
      ])->getThread();

      $this->assertSame(
        ['role', 'content'],
        array_keys($thread[0]),
        "{$provider} produced a provider-specific turn shape."
      );
    }
  }

  /**
   * A thread must open on a user turn.
   *
   * AppController keeps the last N messages with array_slice(), which can cut
   * mid-exchange and leave a model reply first. Anthropic and Gemini both
   * reject that, so the orphan is dropped.
   */
  public function testLeadingModelTurnsAreDropped(): void {
    $message = $this->withConversation('Anthropic', [
      [ChatbotMessageInterface::ASSISTANT, 'Orphaned reply from a sliced history.'],
      [ChatbotMessageInterface::USER, 'And with food?'],
    ]);

    $thread = $message->getThread();

    $this->assertCount(1, $thread);
    $this->assertSame(ChatbotMessageInterface::USER, $thread[0]['role']);
  }

  /**
   * Roles must alternate, so consecutive same-role turns are merged.
   */
  public function testConsecutiveSameRoleTurnsAreMerged(): void {
    $message = $this->withConversation('Anthropic', [
      [ChatbotMessageInterface::USER, 'First question.'],
      [ChatbotMessageInterface::ASSISTANT, 'Part one.'],
      [ChatbotMessageInterface::ASSISTANT, 'Part two.'],
      [ChatbotMessageInterface::USER, 'Follow up.'],
    ]);

    $thread = $message->getThread();

    $this->assertSame(
      [ChatbotMessageInterface::USER, ChatbotMessageInterface::ASSISTANT, ChatbotMessageInterface::USER],
      array_column($thread, 'role')
    );
    $this->assertSame("Part one.\n\nPart two.", $thread[1]['content']);
  }

  /**
   * Merging works the same way for Gemini as for everyone else.
   *
   * It used to append `parts` rather than concatenate strings, on the grounds
   * that a Gemini turn may hold several parts. True, but it bought nothing —
   * Gemini reads the parts of a turn as one continuous message either way —
   * and it was the reason the merge logic had a provider branch in it at all.
   */
  public function testMergingIsProviderIndependent(): void {
    $message = $this->withConversation(ChatbotManagerInterface::CHATBOT_MODEL_GEMINI, [
      [ChatbotMessageInterface::USER, 'First question.'],
      [ChatbotMessageInterface::MODEL, 'Part one.'],
      [ChatbotMessageInterface::MODEL, 'Part two.'],
    ]);

    $this->assertSame("Part one.\n\nPart two.", $message->getThread()[1]['content']);
  }

  /**
   * An unrecognised persona falls back to the default instead of throwing.
   *
   * A single stale User.persona row used to turn the whole chat page into a
   * 500, raised from a getter several layers below anything able to handle it.
   */
  public function testUnknownPersonaFallsBackToTheDefault(): void {
    $message = new ChatbotMessage('Anthropic', 'Nonexistent Person', 2, 2);

    $this->assertSame(ChatbotManagerInterface::PERSONA_EMILY, $message->getPersona());
  }

  /**
   * The empathy and literacy settings must actually reach the prompt —
   * otherwise the experimental manipulation silently does nothing.
   */
  public function testEmpathyAndLiteracyLevelsReachTheSystemInstruction(): void {
    $low = new ChatbotMessage('Anthropic', ChatbotManagerInterface::PERSONA_EMILY, 1, 1);
    $high = new ChatbotMessage('Anthropic', ChatbotManagerInterface::PERSONA_EMILY, 3, 3);

    $this->assertStringContainsString('Empathy level is low', $low->getSystemInstruction());
    $this->assertStringContainsString('Literacy level is basic', $low->getSystemInstruction());

    $this->assertStringContainsString('Empathy level is high', $high->getSystemInstruction());
    $this->assertStringContainsString('Literacy level is advanced', $high->getSystemInstruction());
  }

  /**
   * The levels readout must stay off unless debug mode is explicitly on.
   *
   * announce-levels tells the model to state the current empathy and literacy
   * levels at the top of every reply. That is a testing aid: in a study it
   * tells the participant which experimental arm they are in, which defeats
   * blinding. The default has to be off, including when there are no
   * preferences attached at all.
   */
  public function testLevelsReadoutIsOffUnlessDebugModeIsEnabled(): void {
    $marker = 'Always state the current empathy levels';

    $noUser = new ChatbotMessage('Anthropic', ChatbotManagerInterface::PERSONA_EMILY, 2, 2);
    $this->assertStringNotContainsString($marker, $noUser->getSystemInstruction());

    $off = new ChatbotMessage('Anthropic', ChatbotManagerInterface::PERSONA_EMILY, 2, 2, $this->preferences());
    $this->assertStringNotContainsString($marker, $off->getSystemInstruction());
  }

  /**
   * ...and on when it is, or the readout would be unusable for testing.
   */
  public function testLevelsReadoutAppearsInDebugMode(): void {
    $preferences = $this->preferences()->setDebugModeEnabled(true);
    $debug = new ChatbotMessage('Anthropic', ChatbotManagerInterface::PERSONA_EMILY, 2, 2, $preferences);

    $this->assertStringContainsString(
      'Always state the current empathy levels',
      $debug->getSystemInstruction()
    );
  }

  /**
   * EmpathicaAgent handles tone and literacy itself, so the app must not also
   * push its own empathy/literacy/guardrail blocks at it. It should still get
   * the persona context.
   */
  public function testEmpathicaAgentSkipsTheToneBlocks(): void {
    $message = new ChatbotMessage(
      ChatbotManagerInterface::CHATBOT_MODEL_EMPATHICA,
      ChatbotManagerInterface::PERSONA_EMILY,
      1,
      1
    );

    $system = $message->getSystemInstruction();

    $this->assertStringNotContainsString('Empathy level is low', $system);
    $this->assertStringContainsString('Emily', $system);
  }

  /**
   * Fresh, unsaved preferences for a stand-in user.
   *
   * ChatbotPreferences only needs *something* implementing
   * HelpersUserInterface as its owner — which is the whole point of the
   * interface — so a stub is enough, and no host User class is involved.
   */
  private function preferences(): ChatbotPreferences {
    return new ChatbotPreferences($this->createStub(HelpersUserInterface::class));
  }
}
