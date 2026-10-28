<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Interfaces\Services;

use Pixiekat\EmpathicaChatbot\Chatbot\ChatReply;
use Pixiekat\EmpathicaChatbot\Entity\ChatbotPreferences;
use Pixiekat\EmpathicaChatbot\Services\ChatbotMessage;

interface ChatbotManagerInterface {

  public const CHATBOT_MODEL_EMPATHICA = 'EmpathicaAgent';
  public const CHATBOT_MODEL_GEMINI = 'Gemini';

  public const PERSONA_EMILY = 'Emily';
  public const PERSONA_MARY = 'Mary';

  /**
   * Configure the manager for a single request.
   *
   * The ChatbotManager is a DI-managed service, so provider/persona/literacy/
   * empathy are passed here per-request instead of via the constructor.
   * Returns $this for fluent chaining.
   *
   * @param string $chatbot
   *   Provider key as stored in ChatbotPreferences.provider. Resolved through
   *   Pixiekat\EmpathicaChatbot\Chatbot\ProviderRegistry, which handles historical spellings and
   *   falls back to a working provider if the value is unrecognised.
   * @param string|null $persona
   *   Persona name (e.g. 'Emily', 'Mary'); null defers to the default.
   * @param int $literacyLevel
   *   1 (basic) .. 4 (clinical) — see ChatbotMessage::setThread().
   * @param int $empathyLevel
   *   1 (low) .. 5 (confrontational, admin-only) — see ChatbotMessage::setThread().
   * @param ChatbotPreferences|null $preferences
   *   The chatting user's preferences, if there is a user. Supplies their
   *   remembered notes and their debug-mode setting to the system prompt.
   */
  public function configure(
    string $chatbot,
    ?string $persona = null,
    int $literacyLevel = 2,
    int $empathyLevel = 2,
    ?ChatbotPreferences $preferences = null,
  ): static;

  /**
   * The URL the configured provider will be called at.
   *
   * Never contains credentials. Note that for EmpathicaAgent this performs a
   * (cached) Cloud Run lookup rather than returning a constant.
   */
  public function getApiEndpoint(): string;

  /**
   * The canonical name of the configured provider.
   *
   * May differ from what was passed to configure(): historical spellings are
   * normalised, and an unrecognised value falls back to a working provider.
   * Read it back after configure() before storing it against a transcript.
   */
  public function getChatbot(): string;

  /**
   * The prompt being assembled for this request.
   */
  public function getChatbotMessage(): ChatbotMessage;

  /**
   * The model the configured provider will call, or null if it does not take
   * one (EmpathicaAgent chooses its own).
   */
  public function getModel(): ?string;

  /**
   * The request body for the configured provider.
   *
   * @param array|null $extra
   *   Extra keys merged over the generated payload. Merged last, so it can
   *   override — useful for one-off experiments, not for anything permanent,
   *   which belongs in the provider class.
   * @return array<string, mixed>
   */
  public function getPayload(?array $extra = []): array;

  /**
   * Send the exchange and return the answer in a provider-neutral shape.
   *
   * This is the method callers want. Every provider names its response fields
   * differently, and picking through them at the call site is what produced
   * the duplicate parsing ladder this design removed.
   *
   * Exceptions from the HTTP layer propagate — callers should catch and log.
   */
  public function getReply(): ChatReply;

  /**
   * Send the exchange and return the provider's raw decoded body, or null if
   * it was not a JSON object.
   *
   * Kept for debugging and for anything that needs a field ChatReply does not
   * carry. Prefer getReply().
   *
   * @return array<string, mixed>|null
   */
  public function getResponse(): ?array;

  /**
   * Change the provider without rebuilding the prompt.
   *
   * Note this does NOT re-run configure(), so the existing ChatbotMessage
   * keeps whatever provider-specific prompt decisions it was built with.
   */
  public function setChatbot(string $chatbot): void;

}
