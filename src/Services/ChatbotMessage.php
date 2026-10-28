<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Services;

use Pixiekat\EmpathicaChatbot\Entity;
use Pixiekat\EmpathicaChatbot\Interfaces;

class ChatbotMessage implements Interfaces\Services\ChatbotMessageInterface {

  /**
   * The chatbot this prompt is being assembled for.
   *
   * Note what this is NOT used for any more. It used to decide the *shape* of
   * every stored turn — Gemini's `parts` lists, Gemini's 'model' role — which
   * meant one provider's wire format was baked into the thread builder and had
   * to be undone again if the user switched provider mid-conversation. All of
   * that now lives in Pixiekat\EmpathicaChatbot\Chatbot\Provider\GeminiProvider, and this class
   * emits one neutral shape for everybody.
   *
   * The single remaining use is in setThread(), where it decides *which
   * instruction blocks apply* — EmpathicaAgent handles tone itself and must
   * not also receive ours. That is a question about prompt content, not about
   * request format, which is why it belongs here and not in a provider.
   */
  private $_chatbot = null;

  /**
   * The chatting user's saved preferences, if there is a user at all.
   *
   * Only two things are read from it here: whether debug mode is on (which
   * gates announce-levels.md) and the user's remembered notes. Persona and the
   * levels arrive as explicit constructor arguments instead, so CLI scripts and
   * unit tests can assemble a prompt without any entity.
   */
  private ?Entity\ChatbotPreferences $_preferences = null;

  /**
   * The persona talking to the chatbot.
   *
   * @var string
   */
  private $_persona = null;

  /**
   * The supported personas.
   *
   * @var array
   */
  private static $_personas = [
    'Emily',
    'Mary',
  ];

  /**
   * The persona used when none is given, or when the stored one is not
   * recognised. See setPersona().
   */
  private const DEFAULT_PERSONA = 'Emily';

  /**
   * The literacy level.
   */
  private $_literacy_level = 2;

  /**
   * The empathy level.
   */
  private $_empathy_level = 2;

  /**
   * Source of the system-prompt text. See PromptRepository for the file
   * layout and for how prompt versions are derived.
   */
  private PromptRepository $_prompts;

  /**
   * The current thread — the *conversation* only (user turns and model
   * turns). System instructions deliberately do NOT live here; see
   * $_system below.
   *
   * @var array
   */
  private $_thread = [];

  /**
   * The system instruction blocks, in the order they were added.
   *
   * These used to be pushed into $_thread with whatever role getSystemRole()
   * returned — which was 'assistant' for Anthropic and OpenAI. That meant
   * the persona, literacy, empathy and guardrail text was delivered to those
   * two providers as if the model had already said it, rather than as an
   * instruction to follow. Neither API treats it that way:
   *
   *   - Anthropic has no 'system' role in `messages` at all; the system
   *     prompt is a top-level `system` request parameter.
   *   - OpenAI expects role 'system' (or 'developer' on newer models).
   *   - Gemini has a top-level `system_instruction` field.
   *   - Mistral is the only one that took a 'system' role message, which is
   *     why it was the only provider actually receiving instructions.
   *
   * So we collect the blocks here and let ChatbotManager::getPayload() put
   * them wherever the selected provider expects them.
   *
   * @var string[]
   */
  private array $_system = [];

  /**
   * {@inheritdoc}
   */
  public function __construct(string $chatbot, string $persona = 'Emily', int $literacyLevel = 2, int $empathyLevel = 2, ?Entity\ChatbotPreferences $preferences = null, ?PromptRepository $prompts = null) {
    $this->_chatbot = $chatbot;
    $this->_preferences = $preferences;
    // Defaults to a repository pointing at config/prompts/ so this class stays
    // constructable without the container (unit tests, throwaway scripts).
    // In a real request ChatbotManager passes the autowired service through.
    // Must be set before setThread() below, which reads from it.
    $this->_prompts = $prompts ?? new PromptRepository();
    $this->setPersona($persona);
    $this->setLiteracyLevel($literacyLevel);
    $this->setEmpathyLevel($empathyLevel);
    $this->setThread();
  }

