<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Interfaces\Services;

interface ChatbotMessageInterface {

  /**
   * The message role for the user of the chatbot.
   */
  const USER = "user";

  /**
   * The message role for the assistant of the chatbot.
   */
  const ASSISTANT = "assistant";

  /**
   * The message role for system instructions.
   */
  const SYSTEM = "system";

  /**
   * The message role for tools.
   */
  const TOOL = "tool";

  /**
   * The message role for the model.
   */
  const MODEL = 'model';

  /**
   * The message role for the result of a message.
   */
  const RESULT = "result";

  /**
   * Adds a message to the thread.
   *
   * @param string $role
   *  The role of the message.
   * @param string $message
   *  The message to add.
   * @return static
   */
  public function addMessage(string $role, string $message): static;

  /**
   * Adds a block of system instructions.
   *
   * System content is kept out of the conversation thread because each
   * provider expects it somewhere different — a top-level `system` param
   * (Anthropic), a `system_instruction` field (Gemini), or the first
   * message with role 'system' (OpenAI, Mistral). ChatbotManager::
   * getPayload() decides which.
   *
   * @param string $content
   *  The instruction text. Empty/whitespace-only content is ignored.
   * @return static
   */
  public function addSystemMessage(string $content): static;

  /**
   * Gets the current persona assigned to this message.
   */
  public function getPersona(): string ;

  /**
   * Gets the accumulated system instruction as a single string.
   *
   * @return string
   *  The blocks added via addSystemMessage(), joined by a blank line.
   *  Empty string if none were added.
   */
  public function getSystemInstruction(): string;

  /**
   * Gets the current conversation thread (user and model turns only).
   *
   * The returned thread is normalised for the selected provider: it always
   * opens on a user turn and never repeats a role, both of which Anthropic
   * and Gemini reject.
   *
   * @return array
   */
  public function getThread(): array;

  /**
   * Sets the current persona for this message.
   *
   * @param string $persona
   * The persona to set.
   * @return void
   */
  public function setPersona(string $persona): void;

  /**
   * Initialises the thread and adds initial training data.
   *
   * @return static
   */
  public function setThread(): static;
}
