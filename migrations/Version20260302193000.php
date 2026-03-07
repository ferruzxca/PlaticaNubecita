<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260302193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'v2 profile avatar/status, group chats and bot support';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->upMySql();

            return;
        }

        if ($platform instanceof SqlitePlatform) {
            $this->upSqlite();

            return;
        }

        $this->abortIf(true, sprintf('Unsupported platform "%s" in migration.', get_debug_type($platform)));
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->downMySql();

            return;
        }

        if ($platform instanceof SqlitePlatform) {
            $this->downSqlite();

            return;
        }

        $this->abortIf(true, sprintf('Unsupported platform "%s" in migration.', get_debug_type($platform)));
    }

    private function upMySql(): void
    {
        $this->addSql('ALTER TABLE users ADD status_ciphertext LONGBLOB DEFAULT NULL, ADD avatar_blob LONGBLOB DEFAULT NULL, ADD avatar_nonce VARBINARY(24) DEFAULT NULL, ADD avatar_key_version INT NOT NULL DEFAULT 1, ADD avatar_mime_ciphertext LONGBLOB DEFAULT NULL, ADD is_bot TINYINT(1) NOT NULL DEFAULT 0');

        $this->addSql('ALTER TABLE chats MODIFY pair_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE chats ADD owner_id INT DEFAULT NULL, ADD name_ciphertext LONGBLOB DEFAULT NULL, ADD name_nonce VARBINARY(24) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_CHATS_OWNER_ID ON chats (owner_id)');
        $this->addSql('ALTER TABLE chats ADD CONSTRAINT FK_CHATS_OWNER_ID FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE SET NULL');

        $this->addSql("ALTER TABLE chat_participants ADD role VARCHAR(20) NOT NULL DEFAULT 'member'");
        $this->addSql("UPDATE chat_participants SET role = 'member' WHERE role IS NULL OR role = ''");
    }

    private function upSqlite(): void
    {
        $this->addSql('ALTER TABLE users ADD COLUMN status_ciphertext BLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD COLUMN avatar_blob BLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD COLUMN avatar_nonce BLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD COLUMN avatar_key_version INTEGER NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE users ADD COLUMN avatar_mime_ciphertext BLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD COLUMN is_bot BOOLEAN NOT NULL DEFAULT 0');

        $this->addSql('PRAGMA foreign_keys=OFF');
        $this->addSql('CREATE TABLE chats_new (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, owner_id INTEGER DEFAULT NULL, type VARCHAR(20) NOT NULL, pair_hash VARCHAR(64) DEFAULT NULL, name_ciphertext BLOB DEFAULT NULL, name_nonce BLOB DEFAULT NULL, created_at DATETIME NOT NULL, CONSTRAINT FK_CHATS_OWNER_ID FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO chats_new (id, type, pair_hash, created_at) SELECT id, type, pair_hash, created_at FROM chats');
        $this->addSql('DROP TABLE chats');
        $this->addSql('ALTER TABLE chats_new RENAME TO chats');
        $this->addSql('CREATE UNIQUE INDEX uniq_chat_pair_hash ON chats (pair_hash)');
        $this->addSql('CREATE INDEX IDX_CHATS_OWNER_ID ON chats (owner_id)');
        $this->addSql('PRAGMA foreign_keys=ON');

        $this->addSql("ALTER TABLE chat_participants ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'member'");
        $this->addSql("UPDATE chat_participants SET role = 'member' WHERE role IS NULL OR role = ''");
    }

    private function downMySql(): void
    {
        $this->addSql('ALTER TABLE chats DROP FOREIGN KEY FK_CHATS_OWNER_ID');
        $this->addSql('DROP INDEX IDX_CHATS_OWNER_ID ON chats');
        $this->addSql('ALTER TABLE chats DROP owner_id, DROP name_ciphertext, DROP name_nonce');
        $this->addSql("UPDATE chats SET pair_hash = CONCAT('legacy-', id) WHERE pair_hash IS NULL");
        $this->addSql('ALTER TABLE chats MODIFY pair_hash VARCHAR(64) NOT NULL');

        $this->addSql('ALTER TABLE chat_participants DROP role');
        $this->addSql('ALTER TABLE users DROP status_ciphertext, DROP avatar_blob, DROP avatar_nonce, DROP avatar_key_version, DROP avatar_mime_ciphertext, DROP is_bot');
    }

    private function downSqlite(): void
    {
        $this->addSql('PRAGMA foreign_keys=OFF');

        $this->addSql('CREATE TABLE users_down (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, display_name VARCHAR(80) NOT NULL, email_ciphertext BLOB NOT NULL, email_hash VARCHAR(64) NOT NULL, roles CLOB NOT NULL, password VARCHAR(255) NOT NULL, is_active BOOLEAN DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('INSERT INTO users_down (id, display_name, email_ciphertext, email_hash, roles, password, is_active, created_at, updated_at) SELECT id, display_name, email_ciphertext, email_hash, roles, password, is_active, created_at, updated_at FROM users');
        $this->addSql('DROP TABLE users');
        $this->addSql('ALTER TABLE users_down RENAME TO users');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_email_hash ON users (email_hash)');

        $this->addSql('CREATE TABLE chats_down (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, type VARCHAR(20) NOT NULL, pair_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql("INSERT INTO chats_down (id, type, pair_hash, created_at) SELECT id, type, COALESCE(pair_hash, ('legacy-' || id)), created_at FROM chats");
        $this->addSql('DROP TABLE chats');
        $this->addSql('ALTER TABLE chats_down RENAME TO chats');
        $this->addSql('CREATE UNIQUE INDEX uniq_chat_pair_hash ON chats (pair_hash)');

        $this->addSql('CREATE TABLE chat_participants_down (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, joined_at DATETIME NOT NULL, chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_8F8219131A9A7125 FOREIGN KEY (chat_id) REFERENCES chats (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_8F821913A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO chat_participants_down (id, joined_at, chat_id, user_id) SELECT id, joined_at, chat_id, user_id FROM chat_participants');
        $this->addSql('DROP TABLE chat_participants');
        $this->addSql('ALTER TABLE chat_participants_down RENAME TO chat_participants');
        $this->addSql('CREATE INDEX IDX_8F8219131A9A7125 ON chat_participants (chat_id)');
        $this->addSql('CREATE INDEX IDX_8F821913A76ED395 ON chat_participants (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_chat_user ON chat_participants (chat_id, user_id)');

        $this->addSql('PRAGMA foreign_keys=ON');
    }
}
