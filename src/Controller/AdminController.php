<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Pixiekat\EmpathicaChatbot\Entity;
use Pixiekat\EmpathicaChatbot\Export\CsvSanitizer;
use Pixiekat\EmpathicaChatbot\Interfaces;
use Pixiekat\EmpathicaChatbot\Repository;
use Pixiekat\SymfonyHelpers\Interfaces\Entity\HelpersUserInterface;
use Pixiekat\SymfonyHelpers\Services\AuditLogManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Researcher screens: who has been chatting, their threads, and transcripts.
 *
 * WHAT CHANGED FROM THE STANDALONE APP
 * ------------------------------------
 * The old AdminController also added and edited users, and read transcripts
 * from the legacy UserChatHistory log. Neither belongs in a bundle: the host
 * owns its users (Infosage has its own admin for them), and UserChatHistory
 * stayed behind as a frozen archive. Everything here is built from the
 * bundle's own Conversation / ConversationMessage tables.
 *
 * The host's users are only ever reached through HelpersUserInterface, which
 * Doctrine resolves to the host's concrete class. Nothing here reads a field
 * the interface does not promise — a user is shown by getUserIdentifier().
 *
 * ACCESS
 * ------
 * The whole controller is behind ADMIN_ACCESS_ADMIN_FUNCTIONS, which the
 * DefaultVoter only grants when `empathica_chatbot.features.admin` is on AND
 * the user holds `empathica_chatbot.admin_role`. Switching the feature off
 * makes every route here answer 403 instead of remaining reachable by URL.
 *
 * Templates extend the `admin_layout` Twig global published by the
 * SymfonyHelpers bundle, so these screens render inside the host's own
 * control panel chrome.
 *
 * AUDIT TRAIL
 * -----------
 * Every screen or download that shows what a participant actually wrote is
 * recorded through the SymfonyHelpers AuditLogManager (the `audit_logs` table
 * and the `audit` Monolog channel), so "who read whose conversations, and
 * when" can always be answered. The overview list of users is not logged: it
 * shows names and counts, never anything a participant typed.
 *
 * The audit TARGET is always the participant, never the Conversation. The
 * manager labels a target by its getTitle(), and a conversation's title is
 * the opening words of the participant's first message. Using it would copy
 * clinical text into a log that is kept and read for other reasons. The
 * conversation is identified by id in the context instead.
 *
 * The sentences these entries show as in the SymfonyHelpers audit screen are
 * in translations/messages.en.yaml (audit.action.chatbot.*).
 */
#[IsGranted(Interfaces\Security\Voter\DefaultVoterInterface::ADMIN_ACCESS_ADMIN_FUNCTIONS, message: 'You do not have access to the chatbot admin screens.')]
#[Route('/admin')]
final class AdminController extends AbstractController {

  /**
   * Rows per page on the user and conversation lists.
   */
  private const PER_PAGE = 25;

  /**
   * Audit action keys. Each has a matching audit.action.<key> sentence in
   * translations/messages.en.yaml.
   */
  public const AUDIT_USER_CONVERSATIONS_VIEWED = 'chatbot.user_conversations.viewed';
  public const AUDIT_TRANSCRIPT_VIEWED = 'chatbot.transcript.viewed';
  public const AUDIT_TRANSCRIPT_EXPORTED = 'chatbot.transcript.exported';
  public const AUDIT_USER_EXPORTED = 'chatbot.user_messages.exported';
  public const AUDIT_ALL_EXPORTED = 'chatbot.all_messages.exported';

  public function __construct(
    private readonly EntityManagerInterface $entityManager,
    private readonly Repository\ConversationRepository $conversations,
    private readonly AuditLogManager $audit,
  ) {  }

  /**
   * Everyone who has at least one conversation, most recently active first.
   */
  #[Route('', name: 'empathica_chatbot_admin_users', methods: ['GET'])]
  public function users(Request $request): Response {
    // Clamp so a hand-typed ?page=0 cannot produce a negative offset.
    $page = max(1, $request->query->getInt('page', 1));
    $rows = $this->conversations->findUserSummariesPaginated($page, self::PER_PAGE);
    $totalItems = $this->conversations->countDistinctUsers();

    return $this->render('@PixiekatEmpathicaChatbot/admin/users.html.twig', [
      'rows' => $rows,
      'currentPage' => $page,
      'totalPages' => max(1, (int) ceil($totalItems / self::PER_PAGE)),
      'totalItems' => $totalItems,
    ]);
  }

