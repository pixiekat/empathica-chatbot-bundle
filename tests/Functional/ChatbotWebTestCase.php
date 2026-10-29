<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Tests\Functional;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Pixiekat\EmpathicaChatbot\Entity\Conversation;
use Pixiekat\EmpathicaChatbot\Entity\ConversationMessage;
use Pixiekat\SymfonyHelpers\Entity\AuditLog;
use Pixiekat\SymfonyHelpers\Interfaces\Entity\HelpersUserInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Base for the bundle's functional tests: real requests through the host.
 *
 * ── WHAT THEY NEED ───────────────────────────────────────────────────────────
 * A host application (KERNEL_CLASS) and its TEST database, with the host's
 * tables and the bundle's three tables in it. Without that, every test here
 * is SKIPPED with a message saying so, never failed: a missing database is a
 * machine set-up problem, not a bug. For Infosage, see tests/README in the
 * bundle (docs/qa-testing.md) for the commands.
 *
 * ── NO REAL AI CALLS, EVER ───────────────────────────────────────────────────
 * The HTTP transport is replaced with a mock that answers every request with
 * a 500, so a test can never reach a paid provider, whatever API keys the
 * machine happens to have. `http_client.transport` is swapped rather than
 * `http_client` itself, because Symfony wraps the client in decorators (URI
 * templates, retries, the profiler); swapping the innermost transport is the
 * one replacement every decorator still goes through.
 *
 * A 500 is chosen deliberately: it drives the chat's "temporarily
 * unavailable" path, which must save the participant's message anyway.
 * {@see self::$providerCalls} counts what would have been sent.
 *
 * ── USERS ────────────────────────────────────────────────────────────────────
 * The bundle can't know how the host builds a user, so createUser() fills
 * in whatever common setters the host's class has (see there). Each test's
 * users get unique email addresses, and are deleted afterwards along with
 * everything that cascades from them: their chatbot rows, and the audit rows
 * the admin tests create.
 */
abstract class ChatbotWebTestCase extends WebTestCase {

  protected KernelBrowser $client;

  /**
   * How many requests reached the mocked AI transport in this test.
   */
  protected int $providerCalls = 0;

  /**
   * Ids of users this test created, deleted again in tearDown().
   *
   * @var list<int>
   */
  private array $createdUserIds = [];

  protected function setUp(): void {
    $this->client = static::createClient();
    // One kernel for the whole test: without this the container (and the
    // mocked transport in it) is rebuilt between requests.
    $this->client->disableReboot();

    $this->skipUnlessDatabaseIsReady();

    $this->providerCalls = 0;
    static::getContainer()->set('http_client.transport', new MockHttpClient(function (): MockResponse {
      $this->providerCalls++;
      return new MockResponse('{"error":"mocked in tests"}', ['http_code' => 500]);
    }));
  }

  protected function tearDown(): void {
    // try/finally: if the clean-up itself fails, parent::tearDown() must
    // still run. Otherwise the kernel stays booted and EVERY later test fails
    // in createClient() with "the kernel should only be booted once", which
    // hides the one real error under dozens of misleading ones.
    try {
      if ($this->createdUserIds !== []) {
        $em = $this->entityManager();
        $connection = $em->getConnection();

        // Audit rows first: their actor column is SET NULL on delete, so they
        // would otherwise survive as orphans in the test database.
        $auditTable = $em->getClassMetadata(AuditLog::class)->getTableName();
        $connection->executeStatement(
          "DELETE FROM $auditTable WHERE actor_id IN (?) OR (target_type = 'user' AND target_id IN (?))",
          [$this->createdUserIds, array_map('strval', $this->createdUserIds)],
          [ArrayParameterType::INTEGER, ArrayParameterType::STRING],
        );

        // Then the users, by SQL rather than $em->remove(): Infosage's User is
        // soft-deletable, so remove() would only mark it deleted. (Plain
        // getTableName(), like the audit table above: ORM 3 dropped
        // getQuotedTableName().)
        $userTable = $em->getClassMetadata(HelpersUserInterface::class)->getTableName();
        $this->deleteWithDependents($connection, $userTable, 'id', $this->createdUserIds);
      }
    } finally {
      $this->createdUserIds = [];
      parent::tearDown();
    }
  }