  /**
   * {@inheritdoc}
   */
  public function addMessage(string $role, string $message): static {
    $this->_thread[] = [
      'role' => $this->normalizeRole($role),
      'content' => $message,
    ];

    return $this;
  }

  /**
   * Adds a block of system instructions.
   *
   * Blocks are concatenated (in insertion order) by getSystemInstruction()
   * and handed to whichever provider-specific field expects them. Empty or
   * whitespace-only blocks are dropped so we don't send stray blank lines.
   */
  public function addSystemMessage(string $content): static {
    $content = trim($content);
    if ($content !== '') {
      $this->_system[] = $content;
    }
    return $this;
  }

  /**
   * The full system instruction as a single string.
   *
   * Blocks are joined with a blank line between them, which keeps the
   * persona / tone / guardrail sections visually separate in the prompt —
   * that separation measurably helps instruction-following and makes the
   * prompt far easier to eyeball when debugging.
   */
  public function getSystemInstruction(): string {
    return implode("\n\n", $this->_system);
  }

  /**
   * Reduce an arbitrary role string to one of the two canonical ones.
   *
   * The thread only ever holds 'user' and 'assistant'. Providers that call the
   * model's turn something else rename it on the way out — Gemini is the only
   * one that does, and GeminiProvider::buildPayload() handles it.
   *
   * Normalising on the way *in* is what makes stored history portable. Replayed
   * turns come from ConversationMessage rows written by whichever provider was
   * selected at the time, so a conversation started on Gemini and continued on
   * Anthropic can contain 'model' rows that Anthropic rejects outright. Folding
   * them here fixes that without rewriting anything already saved.
   *
   * Anything that is not a user turn is treated as a model turn — system
   * content never reaches this method, it goes through addSystemMessage().
   */
  private function normalizeRole(string $role): string {
    return strtolower(trim($role)) === self::USER ? self::USER : self::ASSISTANT;
  }

  public function setLiteracyLevel(int $literacyLevel): static {
    $this->_literacy_level = $literacyLevel;

    return $this;
  }

