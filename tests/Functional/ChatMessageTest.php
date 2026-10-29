<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Tests\Functional;

use Pixiekat\EmpathicaChatbot\Entity\Conversation;
use Pixiekat\EmpathicaChatbot\Form\ChatbotInputType;
use Pixiekat\SymfonyHelpers\Interfaces\Entity\HelpersUserInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sending a message, through the real form, with the AI provider mocked to
 * fail (see ChatbotWebTestCase). That makes these tests about the chat's own
 * behaviour, which is what can regress here; parsing each provider's replies
 * is covered by the unit tests in tests/Chatbot.
 */
final class ChatMessageTest extends ChatbotWebTestCase {

  /**
   * Submit the chat form exactly as a browser would, CSRF token included.
   */
  private function send(string $message): void {
    $crawler = $this->client->request('GET', $this->url('empathica_chatbot_index'));
    $form = $crawler->filter('form[name="chatbot_input"]')->form();
    $this->client->submit($form, ['chatbot_input[message]' => $message]);
  }

  /**
   * @return list<Conversation>
   */
  private function conversationsOf(HelpersUserInterface $user): array {
    $this->entityManager()->clear();
    return $this->entityManager()->getRepository(Conversation::class)->findBy(['user' => $user->getId()]);
  }

  /**
   * The provider is down. The participant is told plainly, and their
   * question is still saved: a question that got no answer is part of the
   * study record, and losing it would hide the failure.
   */
  public function testWhenTheProviderFailsTheQuestionIsStillSaved(): void {
    $user = $this->createUser();
    $this->loginAs($user);

    $this->send('Is it safe to take paracetamol with my tablets?');

    $this->assertResponseRedirects($this->url('empathica_chatbot_index'));
    $this->client->followRedirect();
    $this->assertSelectorTextContains('body', 'temporarily unavailable');

    $conversations = $this->conversationsOf($user);
    $this->assertCount(1, $conversations);
    $messages = $conversations[0]->getMessages();
    $this->assertCount(1, $messages, 'Only the question; no invented answer.');
    $this->assertSame('Is it safe to take paracetamol with my tablets?', $messages->first()->getContent());
  }

  /**
   * Over the limit: rejected with 422 (which Turbo will render), nothing
   * saved, nothing sent to the provider.
   */
  public function testOverlongMessageIsRejectedBeforeAnythingIsSent(): void {
    $user = $this->createUser();
    $this->loginAs($user);

    $this->send(str_repeat('a', ChatbotInputType::MESSAGE_MAX_LENGTH + 1));

    $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    $this->assertSame([], $this->conversationsOf($user));
    $this->assertSame(0, $this->providerCalls);
  }

  /**
   * The chat_message limiter (12 a minute by default). Once it's used up,
   * the next message gets 429 and isn't saved, because nothing was sent
   * for it.
   *
   * The test sends until it's throttled rather than hard-coding 12, so it
   * still holds when a host configures a different limit. The cap of 100
   * only stops a broken limiter from looping forever.
   *
   * Users are unique per test, and the limiter is keyed on the user, so
   * earlier runs of this test can't use up this run's allowance.
   */
  public function testRapidMessagesAreThrottled(): void {
    $user = $this->createUser();
    $this->loginAs($user);

    $throttled = false;
    for ($i = 1; $i <= 100 && !$throttled; $i++) {
      $this->send("Question number $i");
      $status = $this->client->getResponse()->getStatusCode();
      $throttled = $status === Response::HTTP_TOO_MANY_REQUESTS;
      if (!$throttled) {
        $this->assertResponseRedirects(message: "Message $i should be accepted until the limit is reached.");
      }
    }

    $this->assertTrue($throttled, 'No 429 after 100 messages: the chat_message limiter is not applied.');
    $this->assertSelectorTextContains('body', 'very quickly');

    $rejected = 'Question number ' . ($i - 1);
    $saved = $this->conversationsOf($user)[0]->getMessages()->map(static fn ($m) => $m->getContent())->toArray();
    $this->assertNotContains($rejected, $saved, 'The throttled message must not be stored.');
  }
}
