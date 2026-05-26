-- =============================================================================
-- EduTrack — Database Schema
-- =============================================================================
-- Import this file first via phpMyAdmin:
--   phpMyAdmin → edutrack_db → Import → choose this file → Go
--
-- Then import seed.sql to create the default admin account and sample data.
--
-- Engine:    InnoDB  (foreign key support + transactions)
-- Charset:   utf8mb4 (full Unicode — handles all languages + emoji)
-- Collation: utf8mb4_unicode_ci (case-insensitive, accent-aware sorting)
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+03:00"; -- Africa/Nairobi — change to match your timezone

-- =============================================================================
-- DROP existing tables (safe re-import)
-- Order matters: children before parents
-- =============================================================================
DROP TABLE IF EXISTS `disputes`;
DROP TABLE IF EXISTS `marks`;
DROP TABLE IF EXISTS `assessments`;
DROP TABLE IF EXISTS `attendance_logs`;
DROP TABLE IF EXISTS `attendance_sessions`;
DROP TABLE IF EXISTS `enrollments`;
DROP TABLE IF EXISTS `student_course_enrollments`;
DROP TABLE IF EXISTS `parent_student_links`;
DROP TABLE IF EXISTS `units`;
DROP TABLE IF EXISTS `courses`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `system_settings`;
DROP TABLE IF EXISTS `audit_logs`;

