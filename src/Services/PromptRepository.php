<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Services;

/**
 * Loads the system-prompt text from files under config/prompts/.
 *
 * WHY THIS EXISTS
 *
 * All of this text used to be PHP string literals concatenated inside
 * ChatbotMessage::setThread() — the guardrails alone were a single 24KB
 * `return` statement. Two problems with that, one practical and one that
 * matters more given this is a research app:
 *
 *  1. Nobody could review or diff it. A one-word change to the guardrails was
 *     invisible in a git diff full of wrapped PHP string.
 *  2. There was no way to say *which* prompt produced a given transcript. Six
 *     months and a few thousand rows later, "did the empathy manipulation
 *     work?" becomes an exercise in matching commit timestamps against
 *     UserChatHistory.created_at.
 *
 * Files fix the first. getVersion() fixes the second: it returns an
 * identifier that changes whenever any prompt file changes, suitable for
 * stamping onto each stored exchange.
 *
 * FILE LAYOUT
 *
 *   config/prompts/
 *     VERSION               human-readable label, e.g. "2026-08-11 baseline"
 *     base.md               the shared "You are EMPATHICA..." preamble
 *     persona/emily.md      persona-specific context, appended to base
 *     persona/mary.md
 *     empathy/1..5.md       tone blocks, keyed by the User.empathyLevel value
 *     empathy/default.md    used when the level is out of range
 *     literacy/1..4.md      reading-level blocks, keyed by User.literacyLevel
 *     literacy/default.md
 *     announce-levels.md    the "state the levels in every response" rule
 *     guardrails.md         sourcing, safety and privacy rules
 *     user-memory.md        wrapper for ChatbotPreferences.memory; has {{ memory }}
 *     tts/instruction.md    directive wrapped around text sent to the *speech*
 *                           model — NOT part of the chat system prompt
 *
 * Content is deliberately plain text, not Twig. These are prompts, not
 * markup, and keeping the loader dumb means there is no template syntax to
 * accidentally trip over inside 24KB of clinical guidance.
 */
class PromptRepository {

  /**
   * Absolute path to the prompt directory.
   */
  private readonly string $promptDir;

  /**
   * Per-instance memo of file contents, keyed by prompt name.
   *
   * The service is shared within a request, and a request builds at most one
   * thread, so this is enough to avoid re-reading the same file. It is
   * deliberately not a cross-request cache: the files are small, and editing
   * a prompt should take effect immediately rather than after a cache clear.
   *
   * @var array<string, string>
   */
  private array $loaded = [];

  /**
   * Scopes for getVersion(). A prompt belongs to a scope by where it lives:
   * anything under tts/ is speech, everything else is chat. Directory-based
   * rather than a list, so a new prompt file lands in the right bucket without
   * anyone remembering to update a constant.
   */
  public const SCOPE_CHAT = 'chat';
  public const SCOPE_TTS = 'tts';

  /**
   * Memo for the computed version strings, keyed by scope.
   *
   * @var array<string, string>
   */
  private array $version = [];

  /**
   * @param string|null $promptDir
   *   Injected from '%kernel.project_dir%/config/prompts' by the container.
   *   Falls back to a path relative to this file so the class stays usable
   *   in a plain unit test with no container booted.
   */
  public function __construct(?string $promptDir = null) {
    $this->promptDir = rtrim($promptDir ?? __DIR__ . '/../../config/prompts', '/');
  }

  /**
   * Return the contents of a prompt file.
   *
   * @param string $name
   *   Path below config/prompts/ without the .md extension, e.g.
   *   'guardrails' or 'persona/emily'.
   *
   * @throws \RuntimeException
   *   If the prompt is missing. This is deliberately fatal rather than
   *   returning '': a silently empty guardrails block would send an
   *   unguarded medical prompt to a live provider, which is far worse than
   *   an error page.
   */
  public function get(string $name): string {
    if (isset($this->loaded[$name])) {
      return $this->loaded[$name];
    }

    $path = $this->path($name);
    if ($path === null) {
      throw new \RuntimeException(sprintf(
        'Prompt "%s" not found under %s. Prompts are required — refusing to '
        . 'build a system prompt with a missing block.',
        $name,
        $this->promptDir
      ));
    }

    return $this->loaded[$name] = (string) file_get_contents($path);
  }

