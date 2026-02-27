<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260227214856 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE attachments (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, cipher_blob BLOB NOT NULL, nonce BLOB NOT NULL, key_version INTEGER DEFAULT 1 NOT NULL, mime_ciphertext BLOB NOT NULL, filename_ciphertext BLOB NOT NULL, size_bytes INTEGER NOT NULL, created_at DATETIME NOT NULL, message_id INTEGER NOT NULL, CONSTRAINT FK_47C4FAD6537A1329 FOREIGN KEY (message_id) REFERENCES messages (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_47C4FAD6537A1329 ON attachments (message_id)');
        $this->addSql('CREATE TABLE chat_participants (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, joined_at DATETIME NOT NULL, chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_8F8219131A9A7125 FOREIGN KEY (chat_id) REFERENCES chats (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_8F821913A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_8F8219131A9A7125 ON chat_participants (chat_id)');
        $this->addSql('CREATE INDEX IDX_8F821913A76ED395 ON chat_participants (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_chat_user ON chat_participants (chat_id, user_id)');
        $this->addSql('CREATE TABLE chats (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, type VARCHAR(20) NOT NULL, pair_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_chat_pair_hash ON chats (pair_hash)');
        $this->addSql('CREATE TABLE login_audit (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email_hash VARCHAR(64) DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, success BOOLEAN NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE INDEX idx_login_audit_created_at ON login_audit (created_at)');
        $this->addSql('CREATE TABLE messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, ciphertext BLOB DEFAULT NULL, nonce BLOB DEFAULT NULL, key_version INTEGER DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, chat_id INTEGER NOT NULL, sender_id INTEGER NOT NULL, CONSTRAINT FK_DB021E961A9A7125 FOREIGN KEY (chat_id) REFERENCES chats (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_DB021E96F624B39D FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_DB021E96F624B39D ON messages (sender_id)');
        $this->addSql('CREATE INDEX idx_message_chat_id ON messages (chat_id)');
        $this->addSql('CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_3967A216A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_3967A216A76ED395 ON password_reset_tokens (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_password_reset_token_hash ON password_reset_tokens (token_hash)');
        $this->addSql('CREATE TABLE registration_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email_hash VARCHAR(64) NOT NULL, email_ciphertext BLOB NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE INDEX idx_registration_email_hash ON registration_tokens (email_hash)');
        $this->addSql('CREATE UNIQUE INDEX uniq_registration_token_hash ON registration_tokens (token_hash)');
        $this->addSql('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, display_name VARCHAR(80) NOT NULL, email_ciphertext BLOB NOT NULL, email_hash VARCHAR(64) NOT NULL, roles CLOB NOT NULL, password VARCHAR(255) NOT NULL, is_active BOOLEAN DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_email_hash ON users (email_hash)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE attachments');
        $this->addSql('DROP TABLE chat_participants');
        $this->addSql('DROP TABLE chats');
        $this->addSql('DROP TABLE login_audit');
        $this->addSql('DROP TABLE messages');
        $this->addSql('DROP TABLE password_reset_tokens');
        $this->addSql('DROP TABLE registration_tokens');
        $this->addSql('DROP TABLE users');
    }
}
