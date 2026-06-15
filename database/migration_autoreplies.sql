-- ============================================================================
-- EduTrack — Autoreply templates + parent message replies
-- Run once: mysql -u root edutrack_db < migration_autoreplies.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS `autoreply_templates` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `title`       VARCHAR(150)  NOT NULL COMMENT 'Admin-facing label, e.g. "Closing Dates"',
    `description` VARCHAR(300)  NOT NULL COMMENT 'Plain-language description of what this answers (used in AI prompt)',
    `reply_body`  TEXT          NOT NULL COMMENT 'The reply text sent to the parent',
    `is_active`   TINYINT(1)    NOT NULL DEFAULT 1,
    `created_by`  INT UNSIGNED  NOT NULL,
    `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_at_active` (`is_active`),
    CONSTRAINT `fk_at_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Admin-defined autoreply templates for common parent queries';


CREATE TABLE IF NOT EXISTS `parent_message_replies` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `message_id`  INT UNSIGNED  NOT NULL,
    `template_id` INT UNSIGNED  DEFAULT NULL,
    `reply_body`  TEXT          NOT NULL,
    `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_pmr_message` (`message_id`),
    CONSTRAINT `fk_pmr_message`
        FOREIGN KEY (`message_id`)  REFERENCES `parent_messages`(`id`)  ON DELETE CASCADE,
    CONSTRAINT `fk_pmr_template`
        FOREIGN KEY (`template_id`) REFERENCES `autoreply_templates`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='AI-generated autoreply messages sent to parents';
