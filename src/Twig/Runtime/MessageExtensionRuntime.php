<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Twig\Runtime;

use \Parsedown;
use Twig\Extension\RuntimeExtensionInterface;

class MessageExtensionRuntime implements RuntimeExtensionInterface {

  /**
   * Render a chat message as HTML, escaping any raw HTML it contains.
   *
   * SECURITY: the output of this method is printed through Twig's |raw
   * filter (the bundle's chat and admin transcript templates), so *this method*
   * — not Twig — is the only thing standing between a message and stored
   * XSS. Neither half of a message is trustworthy:
   *
   *   - the user half is typed straight into the chat box;
   *   - the assistant half is whatever an LLM chose to emit, and the user
   *     controls the prompt that produced it.
   *
   * Both halves are persisted to ConversationMessage and replayed on every page
   * load, so an injected <script> or onerror= attribute would fire for the
   * author *and* for any admin reviewing that transcript later.
   *
   * Two Parsedown switches do the work:
   *   - setMarkupEscaped(true): inline HTML in the source is escaped to
   *     &lt;...&gt; text rather than passed through verbatim.
   *   - setSafeMode(true): additionally strips dangerous URL schemes
   *     (javascript:, data:) from links and images and escapes characters
   *     Parsedown would otherwise emit unfiltered.
   *
   * Safe mode is a mitigation, not a guarantee — Parsedown is effectively
   * unmaintained and has a history of bypasses. The durable replacement is
   * league/commonmark configured with html_input: 'escape' and
   * allow_unsafe_links: false.
   */
  public function parseMarkdown($value): string {
    $value = (string) $value;

    // Legacy-content compatibility. Until this was fixed, AppController ran
    // every message through nl2br() *before* persisting it, so existing
    // UserChatHistory rows (and any live session) contain literal "<br />"
    // tags. Now that markup is escaped those would render as visible
    // "<br />" text, so we fold them back into the newlines they came from
    // first. Doing this before escaping is safe: it only ever rewrites this
    // one known-harmless tag, and everything else still gets escaped below.
    $value = preg_replace('/<br\s*\/?>/i', "\n", $value);

    $Parsedown = new Parsedown();
    $Parsedown->setSafeMode(true);
    $Parsedown->setMarkupEscaped(true);
    // Single newlines become <br>, which is the behaviour nl2br() used to
    // give us. Model output leans on in-paragraph line breaks for things
    // like side-effect lists, and dropping them hurts readability.
    $Parsedown->setBreaksEnabled(true);
    $text = $Parsedown->text($value);

    // Normalize all line breaks to spaces to make the regex more effective
    $text = preg_replace('/\s*\n\s*/', ' ', $text);

    // Replace sequences of 3+ <br> tags (even if broken by newlines) with exactly 2
    $text = preg_replace('/(<br\s*\/?>\s*){3,}/i', '<br><br>', $text);

    // Remove any trailing <br> tags before a closing </p>
    $text = preg_replace('/(<br\s*\/?>)+\s*(<\/p>)/i', '$2', $text);

    return $text ?? '';
  }
}
