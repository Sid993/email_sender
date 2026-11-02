-- Bulk Mailer schema (MySQL 8 / MariaDB 10.5+)
-- Only user accounts are stored permanently. Campaign configuration, recipient
-- data, progress and per-email results are temporary (session + per-user workspace)
-- and are removed after the campaign finishes.

CREATE DATABASE IF NOT EXISTS bulk_mailer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
