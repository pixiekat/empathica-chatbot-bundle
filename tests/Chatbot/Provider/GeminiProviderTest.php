<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Tests\Chatbot\Provider;

use Pixiekat\EmpathicaChatbot\Chatbot\ChatRequest;
use Pixiekat\EmpathicaChatbot\Chatbot\Provider\GeminiProvider;
use Pixiekat\EmpathicaChatbot\Interfaces\Services\ChatbotMessageInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Gemini's wire format.
 *
 * These moved here from ChatbotMessageTest along with the code they cover.
 * ChatbotMessage used to build Gemini's shape itself, which meant one API's
 * quirks were baked into the shared thread builder; now it emits one neutral
 * shape and GeminiProvider translates on the way out. The assertions are the
 * same ones — they just point at the class that owns the behaviour.
 */
final class GeminiProviderTest extends TestCase {

  private function payloadFor(array $thread, string $system = ''): array {
    return (new GeminiProvider())->buildPayload(new ChatRequest($system, $thread));
  }

  /**
   * `parts` must encode as a JSON array, not a JSON object.
   *
   * Asserting on the encoded JSON rather than the PHP array is deliberate:
   * ['text' => ...] and [['text' => ...]] are both "arrays" in PHP, and only
   * the wire format tells them apart. The first is what this app used to send,
   * and what the v1beta generateContent endpoint rejects outright.
   */
  public function testPartsEncodeAsAList(): void {
    $payload = $this->payloadFor([
      ['role' => ChatbotMessageInterface::USER, 'content' => 'Hi'],
    ]);

    $this->assertSame('[{"text":"Hi"}]', json_encode($payload['contents'][0]['parts']));
  }

  /**
   * Gemini calls the model's turn 'model'; the thread calls it 'assistant'.
   *
   * The rename happens here, at the last possible moment, so that stored
   * history stays portable between providers.
   */
  public function testTheAssistantRoleIsRenamedToModel(): void {
    $payload = $this->payloadFor([
      ['role' => ChatbotMessageInterface::USER, 'content' => 'Hi'],
      ['role' => ChatbotMessageInterface::ASSISTANT, 'content' => 'Hello Emily.'],
    ]);

    $this->assertSame(
      [ChatbotMessageInterface::USER, ChatbotMessageInterface::MODEL],
      array_column($payload['contents'], 'role')
    );
  }

  /**
   * `contents` must encode as a JSON array too.
   *
   * array_map() over a list preserves list keys, but a future filter or unset
   * in this path would silently produce {"0":{...}} instead — the same class
   * of bug as the parts one above, one level up.
   */
  public function testContentsEncodeAsAList(): void {
    $payload = $this->payloadFor([
      ['role' => ChatbotMessageInterface::USER, 'content' => 'Hi'],
    ]);

    $this->assertStringStartsWith('[{', json_encode($payload['contents']));
  }

  /**
   * No system_instruction key at all when there is nothing to put in it.
   *
   * Sending an empty system field is not the same as omitting it, and an empty
   * parts list is the kind of thing an API rejects on a Friday afternoon.
   */
  public function testAnEmptySystemInstructionIsOmittedEntirely(): void {
    $payload = $this->payloadFor([
      ['role' => ChatbotMessageInterface::USER, 'content' => 'Hi'],
    ]);

    $this->assertArrayNotHasKey('system_instruction', $payload);
  }

  /**
   * The API key must never appear in the URL.
   *
   * Gemini accepts a ?key= query parameter and this app used to use it, which
   * put a live credential in every logged endpoint and in the text of any
   * exception raised against it. The key belongs in the x-goog-api-key header.
   */
  public function testTheApiKeyIsNotInTheEndpoint(): void {
    $endpoint = (new GeminiProvider('sk-not-a-real-key'))->getEndpoint();

    $this->assertStringNotContainsString('sk-not-a-real-key', $endpoint);
    $this->assertStringNotContainsString('key=', $endpoint);
    $this->assertSame('sk-not-a-real-key', (new GeminiProvider('sk-not-a-real-key'))->getHeaders()['x-goog-api-key']);
  }

  /**
   * A missing key fails by name, before anything is sent.
   *
   * The old behaviour was to return an empty-string endpoint, which surfaced
   * as an inscrutable HTTP client error about a malformed URI.
   */
  public function testAMissingKeyFailsWithAUsefulMessage(): void {
    $this->expectExceptionMessage('GEMINI_API_KEY');

    (new GeminiProvider(''))->getEndpoint();
  }
}
