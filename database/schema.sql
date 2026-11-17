-- Bulk Mailer schema for MySQL 9.7 LTS / MySQL 26.x (also works on 8.4 LTS).
--
-- MySQL stores user accounts (with their role) and the list of shared recipient
-- files with the one employee each file is assigned to. The recipient data itself,
-- and each file's sending progress, live on the server in storage/shared/f{id}/.
-- Form settings stay in the PHP session.
--
-- Run as an administrative user:   mysql -u root -p < database/schema.sql

CREATE DATABASE IF NOT EXISTS bulk_mailer
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_0900_ai_ci;

USE bulk_mailer;

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(120) NOT NULL,
    email         VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME     NULL,
    role          ENUM('admin', 'employee') NOT NULL DEFAULT 'employee',
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Recipient files uploaded by an admin. Each file is assigned to at most one employee.
CREATE TABLE IF NOT EXISTS shared_files (
    id               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    name             VARCHAR(190)    NOT NULL,
    recipients       INT UNSIGNED    NOT NULL DEFAULT 0,
    invalid_rows     INT UNSIGNED    NOT NULL DEFAULT 0,
    duplicate_rows   INT UNSIGNED    NOT NULL DEFAULT 0,
    size_bytes       BIGINT UNSIGNED NOT NULL DEFAULT 0,
    assigned_user_id INT UNSIGNED    NULL,
    uploaded_by      INT UNSIGNED    NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    assigned_at      DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shared_files_name (name),
    KEY idx_shared_files_assignee (assigned_user_id),
    CONSTRAINT fk_shared_files_assignee FOREIGN KEY (assigned_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_shared_files_uploader FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Application account with only the privileges the app needs.
-- MySQL 9+ uses caching_sha2_password (mysql_native_password no longer exists);
-- PHP's mysqlnd driver supports it. Change the password before running.
CREATE USER IF NOT EXISTS 'bulk_mailer'@'localhost' IDENTIFIED BY 'change-this-password';
GRANT SELECT, INSERT, UPDATE ON bulk_mailer.users TO 'bulk_mailer'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON bulk_mailer.shared_files TO 'bulk_mailer'@'localhost';

-- Then make yourself admin (after registering on the website):
--   php bin/make-admin.php you@example.com
