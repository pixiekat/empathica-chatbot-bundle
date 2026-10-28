<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Repository;

use Pixiekat\EmpathicaChatbot\Entity\Conversation;
use Pixiekat\SymfonyHelpers\Interfaces\Entity\HelpersUserInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Conversation>
 */
class ConversationRepository extends ServiceEntityRepository {

  public function __construct(ManagerRegistry $registry) {
    parent::__construct($registry, Conversation::class);
  }

  /**
   * The conversation a returning user should land in, if any.
   *
   * Ordered by lastActivityAt so "carry on where I left off" works across
   * devices and across session expiry — which is the whole point of moving
   * this out of the PHP session.
   */
  public function findMostRecentForUser(HelpersUserInterface $user): ?Conversation {
    return $this->createQueryBuilder('c')
      ->andWhere('c.user = :user')
      ->setParameter('user', $user)
      ->orderBy('c.lastActivityAt', 'DESC')
      ->setMaxResults(1)
      ->getQuery()
      ->getOneOrNullResult();
  }

  /**
   * Fetch one conversation, but only if it belongs to this user.
   *
   * The ownership check lives in the query rather than in the controller so
   * there is no path that loads someone else's thread by id and checks
   * afterwards — a conversation is a clinical transcript, and "load then
   * verify" is one forgotten branch away from disclosure.
   */
  public function findOneOwnedBy(int $id, HelpersUserInterface $user): ?Conversation {
    return $this->createQueryBuilder('c')
      ->andWhere('c.id = :id')
      ->andWhere('c.user = :user')
      ->setParameter('id', $id)
      ->setParameter('user', $user)
      ->getQuery()
      ->getOneOrNullResult();
  }

  /**
   * One page of a user's conversations for the list view, newest first.
   *
   * @return Paginator<Conversation>
   */
  public function findByUserPaginated(HelpersUserInterface $user, int $page = 1, int $limit = 15): Paginator {
    $query = $this->createQueryBuilder('c')
      ->andWhere('c.user = :user')
      ->setParameter('user', $user)
      ->orderBy('c.lastActivityAt', 'DESC')
      ->setFirstResult(($page - 1) * $limit)
      ->setMaxResults($limit)
      ->getQuery();

    return new Paginator($query);
  }

  /**
   * One page of "who has been chatting", most recently active first.
   *
   * Done as two queries on purpose. The obvious single query —
   * SELECT u, COUNT(c) ... GROUP BY u — selects every column of the host's user
   * table while grouping by its id. MySQL 5.7+ accepts that (it detects the
   * functional dependency on the primary key) but MariaDB does not, and fails
   * under ONLY_FULL_GROUP_BY. So: aggregate by user id first, then load just
   * those users through HelpersUserInterface, which Doctrine resolves to the
   * host's concrete class.
   *
   * @return list<array{user: HelpersUserInterface, conversationCount: int, lastActivityAt: \DateTimeImmutable}>
   */
  public function findUserSummariesPaginated(int $page = 1, int $limit = 25): array {
    $summaries = $this->createQueryBuilder('c')
      ->select('IDENTITY(c.user) AS userId', 'COUNT(c.id) AS conversationCount', 'MAX(c.lastActivityAt) AS lastActivityAt')
      ->groupBy('c.user')
      ->orderBy('lastActivityAt', 'DESC')
      ->setFirstResult(($page - 1) * $limit)
      ->setMaxResults($limit)
      ->getQuery()
      ->getScalarResult();

    if ($summaries === []) {
      return [];
    }

    // Index the users by id so the aggregate order above is preserved.
    $users = [];
    $found = $this->getEntityManager()->createQueryBuilder()
      ->select('u')
      ->from(HelpersUserInterface::class, 'u')
      ->andWhere('u.id IN (:ids)')
      ->setParameter('ids', array_column($summaries, 'userId'))
      ->getQuery()
      ->getResult();
    foreach ($found as $user) {
      $users[$user->getId()] = $user;
    }

    $rows = [];
    foreach ($summaries as $summary) {
      $user = $users[(int) $summary['userId']] ?? null;
      // A user deleted between the two queries simply drops out of the page.
      if ($user === null) {
        continue;
      }
      $rows[] = [
        'user' => $user,
        'conversationCount' => (int) $summary['conversationCount'],
        // Scalar results come back as strings; MAX() loses the column type.
        'lastActivityAt' => new \DateTimeImmutable((string) $summary['lastActivityAt']),
      ];
    }

    return $rows;
  }

  /**
   * How many distinct users have at least one conversation.
   */
  public function countDistinctUsers(): int {
    return (int) $this->createQueryBuilder('c')
      ->select('COUNT(DISTINCT IDENTITY(c.user))')
      ->getQuery()
      ->getSingleScalarResult();
  }

  /**
   * Message counts for a set of conversations, keyed by conversation id.
   *
   * Used by the list view. Doing this as one grouped query avoids issuing a
   * COUNT per row, which is the usual way a conversation list quietly becomes
   * N+1 as someone's history grows.
   *
   * @param Conversation[] $conversations
   *
   * @return array<int, int>
   */
  public function countMessagesFor(array $conversations): array {
    if ($conversations === []) {
      return [];
    }

    $rows = $this->getEntityManager()->createQueryBuilder()
      ->select('IDENTITY(m.conversation) AS conversationId', 'COUNT(m.id) AS total')
      ->from(\Pixiekat\EmpathicaChatbot\Entity\ConversationMessage::class, 'm')
      ->andWhere('m.conversation IN (:conversations)')
      ->setParameter('conversations', $conversations)
      ->groupBy('m.conversation')
      ->getQuery()
      ->getScalarResult();

    $counts = [];
    foreach ($rows as $row) {
      $counts[(int) $row['conversationId']] = (int) $row['total'];
    }

    return $counts;
  }
}
