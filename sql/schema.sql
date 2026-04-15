-- UPOU AI HelpDesk - MySQL schema
-- Run: mysql -u root -p < sql/schema.sql

CREATE DATABASE IF NOT EXISTS upou_helpdesk
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE upou_helpdesk;

CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(32)  NOT NULL UNIQUE,
    email         VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at    DATETIME     NOT NULL,
    INDEX idx_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_history (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    question        TEXT NOT NULL,
    answer          MEDIUMTEXT NOT NULL,
    source_label    VARCHAR(40)  NULL,
    top_similarity  DECIMAL(6,4) NULL,
    ticket_id       VARCHAR(64)  NULL,
    created_at      DATETIME NOT NULL,
    INDEX idx_chat_user (user_id, created_at),
    CONSTRAINT fk_chat_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Application user (change the password before running in production)
CREATE USER IF NOT EXISTS 'upou_app'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON upou_helpdesk.* TO 'upou_app'@'localhost';
FLUSH PRIVILEGES;
