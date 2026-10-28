<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\Migrations\AbstractMigration;
use Pixiekat\SymfonyHelpers\Traits\Migration\ResolvesUserTableTrait;

/**
 * Creates the chatbot bundle's three tables.
 *
 *   empathica_chatbot_preferences          one row per user who has saved settings
 *   empathica_chatbot_conversation         one row per chat thread
 *   empathica_chatbot_conversation_message one row per turn
 *
 * Found by `doctrine:migrations:migrate` without any host configuration: the
 * bundle's extension prepends this directory to doctrine_migrations.migrations_paths.
 *
 * ── THE USER FOREIGN KEYS ────────────────────────────────────────────────────
 * Two tables reference the host's user table, which this bundle does not own and
 * cannot name. ResolvesUserTableTrait (from SymfonyHelpers) finds it in the
 * schema and reports its primary-key type, so the referencing column matches in
 * width and signedness — the usual cause of MySQL's errno 150. If no user table
 * can be found the tables are still created and the migration prints the exact
 * ALTER statements to run by hand.
 *
 * ── STARTS EMPTY ─────────────────────────────────────────────────────────────
 * Nothing is copied from the standalone chatbot-app's database. Its users were
 * different people in a different user table; mapping them onto Infosage
 * accounts is a data decision, not a schema one, and belongs in its own
 * deliberate import if it is ever wanted.
 *
 * Guarded with hasTable(), like the SymfonyHelpers migrations, because a host
 * may already have run schema:update during development.
 */
final class Version20261028120000 extends AbstractMigration {

