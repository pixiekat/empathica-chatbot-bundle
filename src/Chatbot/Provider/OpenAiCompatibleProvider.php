<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Chatbot\Provider;

use Pixiekat\EmpathicaChatbot\Chatbot\ChatReply;
use Pixiekat\EmpathicaChatbot\Chatbot\ChatRequest;
use Pixiekat\EmpathicaChatbot\Interfaces\Services\ChatbotMessageInterface;

/**
 * The `/v1/chat/completions` dialect, shared by OpenAI and Mistral.
 *
 * Mistral implements OpenAI's API surface intentionally, so both take the same
 * request body and return the same response envelope. Expressing that as a
 * shared parent rather than two near-identical classes means a change to the
 * shape — or a bug in reading it — can only be made once.
 *
 * What subclasses still own: the endpoint, the model name, the auth header,
 * and any vendor-specific extras bolted onto the payload.
 */
abstract class OpenAiCompatibleProvider extends AbstractChatProvider {

  /**
   * {@inheritdoc}
   *
   * The system prompt is the first entry in `messages`, tagged with the
   * 'system' role. Both APIs accept that; OpenAI's newer models also accept
   * 'developer', but 'system' remains valid and is what the whole codebase
   * (and ChatbotMessageInterface::SYSTEM) already speaks.
   */
  public function buildPayload(ChatRequest $request): array {
    $messages = $request->thread;

    if ($request->hasSystemInstruction()) {
      array_unshift($messages, [
        'role' => ChatbotMessageInterface::SYSTEM,
        'content' => $request->systemInstruction,
      ]);
    }

    return [
      'model' => $this->getModel(),
      'temperature' => static::TEMPERATURE,
      'max_tokens' => static::MAX_TOKENS,
      'messages' => $messages,
    ];
  }

  /**
   * {@inheritdoc}
   *
   * `choices` is a list because the API can be asked for several completions;
   * we always ask for one (`n => 1` on Mistral, the default on OpenAI), so
   * index 0 is the answer.
   */
  public function parseReply(array $response): ChatReply {
    $content = $response['choices'][0]['message']['content'] ?? null;

    // The metadata is read even when the text is missing. Anything that is not
    // a string means an error body, or a shape the API changed under us — and
    // in exactly that situation finish_reason is the one field that explains
    // why, so throwing it away along with the answer would discard the
    // evidence. The caller decides what a null content means; our job is to
    // report everything the response actually said.
    $usage = $response['usage'] ?? [];
    // An error body may hold a string where a list belongs; parsing one must
    // still produce an empty reply rather than a PHP warning.
    $usage = is_array($usage) ? $usage : [];

    return new ChatReply(
      is_string($content) ? $content : null,
      isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
      isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
      // 'stop' on a normal completion, 'length' when max_tokens was reached.
      isset($response['choices'][0]['finish_reason']) ? (string) $response['choices'][0]['finish_reason'] : null,
    );
  }
}
