-- Upgrade an existing 1.x database to 2.x (roles + shared recipient files).
-- Run once as an administrative user:
--   mysql -u root -p bulk_mailer < database/migrations/2.0-shared-files.sql
-- Then make yourself admin:  php bin/make-admin.php you@example.com

ALTER TABLE users
    ADD COLUMN role ENUM('admin', 'employee') NOT NULL DEFAULT 'employee';

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- If the app connects with its own limited account (as in database/schema.sql):
-- GRANT SELECT, INSERT, UPDATE, DELETE ON bulk_mailer.shared_files TO 'bulk_mailer'@'localhost';