-- =============================================================================
-- TABLE: users
-- Central identity table for all roles (admin, lecturer, student, parent).
-- Role determines which portal the user accesses and what they can do.
-- =============================================================================
CREATE TABLE `users` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `reg_number`    VARCHAR(30)     NOT NULL COMMENT 'Registration/staff number — unique per user',
    `full_name`     VARCHAR(120)    NOT NULL,
    `email`         VARCHAR(120)    DEFAULT NULL,
    `phone`         VARCHAR(20)     DEFAULT NULL COMMENT 'Used for SMS alerts and parent OTP login',
    `password_hash` VARCHAR(255)    NOT NULL COMMENT 'bcrypt hash — never store plain text',
    `role`          ENUM(
                        'admin',
                        'lecturer',
                        'student',
                        'parent'
                    )               NOT NULL,
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '0 = deactivated, cannot log in',
    `last_login`    DATETIME        DEFAULT NULL,
    `created_by`    INT UNSIGNED    DEFAULT NULL COMMENT 'Admin user ID who created this account',
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_reg_number` (`reg_number`),
    KEY `idx_role` (`role`),
    KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='All system users regardless of role';


-- =============================================================================
-- TABLE: courses
-- A course is a programme of study (e.g. "Bachelor of Computer Science").
-- Each course contains multiple units.
-- =============================================================================
CREATE TABLE `courses` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `code`          VARCHAR(20)     NOT NULL COMMENT 'e.g. BCS, BCOM, BED',
    `name`          VARCHAR(150)    NOT NULL COMMENT 'Full course name',
    `department`    VARCHAR(100)    DEFAULT NULL,
    `duration_years`TINYINT         NOT NULL DEFAULT 4,
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_course_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Academic programmes / courses of study';


-- =============================================================================
-- TABLE: units
-- A unit is a single subject/module taught within a course.
-- Each unit is taught by one lecturer in a given semester and year of study.
-- =============================================================================
CREATE TABLE `units` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `course_id`     INT UNSIGNED    NOT NULL,
    `lecturer_id`   INT UNSIGNED    DEFAULT NULL COMMENT 'Assigned lecturer (users.id, role=lecturer)',
    `code`          VARCHAR(20)     NOT NULL COMMENT 'e.g. BCS101, BCOM201',
    `name`          VARCHAR(150)    NOT NULL,
    `semester`      TINYINT         NOT NULL DEFAULT 1 COMMENT '1 or 2',
    `year_of_study` TINYINT         NOT NULL DEFAULT 1 COMMENT '1–6 depending on course duration',
    `credit_hours`  TINYINT         NOT NULL DEFAULT 3,
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_unit_code` (`code`),
    KEY `idx_course_id` (`course_id`),
    KEY `idx_lecturer_id` (`lecturer_id`),

    CONSTRAINT `fk_units_course`
        FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,

    CONSTRAINT `fk_units_lecturer`
        FOREIGN KEY (`lecturer_id`) REFERENCES `users`(`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Individual subjects/modules within a course';


-- =============================================================================
-- TABLE: student_course_enrollments
-- Master enrollment record: links a student to a course for a given
-- academic year, semester, and year of study.
-- Unit-level enrollments (enrollments table) are DERIVED from this table.
-- When a new unit is added to a course, all students with a matching
-- student_course_enrollments record are auto-enrolled in that unit.
-- =============================================================================
CREATE TABLE `student_course_enrollments` (
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


-- =============================================================================
-- TABLE: enrollments
-- Links students to the units they are registered for in a given academic year.
-- A student can be enrolled in many units; a unit can have many students.
-- Rows are derived from student_course_enrollments — do not insert directly
-- (except via the enrollment model methods which handle both tables atomically).
-- =============================================================================
CREATE TABLE `enrollments` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `student_id`    INT UNSIGNED    NOT NULL,
    `unit_id`       INT UNSIGNED    NOT NULL,
    `academic_year` VARCHAR(12)     NOT NULL COMMENT 'e.g. 2024/2025',
    `semester`      TINYINT         NOT NULL DEFAULT 1,
    `enrolled_at`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_enrollment` (`student_id`, `unit_id`, `academic_year`, `semester`),
    KEY `idx_enrollment_unit` (`unit_id`),

    CONSTRAINT `fk_enrollments_student`
        FOREIGN KEY (`student_id`) REFERENCES `users`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_enrollments_unit`
        FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Student-to-unit registrations per academic year';


-- =============================================================================
-- TABLE: parent_student_links
-- Many-to-many: one parent can monitor multiple children;
-- one student can have multiple linked parent/guardian accounts.
-- =============================================================================
CREATE TABLE `parent_student_links` (
    `parent_id`     INT UNSIGNED    NOT NULL,
    `student_id`    INT UNSIGNED    NOT NULL,
    `relationship`  VARCHAR(50)     DEFAULT 'Parent' COMMENT 'e.g. Parent, Guardian, Sibling',
    `linked_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`parent_id`, `student_id`),
    KEY `idx_psl_student` (`student_id`),

    CONSTRAINT `fk_psl_parent`
        FOREIGN KEY (`parent_id`) REFERENCES `users`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_psl_student`
        FOREIGN KEY (`student_id`) REFERENCES `users`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Links parent accounts to student accounts';


-- =============================================================================
-- TABLE: attendance_sessions
-- One row per class session started by a lecturer.
-- The QR code encodes the session_token; the HMAC prevents forgery.
-- =============================================================================
CREATE TABLE `attendance_sessions` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `unit_id`       INT UNSIGNED    NOT NULL,
    `lecturer_id`   INT UNSIGNED    NOT NULL,
    `academic_year` VARCHAR(12)     NOT NULL,
    `semester`      TINYINT         NOT NULL DEFAULT 1,
    `session_token` VARCHAR(64)     NOT NULL COMMENT 'Random hex token encoded in the QR',
    `token_hmac`    VARCHAR(128)    NOT NULL COMMENT 'HMAC-SHA256 signature of the token',
    `started_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`    DATETIME        NOT NULL COMMENT 'Token invalid after this timestamp',
    `closed_at`     DATETIME        DEFAULT NULL COMMENT 'When lecturer closed or timer expired',
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '0 = closed, no more scans accepted',
    `note`          VARCHAR(255)    DEFAULT NULL COMMENT 'Optional session note from lecturer',

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_session_token` (`session_token`),
    KEY `idx_session_unit` (`unit_id`),
    KEY `idx_session_lecturer` (`lecturer_id`),
    KEY `idx_session_active` (`is_active`),

    CONSTRAINT `fk_sessions_unit`
        FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,

    CONSTRAINT `fk_sessions_lecturer`
        FOREIGN KEY (`lecturer_id`) REFERENCES `users`(`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Individual class attendance sessions with QR tokens';


-- =============================================================================
-- TABLE: attendance_logs
-- One row per student per session.
-- Inserted when a student scans the QR (method=qr_scan) or marked manually.
-- Absent rows are inserted in bulk when a session is closed.
-- =============================================================================
CREATE TABLE `attendance_logs` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `session_id`    INT UNSIGNED    NOT NULL,
    `student_id`    INT UNSIGNED    NOT NULL,
    `status`        ENUM(
                        'present',
                        'absent',
                        'excused'
                    )               NOT NULL DEFAULT 'absent',
    `method`        ENUM(
                        'qr_scan',
                        'manual',
                        'auto_absent'
                    )               NOT NULL DEFAULT 'qr_scan',
    `scanned_at`    DATETIME        DEFAULT NULL COMMENT 'Null for absent/excused rows',
    `marked_by`     INT UNSIGNED    DEFAULT NULL COMMENT 'Lecturer ID if method=manual',
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_log_session_student` (`session_id`, `student_id`),
    KEY `idx_log_student` (`student_id`),
    KEY `idx_log_status` (`status`),

    CONSTRAINT `fk_logs_session`
        FOREIGN KEY (`session_id`) REFERENCES `attendance_sessions`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_logs_student`
        FOREIGN KEY (`student_id`) REFERENCES `users`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_logs_marked_by`
        FOREIGN KEY (`marked_by`) REFERENCES `users`(`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-student attendance record for each session';


-- =============================================================================
-- TABLE: assessments
-- Defines the graded components of a unit (CAT 1, Final Exam, Assignment, etc.)
-- Weights must sum to 100 per unit but this is enforced in PHP, not the DB.
-- =============================================================================
CREATE TABLE `assessments` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `unit_id`       INT UNSIGNED    NOT NULL,
    `name`          VARCHAR(100)    NOT NULL COMMENT 'e.g. CAT 1, Final Exam, Practical 1',
    `type`          ENUM(
                        'cat',
                        'assignment',
                        'practical',
                        'project',
                        'final_exam'
                    )               NOT NULL DEFAULT 'cat',
    `max_score`     DECIMAL(6,2)    NOT NULL DEFAULT 100.00 COMMENT 'Maximum possible marks',
    `weight_percent`DECIMAL(5,2)    NOT NULL DEFAULT 0.00 COMMENT 'Contribution to final grade (%)',
    `assessment_date` DATE          DEFAULT NULL,
    `is_published`  TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = students can see their marks',
    `created_by`    INT UNSIGNED    DEFAULT NULL,
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_assessment_unit` (`unit_id`),

    CONSTRAINT `fk_assessments_unit`
        FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_assessments_creator`
        FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Graded components (CATs, exams, practicals) per unit';


-- =============================================================================
-- TABLE: marks
-- One row per student per assessment.
-- The UNIQUE constraint prevents a student being scored twice for the same item.
-- =============================================================================
CREATE TABLE `marks` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `student_id`    INT UNSIGNED    NOT NULL,
    `assessment_id` INT UNSIGNED    NOT NULL,
    `score`         DECIMAL(6,2)    NOT NULL,
    `uploaded_by`   INT UNSIGNED    NOT NULL COMMENT 'Lecturer who entered/uploaded the mark',
    `uploaded_at`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_mark_student_assessment` (`student_id`, `assessment_id`),
    KEY `idx_marks_assessment` (`assessment_id`),

    CONSTRAINT `fk_marks_student`
        FOREIGN KEY (`student_id`) REFERENCES `users`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_marks_assessment`
        FOREIGN KEY (`assessment_id`) REFERENCES `assessments`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_marks_uploader`
        FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Individual student scores per assessment';


-- =============================================================================
-- TABLE: disputes
-- Students raise a dispute when they believe they were wrongly marked absent.
-- Lecturers review and approve or reject with a reason.
-- =============================================================================
CREATE TABLE `disputes` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `student_id`    INT UNSIGNED    NOT NULL,
    `session_id`    INT UNSIGNED    NOT NULL,
    `reason`        TEXT            NOT NULL COMMENT 'Student explanation of why they were present',
    `status`        ENUM(
                        'pending',
                        'approved',
                        'rejected'
                    )               NOT NULL DEFAULT 'pending',
    `reviewer_id`   INT UNSIGNED    DEFAULT NULL COMMENT 'Lecturer who reviewed the dispute',
    `reviewer_note` TEXT            DEFAULT NULL,
    `reviewed_at`   DATETIME        DEFAULT NULL,
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_dispute_student_session` (`student_id`, `session_id`),
    KEY `idx_dispute_status` (`status`),
    KEY `idx_dispute_session` (`session_id`),

    CONSTRAINT `fk_disputes_student`
        FOREIGN KEY (`student_id`) REFERENCES `users`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_disputes_session`
        FOREIGN KEY (`session_id`) REFERENCES `attendance_sessions`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_disputes_reviewer`
        FOREIGN KEY (`reviewer_id`) REFERENCES `users`(`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Student attendance dispute submissions and reviewer decisions';


-- =============================================================================
-- TABLE: system_settings
-- Key-value store for runtime configuration that admins can change
-- without editing config.php (e.g. school name, alert threshold).
-- =============================================================================
CREATE TABLE `system_settings` (
    `setting_key`   VARCHAR(80)     NOT NULL,
    `setting_value` TEXT            DEFAULT NULL,
    `description`   VARCHAR(255)    DEFAULT NULL,
    `updated_by`    INT UNSIGNED    DEFAULT NULL,
    `updated_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`setting_key`),

    CONSTRAINT `fk_settings_updater`
        FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Runtime admin-editable settings stored in the database';


-- =============================================================================
-- TABLE: audit_logs
-- Immutable record of important system actions for admin review.
-- Rows are INSERT-only — never updated or deleted.
-- =============================================================================
CREATE TABLE `audit_logs` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED    DEFAULT NULL COMMENT 'Who performed the action (null = system)',
    `action`        VARCHAR(80)     NOT NULL COMMENT 'e.g. user_created, marks_uploaded, session_closed',
    `target_type`   VARCHAR(50)     DEFAULT NULL COMMENT 'Table/entity affected e.g. users, marks',
    `target_id`     INT UNSIGNED    DEFAULT NULL COMMENT 'ID of the affected record',
    `detail`        JSON            DEFAULT NULL COMMENT 'Extra context as JSON',
    `ip_address`    VARCHAR(45)     DEFAULT NULL COMMENT 'Supports IPv4 and IPv6',
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_audit_user` (`user_id`),
    KEY `idx_audit_action` (`action`),
    KEY `idx_audit_created` (`created_at`),

    CONSTRAINT `fk_audit_user`
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Immutable audit trail of system actions';


-- =============================================================================
-- VIEWS
-- Pre-built queries used frequently across the application.
-- =============================================================================

-- Attendance summary per student per unit
-- Returns: how many sessions were held, how many the student attended,
--          and the attendance percentage. Used by student, parent, and lecturer portals.
CREATE OR REPLACE VIEW `vw_attendance_summary` AS
SELECT
    e.student_id,
    e.unit_id,
    e.academic_year,
    e.semester,
    u.full_name                                             AS student_name,
    un.code                                                 AS unit_code,
    un.name                                                 AS unit_name,
    COUNT(DISTINCT s.id)                                    AS total_sessions,
    COUNT(DISTINCT CASE WHEN al.status = 'present' THEN al.session_id END) AS attended,
    COUNT(DISTINCT CASE WHEN al.status = 'absent'  THEN al.session_id END) AS absent,
    COUNT(DISTINCT CASE WHEN al.status = 'excused' THEN al.session_id END) AS excused,
    ROUND(
        COUNT(DISTINCT CASE WHEN al.status = 'present' THEN al.session_id END)
        / NULLIF(COUNT(DISTINCT s.id), 0) * 100,
        1
    )                                                       AS attendance_percent
FROM enrollments e
JOIN users u   ON u.id = e.student_id
JOIN units un  ON un.id = e.unit_id
LEFT JOIN attendance_sessions s
    ON s.unit_id = e.unit_id
    AND s.academic_year = e.academic_year
    AND s.semester = e.semester
    AND s.is_active = 0
LEFT JOIN attendance_logs al
    ON al.session_id = s.id
    AND al.student_id = e.student_id
GROUP BY
    e.student_id, e.unit_id, e.academic_year, e.semester,
    u.full_name, un.code, un.name;


-- Weighted final grade per student per unit
-- Multiplies each mark by the assessment's weight, sums to get a final grade out of 100.
CREATE OR REPLACE VIEW `vw_unit_grades` AS
SELECT
    m.student_id,
    a.unit_id,
    u.full_name                                                 AS student_name,
    un.code                                                     AS unit_code,
    un.name                                                     AS unit_name,
    ROUND(
        SUM((m.score / a.max_score) * a.weight_percent),
        2
    )                                                           AS weighted_total,
    COUNT(m.id)                                                 AS assessments_submitted,
    COUNT(a.id)                                                 AS assessments_total
FROM marks m
JOIN assessments a  ON a.id = m.assessment_id
JOIN users u        ON u.id = m.student_id
JOIN units un       ON un.id = a.unit_id
WHERE a.is_published = 1
GROUP BY m.student_id, a.unit_id, u.full_name, un.code, un.name;


SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- End of schema.sql
-- Next: import database/seed.sql
-- =============================================================================