  /**
   * Return a prompt, or a named fallback if it does not exist.
   *
   * Used for the level-keyed blocks: empathy/4 exists, empathy/97 does not,
   * and the latter should quietly fall back the way the old switch statement's
   * `default:` branch did.
   */
  public function getWithFallback(string $name, string $fallback): string {
    return $this->path($name) !== null ? $this->get($name) : $this->get($fallback);
  }

  /**
   * Return a prompt with {{ placeholders }} substituted.
   *
   * @param array<string, string> $vars
   *   Keys without the braces, e.g. ['memory' => '...'].
   */
  public function render(string $name, array $vars = []): string {
    $text = $this->get($name);
    foreach ($vars as $key => $value) {
      $text = str_replace('{{ ' . $key . ' }}', $value, $text);
    }

    return $text;
  }

  /**
   * An identifier for the current prompt content, for stamping onto stored
   * exchanges so a transcript can be traced back to what produced it.
   *
   * Format: "<label>+<hash>", e.g. "2026-08-11 baseline+3f9a1c4e2b70".
   *
   * The label comes from config/prompts/VERSION and is for humans. The hash is
   * derived from the actual bytes of the prompt files *in the requested
   * scope*, so it changes even when somebody edits a prompt and forgets to
   * bump the label — which is the failure mode a hand-maintained version
   * number always eventually hits.
   *
   * SCOPING MATTERS. This originally hashed every file in the directory, so
   * adding or editing the text-to-speech directive bumped the version stamped
   * on *chat* messages — implying the chat prompt had changed when it had not.
   * For research provenance that is a false positive: it makes two runs look
   * like different conditions when they were identical. Scope defaults to
   * chat, because that is what UserChatHistory and ConversationMessage record.
   */
  public function getVersion(string $scope = self::SCOPE_CHAT): string {
    if (isset($this->version[$scope])) {
      return $this->version[$scope];
    }

    $files = $this->promptFilesInScope($scope);
    // Sorted, so the hash depends only on content and never on the order the
    // filesystem happened to return entries in.
    sort($files);

    $fingerprint = '';
    foreach ($files as $file) {
      $fingerprint .= $file . "\0" . file_get_contents($file) . "\0";
    }

    $label = 'unlabelled';
    $labelFile = $this->promptDir . '/VERSION';
    if (is_file($labelFile)) {
      $label = trim((string) file_get_contents($labelFile)) ?: 'unlabelled';
    }

    return $this->version[$scope] = $label . '+' . substr(hash('sha256', $fingerprint), 0, 12);
  }

  /**
   * The prompt files belonging to a scope.
   *
   * @return string[]
   */
  private function promptFilesInScope(string $scope): array {
    $ttsPrefix = $this->promptDir . '/' . self::SCOPE_TTS . '/';

    return array_values(array_filter(
      $this->allPromptFiles(),
      static fn (string $file): bool => $scope === self::SCOPE_TTS
        ? str_starts_with($file, $ttsPrefix)
        : !str_starts_with($file, $ttsPrefix)
    ));
  }

  /**
   * Absolute path for a prompt name, or null if it does not exist.
   *
   * Rejects any name containing '..' so a caller-supplied value can never
   * walk out of the prompt directory.
   */
  private function path(string $name): ?string {
    if (str_contains($name, '..')) {
      return null;
    }

    $path = $this->promptDir . '/' . $name . '.md';

    return is_file($path) ? $path : null;
  }

  /**
   * Every .md file in the prompt directory, one level deep plus the
   * persona/empathy/literacy subdirectories.
   *
   * @return string[]
   */
  private function allPromptFiles(): array {
    $found = glob($this->promptDir . '/{*.md,*/*.md}', GLOB_BRACE);

    return $found === false ? [] : $found;
  }
}
