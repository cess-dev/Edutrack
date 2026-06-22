-- =============================================================================
-- EduTrack — Timetable Feature Migration
-- Import via phpMyAdmin → edutrack_db → Import → choose this file → Go
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- TABLE: timetables
-- One row per uploaded timetable file. Stores the file path and extraction
-- status so lecturers can review AI output before committing.
-- =============================================================================
CREATE TABLE IF NOT EXISTS `timetables` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `lecturer_id`       INT UNSIGNED    NOT NULL,
    `academic_year`     VARCHAR(12)     NOT NULL COMMENT 'e.g. 2025/2026',
    `semester`          TINYINT         NOT NULL DEFAULT 1,
    `file_path`         VARCHAR(500)    NOT NULL COMMENT 'Absolute path under /uploads/timetables/',
    `original_filename` VARCHAR(255)    NOT NULL,
    `file_type`         ENUM('image','pdf','csv') NOT NULL DEFAULT 'image',
    `extraction_status` ENUM('pending','extracted','confirmed','failed') NOT NULL DEFAULT 'pending',
    `raw_extraction`    JSON            DEFAULT NULL COMMENT 'Raw JSON array returned by vision model',
    `uploaded_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `confirmed_at`      DATETIME        DEFAULT NULL,

    PRIMARY KEY (`id`),
    KEY `idx_tt_lecturer` (`lecturer_id`),
    KEY `idx_tt_status`   (`extraction_status`),

    CONSTRAINT `fk_tt_lecturer`
        FOREIGN KEY (`lecturer_id`) REFERENCES `users`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Uploaded timetable files with AI extraction status';


-- =============================================================================
-- TABLE: class_schedules
-- Individual class slots extracted (and confirmed) from a timetable.
-- unit_id is nullable — some extracted rows may not match a unit in the DB.
-- =============================================================================
CREATE TABLE IF NOT EXISTS `class_schedules` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `timetable_id`  INT UNSIGNED    NOT NULL,
    `unit_id`       INT UNSIGNED    DEFAULT NULL COMMENT 'Matched unit — null if code not found in DB',
    `unit_code`     VARCHAR(20)     NOT NULL COMMENT 'As extracted from timetable',
    `unit_name`     VARCHAR(150)    DEFAULT NULL,
    `day_of_week`   TINYINT         NOT NULL COMMENT '1=Monday … 7=Sunday',
    `start_time`    TIME            NOT NULL,
    `end_time`      TIME            NOT NULL,
    `room`          VARCHAR(100)    DEFAULT NULL,

    PRIMARY KEY (`id`),
    KEY `idx_cs_timetable` (`timetable_id`),
    KEY `idx_cs_unit`      (`unit_id`),
    KEY `idx_cs_day`       (`day_of_week`),

    CONSTRAINT `fk_cs_timetable`
        FOREIGN KEY (`timetable_id`) REFERENCES `timetables`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_cs_unit`
        FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Individual class slots from confirmed timetables';


-- =============================================================================
-- TABLE: notification_preferences
-- One row per lecturer. Controls whether and how far ahead to email them.
-- =============================================================================
CREATE TABLE IF NOT EXISTS `notification_preferences` (
    `lecturer_id`           INT UNSIGNED    NOT NULL,
    `email_enabled`         TINYINT(1)      NOT NULL DEFAULT 0,
    `notify_before_minutes` INT             NOT NULL DEFAULT 30
                            COMMENT 'Send email this many minutes before class',
    `updated_at`            TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`lecturer_id`),

    CONSTRAINT `fk_np_lecturer`
        FOREIGN KEY (`lecturer_id`) REFERENCES `users`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-lecturer email notification settings for class reminders';


-- =============================================================================
-- TABLE: student_notification_preferences
-- One row per student. Controls whether and how far ahead to email them.
-- =============================================================================
CREATE TABLE IF NOT EXISTS `student_notification_preferences` (
    `student_id`            INT UNSIGNED    NOT NULL,
    `email_enabled`         TINYINT(1)      NOT NULL DEFAULT 0,
    `notify_before_minutes` INT             NOT NULL DEFAULT 30
                            COMMENT 'Send email this many minutes before class',
    `updated_at`            TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`student_id`),

    CONSTRAINT `fk_snp_student`
        FOREIGN KEY (`student_id`) REFERENCES `users`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-student email notification settings for class reminders';


-- =============================================================================
-- TABLE: notification_log
-- Tracks which reminders were sent to prevent duplicates when the cron runs.
-- =============================================================================
CREATE TABLE IF NOT EXISTS `notification_log` (
    `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `lecturer_id`    INT UNSIGNED    NOT NULL,
    `schedule_id`    INT UNSIGNED    NOT NULL,
    `scheduled_date` DATE            NOT NULL COMMENT 'The calendar date of the class',
    `sent_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_notif` (`lecturer_id`, `schedule_id`, `scheduled_date`),
    KEY `idx_nl_lecturer` (`lecturer_id`),

    CONSTRAINT `fk_nl_lecturer`
        FOREIGN KEY (`lecturer_id`) REFERENCES `users`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_nl_schedule`
        FOREIGN KEY (`schedule_id`) REFERENCES `class_schedules`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit log of sent class reminder emails (prevents duplicate sends)';

-- =============================================================================
-- TABLE: notification_queue
-- Pre-computed email send times. Populated when a timetable is confirmed.
-- The cron script runs once daily and fires timed background sends from this table.
-- Rows are purged after sending (or after the date passes).
-- =============================================================================
CREATE TABLE IF NOT EXISTS `notification_queue` (
    `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `lecturer_id`    INT UNSIGNED    NOT NULL,
    `schedule_id`    INT UNSIGNED    NOT NULL COMMENT 'class_schedules row',
    `send_at`        DATETIME        NOT NULL COMMENT 'Exact datetime to send the email',
    `class_date`     DATE            NOT NULL COMMENT 'Calendar date of the class',
    `sent`           TINYINT(1)      NOT NULL DEFAULT 0,
    `sent_at`        DATETIME        DEFAULT NULL,
    `created_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nq` (`lecturer_id`, `schedule_id`, `class_date`),
    KEY `idx_nq_send_at`  (`send_at`),
    KEY `idx_nq_lecturer` (`lecturer_id`),

    CONSTRAINT `fk_nq_lecturer`
        FOREIGN KEY (`lecturer_id`) REFERENCES `users`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_nq_schedule`
        FOREIGN KEY (`schedule_id`) REFERENCES `class_schedules`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Pre-scheduled email send times for class reminders';

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- End of migration_timetable.sql
-- =============================================================================