  /**
   * One user's conversations.
   */
  #[Route('/user/{id}/conversations', name: 'empathica_chatbot_admin_user_conversations', methods: ['GET'], requirements: ['id' => '\d+'])]
  public function userConversations(int $id, Request $request): Response {
    $user = $this->findUser($id);
    $page = max(1, $request->query->getInt('page', 1));

    // Logged because the list shows conversation titles, which are the
    // opening words of what the participant typed.
    $this->audit->log(self::AUDIT_USER_CONVERSATIONS_VIEWED, $user, ['page' => $page]);

    $paginator = $this->conversations->findByUserPaginated($user, $page, self::PER_PAGE);
    $items = iterator_to_array($paginator);

    return $this->render('@PixiekatEmpathicaChatbot/admin/user_conversations.html.twig', [
      'user' => $user,
      'conversations' => $items,
      'messageCounts' => $this->conversations->countMessagesFor($items),
      'currentPage' => $page,
      'totalPages' => max(1, (int) ceil(count($paginator) / self::PER_PAGE)),
    ]);
  }

  /**
   * One conversation, read in full.
   */
  #[Route('/conversation/{id}', name: 'empathica_chatbot_admin_transcript', methods: ['GET'], requirements: ['id' => '\d+'])]
  public function transcript(int $id): Response {
    $conversation = $this->conversations->find($id) ?? throw $this->createNotFoundException('Conversation not found.');

    $this->audit->log(self::AUDIT_TRANSCRIPT_VIEWED, $conversation->getUser(), ['conversation_id' => $conversation->getId()]);

    return $this->render('@PixiekatEmpathicaChatbot/admin/user_transcript.html.twig', [
      'conversation' => $conversation,
      'messages' => $conversation->getMessages(),
    ]);
  }

  /**
   * CSV of one conversation.
   */
  #[Route('/conversation/{id}/export', name: 'empathica_chatbot_admin_transcript_export', methods: ['GET'], requirements: ['id' => '\d+'])]
  public function transcriptExport(int $id): Response {
    $conversation = $this->conversations->find($id) ?? throw $this->createNotFoundException('Conversation not found.');

    // Logged before the download starts. A StreamedResponse writes after this
    // action has returned, so there is no later point at which "it was sent"
    // could be recorded; an interrupted download is still a disclosure
    // attempt worth knowing about.
    $this->audit->log(self::AUDIT_TRANSCRIPT_EXPORTED, $conversation->getUser(), ['conversation_id' => $conversation->getId()]);

    $rows = $this->messageQuery()
      ->andWhere('c = :conversation')
      ->setParameter('conversation', $conversation)
      ->getQuery()
      ->toIterable();

    // Ids, not emails, in filenames: no '@' or other characters that need
    // escaping in a Content-Disposition header.
    return $this->streamMessagesCsv($rows, sprintf('chatbot-conversation-%d-%s.csv', $id, date('Ymd')), includeUser: false);
  }

  /**
   * CSV of every conversation one user has had.
   */
  #[Route('/user/{id}/export', name: 'empathica_chatbot_admin_user_export', methods: ['GET'], requirements: ['id' => '\d+'])]
  public function userExport(int $id): Response {
    $user = $this->findUser($id);

    // Before streaming, as above.
    $this->audit->log(self::AUDIT_USER_EXPORTED, $user);

    $rows = $this->messageQuery()
      ->andWhere('c.user = :user')
      ->setParameter('user', $user)
      ->getQuery()
      ->toIterable();

    // includeUser: false — every row is the same person, and the filename
    // already says which one.
    return $this->streamMessagesCsv($rows, sprintf('chatbot-user-%d-%s.csv', $id, date('Ymd')), includeUser: false);
  }

  /**
   * CSV of every message by every user.
   */
  #[Route('/export', name: 'empathica_chatbot_admin_export', methods: ['GET'])]
  public function exportAll(): Response {
    // No target: this is everyone's data. Before streaming, as above.
    $this->audit->log(self::AUDIT_ALL_EXPORTED);

    $rows = $this->messageQuery()->getQuery()->toIterable();

    return $this->streamMessagesCsv($rows, sprintf('chatbot-all-%s.csv', date('Ymd')), includeUser: true);
  }