  /**
   * Deletes rows, first deleting whatever the database would refuse to let
   * outlive them.
   *
   * The host decides what a user owns, and the bundle can't know it: Infosage,
   * for example, gives every new user a user_profiles row behind the scenes,
   * with a RESTRICT foreign key, so a plain DELETE FROM users fails. Rather
   * than hard-code one host's tables, this asks the database which foreign
   * keys point at $table and follows them:
   *
   *   - RESTRICT / NO ACTION: the dependent rows are deleted first (and their
   *     own dependents before them, recursively).
   *   - CASCADE / SET NULL: left to the database, which handles them itself.
   *
   * A table's foreign keys to ITSELF (e.g. a parent task) are skipped to avoid
   * an endless loop; those rows go in the same DELETE as their parent.
   *
   * Reads information_schema's REFERENCED_TABLE_NAME, which is MySQL/MariaDB
   * only, like the hosts this bundle runs in today.
   *
   * @param list<int|string> $values
   */
  private function deleteWithDependents(Connection $connection, string $table, string $column, array $values, int $depth = 0): void {
    if ($values === []) {
      return;
    }
    if ($depth > 10) {
      throw new \RuntimeException("Test clean-up gave up: foreign keys under '$table' nest more than 10 deep.");
    }

    $dependents = $connection->fetchAllAssociative(
      "SELECT kcu.TABLE_NAME AS child_table, kcu.COLUMN_NAME AS child_column, kcu.REFERENCED_COLUMN_NAME AS parent_column
         FROM information_schema.KEY_COLUMN_USAGE kcu
         JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
           ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
        WHERE kcu.TABLE_SCHEMA = DATABASE()
          AND kcu.REFERENCED_TABLE_NAME = ?
          AND kcu.TABLE_NAME <> kcu.REFERENCED_TABLE_NAME
          AND rc.DELETE_RULE IN ('RESTRICT', 'NO ACTION')",
      [$table],
    );

    foreach ($dependents as $fk) {
      // The dependents point at $fk['parent_column'], which isn't always the
      // column we were given, so look up those values first.
      $parentValues = $connection->fetchFirstColumn(
        "SELECT `{$fk['parent_column']}` FROM `$table` WHERE `$column` IN (?)",
        [$values],
        [ArrayParameterType::STRING],
      );
      $this->deleteWithDependents($connection, $fk['child_table'], $fk['child_column'], $parentValues, $depth + 1);
    }

    $connection->executeStatement(
      "DELETE FROM `$table` WHERE `$column` IN (?)",
      [$values],
      [ArrayParameterType::STRING],
    );
  }

  protected function entityManager(): EntityManagerInterface {
    return static::getContainer()->get('doctrine.orm.entity_manager');
  }

  /**
   * Skip (don't fail) when the test database isn't there or isn't migrated.
   */
  private function skipUnlessDatabaseIsReady(): void {
    try {
      $connection = $this->entityManager()->getConnection();
      $schema = $connection->createSchemaManager();
      $needed = [
        $this->entityManager()->getClassMetadata(HelpersUserInterface::class)->getTableName(),
        $this->entityManager()->getClassMetadata(Conversation::class)->getTableName(),
        $this->entityManager()->getClassMetadata(AuditLog::class)->getTableName(),
      ];
      if (!$schema->tablesExist($needed)) {
        $this->markTestSkipped('The test database is missing tables (' . implode(', ', $needed) . '). Create its schema first; see docs/qa-testing.md.');
      }
    }
    catch (\Doctrine\DBAL\Exception $e) {
      $this->markTestSkipped('No test database: ' . $e->getMessage() . ' See docs/qa-testing.md.');
    }
  }

  /**
   * Create and save a host user.
   *
   * The host's user class comes from SymfonyHelpers (symfony_helpers.user_class).
   * Common setters are called when the class has them, so this works for
   * Infosage's User and for most ordinary user entities. A host whose user
   * needs more than this can override the method in a subclass.
   *
   * @param list<string> $roles
   */
  protected function createUser(array $roles = ['ROLE_USER']): HelpersUserInterface {
    $class = static::getContainer()->getParameter('symfony_helpers.user_class');
    $user = new $class();
    $email = sprintf('chatbot-test-%s@example.com', bin2hex(random_bytes(6)));

    $setters = [
      'setEmailAddress' => $email,
      'setEmail' => $email,
      'setFirstName' => 'Chatbot',
      'setLastName' => 'Tester',
      'setRoles' => $roles,
      'setIsEnabled' => true,
      'setEnabled' => true,
      'setIsActive' => true,
    ];
    foreach ($setters as $setter => $value) {
      if (method_exists($user, $setter)) {
        $user->{$setter}($value);
      }
    }

    $em = $this->entityManager();
    $em->persist($user);
    $em->flush();
    $this->createdUserIds[] = (int) $user->getId();

    return $user;
  }

  /**
   * A saved conversation for $user, with one question and one answer.
   */
  protected function createConversation(HelpersUserInterface $user, string $question = 'What is metformin for?'): Conversation {
    $conversation = (new Conversation())
      ->setUser($user)
      ->setChatbotModel('Gemini')
      ->setPersona('Emily')
      ->setLiteracyLevel(2)
      ->setEmpathyLevel(2);
    $conversation->deriveTitleFrom($question);

    foreach ([ConversationMessage::fromUser($question), ConversationMessage::fromAssistant('It helps control blood sugar.')->setProvider('Gemini')] as $message) {
      $conversation->addMessage($message);
      $this->entityManager()->persist($message);
    }
    $this->entityManager()->persist($conversation);
    $this->entityManager()->flush();

    return $conversation;
  }

  protected function loginAs(HelpersUserInterface $user): void {
    $this->client->loginUser($user, 'main');
  }

  /**
   * Generate a bundle URL, so the tests don't care where the host mounts it.
   *
   * @param array<string, mixed> $parameters
   */
  protected function url(string $route, array $parameters = []): string {
    return static::getContainer()->get('router')->generate($route, $parameters);
  }
}
