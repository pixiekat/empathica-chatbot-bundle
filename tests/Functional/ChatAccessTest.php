<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Pixiekat\EmpathicaChatbot\Entity\ConversationMessage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who can reach what, for participants. See ChatbotWebTestCase for set-up.
 *
 * The headline test is the second one: a conversation is a health
 * transcript, so one participant must never be able to open another's, even
 * by guessing ids in the address bar.
 */
final class ChatAccessTest extends ChatbotWebTestCase {

  /**
   * @return iterable<string, array{string}>
   */
  public static function participantRouteProvider(): iterable {
    yield 'chat' => ['empathica_chatbot_index'];
    yield 'history' => ['empathica_chatbot_history'];
    yield 'settings' => ['empathica_chatbot_preferences'];
  }

  /**
   * Logged out: sent to the host's login page, not shown an error.
   */
  #[DataProvider('participantRouteProvider')]
  public function testAnonymousVisitorIsSentToLogin(string $route): void {
    $this->client->request('GET', $this->url($route));

    $this->assertResponseRedirects();
    $this->assertStringContainsString('login', (string) $this->client->getResponse()->headers->get('Location'));
  }

  #[DataProvider('participantRouteProvider')]
  public function testParticipantCanOpenTheirOwnPages(string $route): void {
    $this->loginAs($this->createUser());
    $this->client->request('GET', $this->url($route));

    $this->assertResponseIsSuccessful();
  }

  /**
   * 404, not 403: "that conversation exists but isn't yours" would itself
   * tell one participant something about another.
   */
  public function testParticipantCannotOpenSomeoneElsesConversation(): void {
    $alice = $this->createUser();
    $bob = $this->createUser();
    $bobsConversation = $this->createConversation($bob, 'A private question from Bob');

    $this->loginAs($alice);
    $this->client->request('GET', $this->url('empathica_chatbot_conversation', ['id' => $bobsConversation->getId()]));

    $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
  }

  /**
   * And nothing of Bob's leaks into Alice's own pages either.
   */
  public function testSomeoneElsesConversationNeverAppearsInYourLists(): void {
    $alice = $this->createUser();
    $bob = $this->createUser();
    $this->createConversation($bob, 'A private question from Bob');

    $this->loginAs($alice);
    foreach (['empathica_chatbot_index', 'empathica_chatbot_history'] as $route) {
      $this->client->request('GET', $this->url($route));
      $this->assertResponseIsSuccessful();
      $this->assertStringNotContainsString('A private question from Bob', (string) $this->client->getResponse()->getContent(), $route);
    }
  }

  public function testOpeningYourOwnConversationShowsIt(): void {
    $alice = $this->createUser();
    $conversation = $this->createConversation($alice, 'Can I take this with grapefruit?');

    $this->loginAs($alice);
    $this->client->request('GET', $this->url('empathica_chatbot_conversation', ['id' => $conversation->getId()]));
    $this->assertResponseRedirects($this->url('empathica_chatbot_index'));

    $this->client->followRedirect();
    $this->assertSelectorTextContains('.conversations', 'Can I take this with grapefruit?');
  }

  /**
   * "New conversation" is a POST with a CSRF token. Without the token (a
   * forged form on another site) it must do nothing.
   */
  public function testNewConversationRequiresItsCsrfToken(): void {
    $this->loginAs($this->createUser());
    $this->client->request('POST', $this->url('empathica_chatbot_conversation_new'));

    $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
  }

  /**
   * Read-aloud for someone else's message gets nothing. 204, the same answer
   * as "read-aloud is off", so the response can't be used to probe which
   * message ids exist.
   */
  public function testReadAloudRefusesSomeoneElsesMessage(): void {
    $alice = $this->createUser();
    $bob = $this->createUser();
    $this->createConversation($alice);
    $bobsAnswer = $this->createConversation($bob)->getMessages()
      ->filter(static fn (ConversationMessage $m) => $m->getRole() === ConversationMessage::ROLE_ASSISTANT)->first();

    // A genuine read-aloud token for Alice, taken from her own chat page.
    $this->loginAs($alice);
    $crawler = $this->client->request('GET', $this->url('empathica_chatbot_index'));
    $token = $crawler->filter('.conversations')->attr('data-tts-token');

    $this->client->request('POST', $this->url('empathica_chatbot_tts', ['id' => $bobsAnswer->getId(), 'part' => 0]), ['_token' => $token]);

    $this->assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    $this->assertSame(0, $this->providerCalls, 'Nothing may be sent for synthesis.');
  }
}