  /**
   * Load a host user by id through the interface.
   *
   * find() on HelpersUserInterface works because Doctrine's
   * ResolveTargetEntityListener resolves the interface to the configured class
   * (symfony_helpers.user_class) when metadata is requested for it — so this
   * controller never has to know the host's user class name.
   */
  private function findUser(int $id): HelpersUserInterface {
    $user = $this->entityManager->find(HelpersUserInterface::class, $id);

    if (!$user instanceof HelpersUserInterface) {
      throw $this->createNotFoundException('User not found.');
    }

    return $user;
  }

  /**
   * Base query for the CSV exports: messages, with their conversation and user.
   *
   * Fetch-joins (addSelect) the conversation and the user so reading them off
   * each row does not fire a query per row — an N+1 that would be brutal over
   * a whole study. To-one joins are safe to combine with toIterable(); a
   * to-many join would not be.
   */
  private function messageQuery(): \Doctrine\ORM\QueryBuilder {
    return $this->entityManager->createQueryBuilder()
      ->select('m', 'c', 'u')
      ->from(Entity\ConversationMessage::class, 'm')
      ->join('m.conversation', 'c')
      ->join('c.user', 'u')
      ->orderBy('u.id', 'ASC')
      ->addOrderBy('c.id', 'ASC')
      ->addOrderBy('m.sequence', 'ASC');
  }

  /**
   * Streams ConversationMessage rows out as a CSV download.
   *
   * One row per message (not per exchange, as the old UserChatHistory export
   * was), carrying the per-message conditions — provider, model, levels,
   * prompt version, token counts — because those are what analysis should
   * trust; see the note on Conversation about why they live on each message.
   *
   * Null token counts are written as empty cells, not 0: "not reported by the
   * provider" and "cost nothing" are different claims. See ChatReply.
   *
   * @param iterable<Entity\ConversationMessage> $rows Rows from Query::toIterable().
   * @param string $filename Suggested download filename.
   * @param bool $includeUser Whether to prepend a "User" column.
   */
  private function streamMessagesCsv(iterable $rows, string $filename, bool $includeUser): StreamedResponse {
    $response = new StreamedResponse(function () use ($rows, $includeUser) {
      $handle = fopen('php://output', 'w');

      // UTF-8 byte-order mark so Excel opens accented characters correctly
      // instead of mangling them as Latin-1.
      fwrite($handle, "\xEF\xBB\xBF");

      $header = [
        'Conversation', 'Sequence', 'Role', 'Content', 'Persona', 'Provider', 'Model',
        'Literacy', 'Empathy', 'Prompt Version', 'Prompt Tokens', 'Completion Tokens',
        'Finish Reason', 'Latency (ms)', 'Timestamp',
      ];
      if ($includeUser) {
        array_unshift($header, 'User');
      }
      // escape: '' turns off PHP's non-standard backslash escaping, so a
      // message containing \" is written the way spreadsheets expect (RFC
      // 4180: quotes doubled, nothing else special). Passing it explicitly is
      // also required from PHP 8.4, which deprecates relying on the default.
      fputcsv($handle, $header, escape: '');

      foreach ($rows as $message) {
        $conversation = $message->getConversation();
        $line = [
          $conversation?->getId(),
          $message->getSequence(),
          $message->getRole(),
          CsvSanitizer::neutralise($message->getContent()),
          CsvSanitizer::neutralise($conversation?->getPersona()),
          CsvSanitizer::neutralise($message->getProvider()),
          CsvSanitizer::neutralise($message->getModelId()),
          $message->getLiteracyLevel(),
          $message->getEmpathyLevel(),
          CsvSanitizer::neutralise($message->getPromptVersion()),
          $message->getPromptTokens(),
          $message->getCompletionTokens(),
          CsvSanitizer::neutralise($message->getFinishReason()),
          $message->getLatencyMs(),
          $message->getCreatedAt()->format('Y-m-d H:i:s'),
        ];
        if ($includeUser) {
          array_unshift($line, CsvSanitizer::neutralise($conversation?->getUser()?->getUserIdentifier()));
        }
        fputcsv($handle, $line, escape: ''); // see the header row above

        // Detach once written so the identity map does not grow unbounded
        // over a large export — keeps memory flat.
        $this->entityManager->detach($message);
      }

      fclose($handle);
    });

    $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
    $response->headers->set(
      'Content-Disposition',
      $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename)
    );
    return $response;
  }
}