  public function setEmpathyLevel(int $empathyLevel): static {
    $this->_empathy_level = $empathyLevel;

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getPersona(): string {
    // Always a valid persona: setPersona() is the only writer and it
    // validates. This used to throw when the value was unrecognised, which
    // meant a single stale User.persona row in the database turned the
    // whole chat page into a 500 — from a getter, several layers below any
    // code equipped to handle it.
    return $this->_persona;
  }

  /**
   * {@inheritdoc}
   */
  public function getThread(): array {
    return $this->normalizeThread($this->_thread);
  }

  /**
   * Make the conversation safe to send to the stricter provider APIs.
   *
   * Anthropic and Gemini both impose rules that our session-backed history
   * can violate:
   *
   *  1. The thread must open on a user turn. History is replayed under a token
   *     budget (see ChatController::HISTORY_TOKEN_BUDGET), which cuts wherever
   *     the budget runs out — quite possibly mid-exchange, leaving a model
   *     reply at the front. We drop those orphans rather than let the request
   *     400.
   *  2. Roles must alternate. Consecutive same-role turns are merged into one,
   *     which also covers the case where a provider returns two content blocks
   *     for what is logically a single reply.
   *
   * OpenAI and Mistral are more forgiving, but normalising for everyone keeps
   * one code path and one set of behaviour to reason about.
   */
  private function normalizeThread(array $thread): array {
    $normalized = [];

    foreach ($thread as $message) {
      // Rule 1: nothing before the first user turn.
      if (empty($normalized) && $message['role'] !== self::USER) {
        continue;
      }

      // Rule 2: fold a repeated role into the turn before it. The blank line
      // matters — without it the two blocks run together into what reads as a
      // single sentence.
      $last = array_key_last($normalized);
      if ($last !== null && $normalized[$last]['role'] === $message['role']) {
        $normalized[$last]['content'] .= "\n\n" . $message['content'];
        continue;
      }

      $normalized[] = $message;
    }

    return $normalized;
  }

  /**
   * {@inheritdoc}
   */
  public function setPersona(string $persona): void {
    // Unrecognised personas fall back to the default rather than being
    // stored and blowing up later. A stale or mistyped value should degrade
    // to a usable chat, not take the page down — the persona list is small,
    // closed, and entirely under our control, so there is nothing a caller
    // could usefully do with an exception here anyway.
    $this->_persona = in_array($persona, self::$_personas, true)
      ? $persona
      : self::DEFAULT_PERSONA;
  }

  /**
   * {@inheritdoc}
   */
  public function setThread(): static {
    $persona = strtolower($this->getPersona());

    // BLOCK 1 — who the assistant is, and who it is talking to.
    // base.md and the persona file are concatenated with no separator, which
    // is exactly how the two PHP string literals used to join.
    $this->addSystemMessage(
      $this->_prompts->get('base') . $this->_prompts->get("persona/{$persona}")
    );

    // BLOCK 2 — tone and rules.
    // EmpathicaAgent is skipped deliberately: it receives literacy and empathy
    // as its own request fields and applies its own guardrails server-side, so
    // sending ours as well would be duplicative and could conflict.
    if ($this->_chatbot <> Interfaces\Services\ChatbotManagerInterface::CHATBOT_MODEL_EMPATHICA) {
      // getWithFallback() reproduces what the old switch statements' `default:`
      // branches did for a level outside the expected range.
      //
      // announce-levels is gated on the user's debug mode. It instructs the
      // model to state the current empathy and literacy levels at the top of
      // every reply, which is invaluable while testing and actively harmful
      // in a study: it tells a participant exactly which experimental arm
      // they are in. Off is the safe default, and it is the default for
      // anyone with no preferences attached (CLI, tests) because of the null check.
      $this->addSystemMessage(
        $this->_prompts->getWithFallback("empathy/{$this->_empathy_level}", 'empathy/default')
        . ' '
        . $this->_prompts->getWithFallback("literacy/{$this->_literacy_level}", 'literacy/default')
        . ($this->_preferences?->isDebugModeEnabled() ? $this->_prompts->get('announce-levels') : '')
        . $this->_prompts->get('guardrails')
      );
    }

    // BLOCK 3 — persona training data.
    // Despite being tagged with role "assistant" in the JSON, every entry in
    // these files is background material — Emily's medical history, her
    // journal, her medication list — not an example of something the model
    // previously said. Feeding 16 consecutive "assistant" turns to Anthropic
    // or Gemini also violates their role-alternation rules outright.
    //
    // So non-user entries become system instructions. A user-role entry (no
    // file has one today) is kept as a genuine conversational turn, which is
    // what a few-shot example would want to be.
    //
    // These stay as JSON rather than moving to config/prompts/ because they
    // are per-persona records with role metadata, not prose blocks — but they
    // are still counted by PromptRepository::getVersion() consumers as part of
    // "the prompt" conceptually. Worth folding in when personas are next
    // revisited.
    foreach (["{$persona}_persona.json"] as $trainingData) {
      $file = __DIR__ . "/../training_data/{$trainingData}";
      if (file_exists($file)) {
        $lines = json_decode(file_get_contents($file), true);
        foreach ($lines as $id => $line) {
          if (($line['role'] ?? null) === self::USER) {
            $this->addMessage(self::USER, $line['content']);
          }
          else {
            $this->addSystemMessage($line['content']);
          }
        }
      }
    }

    // BLOCK 4 — anything the user has told us to remember about themselves.
    // See ChatbotPreferences::getMemory().
    if ($this->_preferences) {
      $chatbotMemory = $this->_preferences->getMemory();
      if (!empty($chatbotMemory)) {
        $this->addSystemMessage(
          $this->_prompts->render('user-memory', ['memory' => $chatbotMemory])
        );
      }
    }

    return $this;
  }
}
