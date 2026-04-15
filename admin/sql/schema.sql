-- UPOU HelpDesk Admin App — MySQL schema
-- Run: mysql -u root -p < sql/schema.sql

CREATE DATABASE IF NOT EXISTS upou_admin
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE upou_admin;

-- Admin/agent accounts (separate from the main helpdesk user table)
CREATE TABLE IF NOT EXISTS admin_users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(32)  NOT NULL UNIQUE,
    email         VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('admin', 'agent') NOT NULL DEFAULT 'agent',
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL,
    last_login_at DATETIME     NULL,
    INDEX idx_admin_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional audit log for admin actions (who did what when)
CREATE TABLE IF NOT EXISTS audit_log (
    id         BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    username   VARCHAR(32) NOT NULL,
    action     VARCHAR(64) NOT NULL,
    ticket_id  VARCHAR(64) NULL,
    details    TEXT        NULL,
    created_at DATETIME    NOT NULL,
    INDEX idx_audit_user (user_id, created_at),
    INDEX idx_audit_ticket (ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Application MySQL user (change password before running in production)
CREATE USER IF NOT EXISTS 'upou_admin_app'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON upou_admin.* TO 'upou_admin_app'@'localhost';
FLUSH PRIVILEGES;
