<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Pixiekat\EmpathicaChatbot\Controller\AdminController;
use Pixiekat\SymfonyHelpers\Entity\AuditLog;
use Symfony\Component\HttpFoundation\Response;

/**
 * The researcher screens: who gets in, what the export contains, and that
 * every look at a participant's words lands in the audit log.
 *
 * Turning the screens off with `features.admin: false` is covered by
 * DefaultVoterTest. Doing it here would need a second kernel configuration.
 */
final class AdminAccessTest extends ChatbotWebTestCase {

  /**
   * Every admin route, given a conversation (and its owner) to point at.
   *
   * @return iterable<string, array{string, string}>
   *   Route name, and which id it takes: 'none', 'user' or 'conversation'.
   */
  public static function adminRouteProvider(): iterable {
    yield 'user list' => ['empathica_chatbot_admin_users', 'none'];
    yield 'one user\'s conversations' => ['empathica_chatbot_admin_user_conversations', 'user'];
    yield 'transcript' => ['empathica_chatbot_admin_transcript', 'conversation'];
    yield 'transcript CSV' => ['empathica_chatbot_admin_transcript_export', 'conversation'];
    yield 'user CSV' => ['empathica_chatbot_admin_user_export', 'user'];
    yield 'everything CSV' => ['empathica_chatbot_admin_export', 'none'];
  }

  private function adminUrl(string $route, string $takes, int $userId, int $conversationId): string {
    return $this->url($route, match ($takes) {
      'user' => ['id' => $userId],
      'conversation' => ['id' => $conversationId],
      default => [],
    });
  }

  #[DataProvider('adminRouteProvider')]
  public function testParticipantsAreRefused(string $route, string $takes): void {
    $participant = $this->createUser();
    $conversation = $this->createConversation($participant);

    $this->loginAs($participant);
    $this->client->request('GET', $this->adminUrl($route, $takes, (int) $participant->getId(), (int) $conversation->getId()));

    $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
  }

  #[DataProvider('adminRouteProvider')]
  public function testAdminsGetIn(string $route, string $takes): void {
    $participant = $this->createUser();
    $conversation = $this->createConversation($participant);

    $this->loginAs($this->createUser(['ROLE_ADMIN']));
    $this->client->request('GET', $this->adminUrl($route, $takes, (int) $participant->getId(), (int) $conversation->getId()));

    $this->assertResponseIsSuccessful();
  }

  /**
   * A participant who typed a spreadsheet formula gets it exported as inert
   * text, and the file is a download, not a page.
   */
  public function testCsvExportIsADownloadWithFormulasNeutralised(): void {
    $participant = $this->createUser();
    $conversation = $this->createConversation($participant, '=HYPERLINK("https://evil.example","click")');

    $this->loginAs($this->createUser(['ROLE_ADMIN']));
    // A StreamedResponse has no body until it's sent. The test browser
    // (HttpKernelBrowser::filterResponse) sends it into a buffer and keeps
    // the result on the internal response, which is what's read here.
    $this->client->request('GET', $this->url('empathica_chatbot_admin_transcript_export', ['id' => $conversation->getId()]));
    $csv = (string) $this->client->getInternalResponse()->getContent();

    $this->assertResponseIsSuccessful();
    $this->assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
    $this->assertStringStartsWith('attachment;', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    $this->assertStringContainsString("'=HYPERLINK", $csv);
    $this->assertStringNotContainsString(',"=HYPERLINK', $csv);
  }

  /**
   * Reading a transcript and downloading one both leave an audit entry,
   * naming the admin and the participant, and never the participant's words.
   */
  public function testReadingAndExportingAreAudited(): void {
    $participant = $this->createUser();
    $conversation = $this->createConversation($participant, 'Something very private');
    $admin = $this->createUser(['ROLE_ADMIN']);

    $this->loginAs($admin);
    $this->client->request('GET', $this->url('empathica_chatbot_admin_transcript', ['id' => $conversation->getId()]));
    $this->client->request('GET', $this->url('empathica_chatbot_admin_transcript_export', ['id' => $conversation->getId()]));

    $this->entityManager()->clear();
    $entries = $this->entityManager()->getRepository(AuditLog::class)->findBy(['actor' => $admin->getId()], ['id' => 'ASC']);
    $actions = array_map(static fn (AuditLog $entry) => $entry->getAction(), $entries);

    $this->assertSame([AdminController::AUDIT_TRANSCRIPT_VIEWED, AdminController::AUDIT_TRANSCRIPT_EXPORTED], $actions);
    foreach ($entries as $entry) {
      $this->assertSame((string) $participant->getId(), $entry->getTargetId(), 'The target is the participant.');
      $this->assertStringNotContainsString('Something very private', (string) $entry->getTargetLabel());
      $this->assertStringNotContainsString('Something very private', json_encode($entry->getContext()));
    }
  }

  /**
   * The overview list shows names and counts only, so it isn't logged.
   * Logging every glance at it would bury the entries that matter.
   */
  public function testTheUserListIsNotAudited(): void {
    $this->createConversation($this->createUser());
    $admin = $this->createUser(['ROLE_ADMIN']);

    $this->loginAs($admin);
    $this->client->request('GET', $this->url('empathica_chatbot_admin_users'));

    $this->assertResponseIsSuccessful();
    $this->assertSame([], $this->entityManager()->getRepository(AuditLog::class)->findBy(['actor' => $admin->getId()]));
  }
}
