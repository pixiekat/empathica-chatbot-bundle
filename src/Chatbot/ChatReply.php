<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Chatbot;

/**
 * A provider's answer, normalised.
 *
 * Four APIs, four completely different response shapes, and four different
 * names for the same two token counts. This is what they all get flattened
 * into so that ChatController can store a turn without knowing or caring which
 * provider produced it.
 *
 * Every field is nullable on purpose. A provider that does not report token
 * usage (EmpathicaAgent does not) should record *nothing* rather than zero:
 * a stored 0 is indistinguishable from "this request genuinely used no
 * tokens", and in a research dataset that difference matters. Null reads
 * honestly as "not measured".
 */
final class ChatReply {

  /**
   * @param string|null $content
   *   The assistant's text, or null if the response could not be understood.
   * @param int|null $promptTokens
   *   Total input tokens, however the provider spells it. For Anthropic with
   *   prompt caching on, this is the *sum* of the cached and uncached parts —
   *   see AnthropicProvider::parseReply() for why that matters.
   * @param int|null $completionTokens
   *   Tokens of generated answer.
   * @param string|null $finishReason
   *   Why generation stopped, in the provider's own vocabulary ('stop',
   *   'end_turn', 'MAX_TOKENS', ...). Deliberately not normalised: the raw
   *   value is the diagnostic, and ConversationMessage::wasTruncated()
   *   already knows the handful of strings that mean "cut off".
   */
  public function __construct(
    public readonly ?string $content = null,
    public readonly ?int $promptTokens = null,
    public readonly ?int $completionTokens = null,
    public readonly ?string $finishReason = null,
  ) {
  }

  /**
   * The "we got nothing usable" reply.
   *
   * Returned when the provider gave us an empty body, or a body in a shape we
   * do not recognise. Named rather than open-coded so the intent is legible at
   * the call site, and so all four providers agree on what failure looks like.
   */
  public static function none(): self {
    return new self();
  }

  /**
   * Whether this reply can actually be shown to a user.
   *
   * Whitespace counts as empty: a provider that returns " " has failed just as
   * completely as one that returns nothing, and storing it would put a blank
   * assistant turn in the transcript.
   */
  public function isEmpty(): bool {
    return $this->content === null || trim($this->content) === '';
  }
}
