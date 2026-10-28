<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Interfaces\Services;

use Pixiekat\EmpathicaChatbot\Chatbot\ChatReply;
use Pixiekat\EmpathicaChatbot\Chatbot\ChatRequest;

/**
 * One LLM provider: where to send a request, how to shape it, how to read the
 * answer back.
 *
 * WHAT THIS REPLACED
 *
 * Provider differences used to live in parallel `switch` statements spread
 * across two files — endpoint, key, model, payload and headers in
 * ChatbotManager, and the response shape in AppController::extractReply().
 * Adding a provider meant finding all six and remembering the sixth. Missing
 * one did not throw; it produced a plausible-looking but wrong request, which
 * is the hardest kind of bug to notice in a chat UI.
 *
 * Now each provider is one class, and the compiler tells you what you have not
 * implemented.
 *
 * WHAT DELIBERATELY IS *NOT* HERE
 *
 * Sending the request. Implementations describe a request; ChatbotManager
 * performs it. Keeping the HTTP call in one place means timeouts, logging and
 * secret redaction are written once and cannot drift between providers — and
 * it is why every method below can be tested without a network.
 *
 * The one exception is service discovery: EmpathicaAgent has no fixed URL and
 * has to ask Cloud Run for it, so its getEndpoint() does make a call. That is
 * documented on the method rather than pretended away.
 */
interface ChatProviderInterface {

  /**
   * The canonical provider key, as stored in ChatbotPreferences.provider.
   *
   * This is also the registry key — see Pixiekat\EmpathicaChatbot\Chatbot\ProviderRegistry, which
   * indexes providers by this value. It must match the values offered by
   * PreferencesType and UserFormType, and the CHATBOT_MODEL_* constants on
   * ChatbotManagerInterface where one exists.
   */
  public function getName(): string;

  /**
   * The specific model to call, or null for providers that do not take one.
   *
   * EmpathicaAgent returns null: the model is chosen by the agent service
   * itself, so there is nothing for us to name.
   */
  public function getModel(): ?string;

  /**
   * The absolute URL to POST to.
   *
   * Must never contain credentials. Gemini's API in particular accepts its key
   * as a `?key=` query parameter and that form is banned here, because the
   * endpoint is logged and appears in exception messages — the key goes in the
   * `x-goog-api-key` header instead.
   *
   * @throws \Pixiekat\EmpathicaChatbot\Chatbot\Exception\ProviderNotConfiguredException
   *   When the provider has no API key configured. Failing here, before any
   *   request is built, gives a log line that names the missing environment
   *   variable instead of an opaque 401 from upstream.
   * @throws \RuntimeException
   *   For providers that discover their endpoint at runtime, if discovery
   *   fails. Only EmpathicaAgent does this.
   */
  public function getEndpoint(): string;

  /**
   * Request headers, including whatever this provider calls authentication.
   *
   * All four spell it differently: `x-api-key` plus a version header
   * (Anthropic), `Authorization: Bearer` (OpenAI, Mistral), `x-goog-api-key`
   * (Gemini), or a Google-signed OIDC identity token (EmpathicaAgent).
   *
   * The return value contains live secrets and must never be logged.
   *
   * @return array<string, string>
   */
  public function getHeaders(): array;

  /**
   * Turn the neutral request into this provider's JSON body.
   *
   * The interesting part is where the system instruction goes, and no two
   * agree: a top-level `system` parameter (Anthropic), a `system_instruction`
   * field (Gemini), a leading message with role 'system' (OpenAI, Mistral), or
   * folded into one flat string (EmpathicaAgent). Getting this wrong does not
   * error — the model simply stops following its instructions.
   *
   * @return array<string, mixed>
   */
  public function buildPayload(ChatRequest $request): array;

  /**
   * Pull the answer and its metadata out of a decoded response body.
   *
   * Implementations must be total: any array at all is valid input, including
   * an error body or something unrecognisable. Return ChatReply::none()
   * rather than throwing — the caller already treats an empty reply as a
   * user-visible failure, and an exception here would lose the user's own
   * message along with it.
   *
   * @param array<string, mixed> $response
   */
  public function parseReply(array $response): ChatReply;
}
