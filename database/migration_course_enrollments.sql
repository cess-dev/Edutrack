-- =============================================================================
-- EduTrack Migration — Course-Level Enrollment
-- =============================================================================
-- Run this ONCE on an existing database that was already imported from schema.sql.
-- If you are doing a fresh import, just use schema.sql + seed.sql instead.
--
-- Usage (phpMyAdmin):
--   edutrack_db → Import → choose this file → Go
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- Add the master enrollment table (idempotent — does nothing if already exists)
CREATE TABLE IF NOT EXISTS `student_course_enrollments` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `student_id`    INT UNSIGNED    NOT NULL,
    `course_id`     INT UNSIGNED    NOT NULL,
    `year_of_study` TINYINT         NOT NULL COMMENT '1–6 matching the course duration',
    `academic_year` VARCHAR(12)     NOT NULL COMMENT 'e.g. 2025/2026 — pulled from system settings',
    `semester`      TINYINT         NOT NULL DEFAULT 1,
    `source`        ENUM('manual','csv') NOT NULL DEFAULT 'manual',
    `enrolled_at`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `enrolled_by`   INT UNSIGNED    DEFAULT NULL COMMENT 'Admin who created this record',

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sce` (`student_id`, `course_id`, `academic_year`, `semester`),
    KEY `idx_sce_course_period` (`course_id`, `year_of_study`, `academic_year`, `semester`),
    KEY `idx_sce_student` (`student_id`),

    CONSTRAINT `fk_sce_student`
        FOREIGN KEY (`student_id`) REFERENCES `users`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_sce_course`
        FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_sce_enrolled_by`
        FOREIGN KEY (`enrolled_by`) REFERENCES `users`(`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Master record: student enrolled in a course for a specific academic year+semester';

SET FOREIGN_KEY_CHECKS = 1;

-- NOTE: Existing rows in the `enrollments` table (unit-level) are NOT
-- back-filled into student_course_enrollments automatically, because we
-- cannot reliably determine the year_of_study for historical records.
-- Going forward, all new enrollments will create both the SCE master record
-- and the derived unit-level enrollment rows atomically.
