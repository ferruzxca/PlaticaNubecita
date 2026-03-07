-- PlaticaNubecita
-- MySQL/MariaDB schema for Hostinger deployments
-- Generated from the project's Doctrine migrations as of 2026-03-07

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS attachments;
DROP TABLE IF EXISTS messages;
DROP TABLE IF EXISTS chat_participants;
DROP TABLE IF EXISTS password_reset_tokens;
DROP TABLE IF EXISTS registration_tokens;
DROP TABLE IF EXISTS login_audit;
DROP TABLE IF EXISTS chats;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS doctrine_migration_versions;

CREATE TABLE doctrine_migration_versions (
    version VARCHAR(191) NOT NULL,
    executed_at DATETIME DEFAULT NULL,
    execution_time INT DEFAULT NULL,
    PRIMARY KEY(version)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE users (
    id INT AUTO_INCREMENT NOT NULL,
    display_name VARCHAR(80) NOT NULL,
    email_ciphertext LONGBLOB NOT NULL,
    email_hash VARCHAR(64) NOT NULL,
    status_ciphertext LONGBLOB DEFAULT NULL,
    avatar_blob LONGBLOB DEFAULT NULL,
    avatar_nonce VARBINARY(24) DEFAULT NULL,
    avatar_key_version INT NOT NULL DEFAULT 1,
    avatar_mime_ciphertext LONGBLOB DEFAULT NULL,
    roles JSON NOT NULL,
    password VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_bot TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_user_email_hash (email_hash),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE registration_tokens (
    id INT AUTO_INCREMENT NOT NULL,
    email_hash VARCHAR(64) NOT NULL,
    email_ciphertext LONGBLOB NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_registration_email_hash (email_hash),
    UNIQUE KEY uniq_registration_token_hash (token_hash),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE password_reset_tokens (
    id INT AUTO_INCREMENT NOT NULL,
    user_id INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL,
    INDEX IDX_3967A216A76ED395 (user_id),
    UNIQUE KEY uniq_password_reset_token_hash (token_hash),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE chats (
    id INT AUTO_INCREMENT NOT NULL,
    owner_id INT DEFAULT NULL,
    type VARCHAR(20) NOT NULL,
    pair_hash VARCHAR(64) DEFAULT NULL,
    name_ciphertext LONGBLOB DEFAULT NULL,
    name_nonce VARBINARY(24) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    INDEX IDX_CHATS_OWNER_ID (owner_id),
    UNIQUE KEY uniq_chat_pair_hash (pair_hash),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE chat_participants (
    id INT AUTO_INCREMENT NOT NULL,
    chat_id INT NOT NULL,
    user_id INT NOT NULL,
    joined_at DATETIME NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'member',
    INDEX IDX_8F8219131A9A7125 (chat_id),
    INDEX IDX_8F821913A76ED395 (user_id),
    UNIQUE KEY uniq_chat_user (chat_id, user_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE messages (
    id INT AUTO_INCREMENT NOT NULL,
    chat_id INT NOT NULL,
    sender_id INT NOT NULL,
    ciphertext LONGBLOB DEFAULT NULL,
    nonce VARBINARY(24) DEFAULT NULL,
    key_version INT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    INDEX IDX_DB021E96F624B39D (sender_id),
    INDEX idx_message_chat_id (chat_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE attachments (
    id INT AUTO_INCREMENT NOT NULL,
    message_id INT NOT NULL,
    cipher_blob LONGBLOB NOT NULL,
    nonce VARBINARY(24) NOT NULL,
    key_version INT NOT NULL DEFAULT 1,
    mime_ciphertext LONGBLOB NOT NULL,
    filename_ciphertext LONGBLOB NOT NULL,
    size_bytes INT NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX IDX_47C4FAD6537A1329 (message_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE login_audit (
    id INT AUTO_INCREMENT NOT NULL,
    email_hash VARCHAR(64) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    success TINYINT(1) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_login_audit_created_at (created_at),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

ALTER TABLE password_reset_tokens
    ADD CONSTRAINT FK_3967A216A76ED395
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;

ALTER TABLE chats
    ADD CONSTRAINT FK_CHATS_OWNER_ID
    FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE chat_participants
    ADD CONSTRAINT FK_8F8219131A9A7125
    FOREIGN KEY (chat_id) REFERENCES chats (id) ON DELETE CASCADE;

ALTER TABLE chat_participants
    ADD CONSTRAINT FK_8F821913A76ED395
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;

ALTER TABLE messages
    ADD CONSTRAINT FK_DB021E961A9A7125
    FOREIGN KEY (chat_id) REFERENCES chats (id) ON DELETE CASCADE;

ALTER TABLE messages
    ADD CONSTRAINT FK_DB021E96F624B39D
    FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE CASCADE;

ALTER TABLE attachments
    ADD CONSTRAINT FK_47C4FAD6537A1329
    FOREIGN KEY (message_id) REFERENCES messages (id) ON DELETE CASCADE;

INSERT IGNORE INTO doctrine_migration_versions (version, executed_at, execution_time) VALUES
    ('DoctrineMigrations\\Version20260227214856', NOW(), 0),
    ('DoctrineMigrations\\Version20260302193000', NOW(), 0);

SET FOREIGN_KEY_CHECKS = 1;
