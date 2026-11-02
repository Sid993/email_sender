-- Bulk Mailer schema for MySQL 9.7 LTS / MySQL 26.x (also works on 8.4 LTS).
--
-- Only user accounts are stored permanently. Campaign configuration, recipient
-- data, progress and per-email results are temporary (session + per-user workspace)
-- and are removed after the campaign finishes.
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
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Application account with only the privileges the app needs.
-- MySQL 9+ uses caching_sha2_password (mysql_native_password no longer exists);
-- PHP's mysqlnd driver supports it. Change the password before running.
CREATE USER IF NOT EXISTS 'bulk_mailer'@'localhost' IDENTIFIED BY 'change-this-password';
GRANT SELECT, INSERT, UPDATE ON bulk_mailer.users TO 'bulk_mailer'@'localhost';