  use ResolvesUserTableTrait;

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return 'EMPATHICA chatbot: preferences, conversation and conversation message tables.';
  }

  /**
   * {@inheritdoc}
   */
  public function up(Schema $schema): void {
    // Resolved BEFORE the CREATEs: user_id has to be declared with the same
    // type as the referenced primary key, or the constraint is refused.
    $users = $this->resolveUserTable($schema);
    $userIdType = $this->userIdColumnType($users);
    $immutable = $this->immutableComment();

    if (!$schema->hasTable('empathica_chatbot_preferences')) {
      $this->addSql(<<<SQL
        CREATE TABLE empathica_chatbot_preferences (
          id INT AUTO_INCREMENT NOT NULL,
          user_id {$userIdType} NOT NULL,
          provider VARCHAR(64) NOT NULL,
          persona VARCHAR(64) NOT NULL,
          literacy_level SMALLINT DEFAULT 2 NOT NULL,
          empathy_level SMALLINT DEFAULT 2 NOT NULL,
          debug_mode_enabled TINYINT(1) DEFAULT 0 NOT NULL,
          memory LONGTEXT DEFAULT NULL,
          created_at DATETIME NOT NULL{$immutable},
          updated_at DATETIME DEFAULT NULL{$immutable},
          -- Unique: one set of preferences per user.
          UNIQUE INDEX UNIQ_734CDC7AA76ED395 (user_id),
          PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }
    else {
      $this->write('Table empathica_chatbot_preferences already exists, skipping creation.');
    }

    if (!$schema->hasTable('empathica_chatbot_conversation')) {
      $this->addSql(<<<SQL
        CREATE TABLE empathica_chatbot_conversation (
          id INT AUTO_INCREMENT NOT NULL,
          user_id {$userIdType} NOT NULL,
          title VARCHAR(255) DEFAULT NULL,
          chatbot_model VARCHAR(255) DEFAULT NULL,
          persona VARCHAR(255) DEFAULT NULL,
          literacy_level INT DEFAULT NULL,
          empathy_level INT DEFAULT NULL,
          prompt_version VARCHAR(255) DEFAULT NULL,
          legacy_import_key VARCHAR(64) DEFAULT NULL,
          last_activity_at DATETIME NOT NULL{$immutable},
          created_at DATETIME NOT NULL{$immutable},
          UNIQUE INDEX UNIQ_F713C522B80CFD9D (legacy_import_key),
          INDEX IDX_F713C522A76ED395 (user_id),
          -- "Resume where I left off" and the switcher both read by this.
          INDEX idx_empathica_conversation_user_activity (user_id, last_activity_at),
          PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }
    else {
      $this->write('Table empathica_chatbot_conversation already exists, skipping creation.');
    }

    if (!$schema->hasTable('empathica_chatbot_conversation_message')) {
      $this->addSql(<<<SQL
        CREATE TABLE empathica_chatbot_conversation_message (
          id INT AUTO_INCREMENT NOT NULL,
          conversation_id INT NOT NULL,
          role VARCHAR(32) NOT NULL,
          content LONGTEXT NOT NULL,
          sequence INT NOT NULL,
          provider VARCHAR(255) DEFAULT NULL,
          model_id VARCHAR(255) DEFAULT NULL,
          literacy_level INT DEFAULT NULL,
          empathy_level INT DEFAULT NULL,
          prompt_version VARCHAR(255) DEFAULT NULL,
          prompt_tokens INT DEFAULT NULL,
          completion_tokens INT DEFAULT NULL,
          latency_ms INT DEFAULT NULL,
          finish_reason VARCHAR(64) DEFAULT NULL,
          created_at DATETIME NOT NULL{$immutable},
          INDEX IDX_98D5F87D9AC0396 (conversation_id),
          -- Transcript order, and the history-budget replay.
          INDEX idx_empathica_message_conversation_sequence (conversation_id, sequence),
          PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

      // Internal to the bundle, so this one can be written directly.
      $this->addSql('ALTER TABLE empathica_chatbot_conversation_message ADD CONSTRAINT FK_98D5F87D9AC0396 FOREIGN KEY (conversation_id) REFERENCES empathica_chatbot_conversation (id) ON DELETE CASCADE');
    }
    else {
      $this->write('Table empathica_chatbot_conversation_message already exists, skipping creation.');
    }

    // CASCADE: deleting a user removes their chatbot preferences and threads.
    // Unlike a shoutbox post, a conversation here has exactly one participant,
    // so there is nobody else's side of it to preserve.
    if ($users === null) {
      $this->write($this->manualForeignKeyNotice('empathica_chatbot_preferences', 'user_id', 'FK_734CDC7AA76ED395'));
      $this->write($this->manualForeignKeyNotice('empathica_chatbot_conversation', 'user_id', 'FK_F713C522A76ED395'));

      return;
    }

    foreach ([
      'empathica_chatbot_preferences' => 'FK_734CDC7AA76ED395',
      'empathica_chatbot_conversation' => 'FK_F713C522A76ED395',
    ] as $table => $constraint) {
      // Skip a constraint that already exists (an earlier schema:update), or
      // re-running against a partly-created schema would fail on a duplicate.
      if ($schema->hasTable($table) && $schema->getTable($table)->hasForeignKey($constraint)) {
        continue;
      }

      $this->addSql(sprintf(
        'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (user_id) REFERENCES `%s` (id) ON DELETE CASCADE',
        $table,
        $constraint,
        $users->getName(),
      ));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function down(Schema $schema): void {
    // Child first: the message table's foreign key would block dropping
    // conversations otherwise.
    foreach (['empathica_chatbot_conversation_message', 'empathica_chatbot_conversation', 'empathica_chatbot_preferences'] as $table) {
      if ($schema->hasTable($table)) {
        $this->addSql(sprintf('DROP TABLE %s', $table));
      }
    }
  }

  /**
   * The column comment DBAL needs for a datetime_immutable column, if any.
   *
   * DBAL 3 recovers a column's Doctrine type from a "(DC2Type:…)" comment, and
   * without it every schema comparison reports the column as changed. DBAL 4
   * dropped the mechanism, and there the comment itself is what shows up as
   * permanent drift. Infosage is on DBAL 3 today and moving with Symfony 8, so
   * the migration asks which one it is running under rather than guessing.
   *
   * requiresSQLCommentHint() exists on Type only in DBAL 3, which makes it a
   * reliable version probe.
   */
  private function immutableComment(): string {
    return method_exists(Type::class, 'requiresSQLCommentHint')
      ? " COMMENT '(DC2Type:datetime_immutable)'"
      : '';
  }
}
