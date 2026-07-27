-- ===========================================================
--  FileShare — Full database schema (MySQL 5.7+ / MariaDB 10.3+)
--  Compatible with InfinityFree and XAMPP (localhost).
--  Charset: utf8mb4 / collation utf8mb4_unicode_ci
-- ===========================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------
--  settings : key/value store for site configuration
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `name`       VARCHAR(64)  NOT NULL,
  `value`      TEXT         NULL,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
--  admins : panel users (password hashed with password_hash)
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admins` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`     VARCHAR(32)  NOT NULL,
  `password`     VARCHAR(255) NOT NULL,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
--  packages : a group of uploaded files sharing one code
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `packages` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`         VARCHAR(32)  NOT NULL,
  `name`         VARCHAR(120) NOT NULL,
  `expires_at`   DATETIME     NULL,
  `downloads`    INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_package_code` (`code`),
  KEY `idx_package_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
--  files : individual files belonging to a package
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `files` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `package_id`     BIGINT UNSIGNED NOT NULL,
  `original_name`  VARCHAR(255) NOT NULL,
  `stored_name`    VARCHAR(255) NOT NULL,
  `mime`           VARCHAR(120) NOT NULL DEFAULT 'application/octet-stream',
  `size`           BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `downloads`      INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_file_package` (`package_id`),
  CONSTRAINT `fk_file_package`
    FOREIGN KEY (`package_id`) REFERENCES `packages` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
--  logs : audit / error log written from PHP
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `level`      VARCHAR(16)  NOT NULL DEFAULT 'info',
  `message`    TEXT         NOT NULL,
  `context`    TEXT         NULL,
  `ip`         VARCHAR(45)  NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_log_created` (`created_at`),
  KEY `idx_log_level`   (`level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ===========================================================
--  Default seed data
-- ===========================================================

-- Default site settings (idempotent insert)
INSERT INTO `settings` (`name`, `value`) VALUES
  ('site_name',       'FileVault'),
  ('site_tagline',    'Share files instantly with a single code'),
  ('maintenance',     '0'),
  ('max_upload_mb',   '500'),
  ('allow_uploads',   '1'),
  ('footer_text',     'FileVault — secure file sharing')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- Default admin  (username: admin  /  password: admin123)
-- Hash generated with password_hash('admin123', PASSWORD_DEFAULT)
INSERT INTO `admins` (`username`, `password`) VALUES
  ('admin', '$2y$12$ETXQ3MYgiY0TIjIHI2bhG.iqnDf5muGWQXUzq3bEssmqp0hWLasAi')
ON DUPLICATE KEY UPDATE `username` = VALUES(`username`);
