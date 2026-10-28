<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Chatbot;

use Pixiekat\EmpathicaChatbot\Interfaces\Services\ChatbotManagerInterface;

/**
 * Everything a provider needs in order to build a request body.
 *
 * This is the seam between "what we want to say" and "how this particular API
 * wants to hear it". ChatbotMessage assembles the first half — the system
 * instruction and the conversation — in one neutral shape, and each
 * ChatProviderInterface implementation turns that into its own wire format.
 *
 * WHY A DTO RATHER THAN PASSING ChatbotMessage AROUND
 *
 * ChatbotMessage is mutable and knows how to *build* a prompt (it reads files,
 * resolves personas, decides which blocks apply). A provider has no business
 * with any of that; it needs the finished text and nothing else. Handing it a
 * frozen value object instead of the builder keeps that boundary honest, and
 * makes a provider trivially testable — you can construct one of these in a
 * line without touching config/prompts/ at all.
 *
 * WHY THE LEVELS ARE HERE
 *
 * Only EmpathicaAgent reads them: it applies literacy and empathy server-side
 * and wants them as request fields rather than as prompt text. Every other
 * provider gets them baked into $systemInstruction and ignores these.
 *
 * They used to be read back out of the Symfony Security service inside
 * ChatbotManager, which meant the payload silently depended on there being a
 * logged-in user — and quietly fell back to "medium" in any context without
 * one (CLI, tests). Passing them explicitly removes that hidden input.
 */
final class ChatRequest {

  /**
   * @param string $systemInstruction
   *   The full system prompt as one string. Empty when there is none — which
   *   providers must handle, because sending an empty system field is not the
   *   same as omitting it.
   * @param list<array{role: string, content: string}> $thread
   *   The conversation, oldest first, already normalised: it opens on a user
   *   turn and never repeats a role. Roles are the canonical 'user' and
   *   'assistant'; a provider that calls the model's turn something else
   *   (Gemini says 'model') renames it on the way out.
   * @param string $persona
   *   The simulated patient being spoken to, e.g. 'Emily'.
   * @param int $literacyLevel
   *   1 (basic) .. 4 (clinical). See PreferencesType for the labels.
   * @param int $empathyLevel
   *   1 (low) .. 5 (confrontational; admin-only). See PreferencesType.
   */
  public function __construct(
    public readonly string $systemInstruction,
    public readonly array $thread,
    public readonly string $persona = ChatbotManagerInterface::PERSONA_EMILY,
    public readonly int $literacyLevel = 2,
    public readonly int $empathyLevel = 2,
  ) {
  }

  /**
   * Whether there is a system instruction worth sending.
   *
   * Providers check this rather than testing the string themselves, so that
   * "what counts as empty" is decided in exactly one place.
   */
  public function hasSystemInstruction(): bool {
    return $this->systemInstruction !== '';
  }
}
