-- ============================================================================
-- EduTrack — Parent contact messages table
-- Run once: mysql -u root edutrack_db < migration_parent_messages.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS `parent_messages` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `parent_id`  INT UNSIGNED  NOT NULL,
    `subject`    VARCHAR(200)  NOT NULL,
    `body`       TEXT          NOT NULL,
    `urgency`    TINYINT UNSIGNED NOT NULL DEFAULT 3
                 COMMENT '1=Urgent 2=High 3=Normal 4=Low 5=Informational',
    `status`     ENUM('unread','read') NOT NULL DEFAULT 'unread',
    `read_by`    INT UNSIGNED  DEFAULT NULL,
    `read_at`    DATETIME      DEFAULT NULL,
    `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_pm_parent`  (`parent_id`),
    KEY `idx_pm_urgency` (`urgency`),
    KEY `idx_pm_status`  (`status`),

    CONSTRAINT `fk_pm_parent`
        FOREIGN KEY (`parent_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pm_reader`
        FOREIGN KEY (`read_by`)   REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Messages sent from parents to the school administration';
