-- ============================================================================
-- EduTrack — Parent AI Chat tables
-- Run once: mysql -u root edutrack_db < migration_parent_ai.sql
-- ============================================================================

-- Parent-reported absences (via AI chat)
CREATE TABLE IF NOT EXISTS `parent_absence_reports` (
    `id`          INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `parent_id`   INT UNSIGNED   NOT NULL,
    `student_id`  INT UNSIGNED   NOT NULL,
    `report_date` DATE           NOT NULL,
    `reason`      VARCHAR(500)   NOT NULL,
    `notes`       TEXT           DEFAULT NULL,
    `status`      ENUM('pending','acknowledged') NOT NULL DEFAULT 'pending',
    `created_at`  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_par_student`  (`student_id`),
    KEY `idx_par_date`     (`report_date`),
    KEY `idx_par_status`   (`status`),

    CONSTRAINT `fk_par_parent`
        FOREIGN KEY (`parent_id`)  REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_par_student`
        FOREIGN KEY (`student_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Absence/sick reports submitted by parents via the AI chat assistant';


-- Escalated incidents logged by the AI (bullying, missing child, medical, staff complaint)
CREATE TABLE IF NOT EXISTS `parent_ai_incidents` (
    `id`                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `parent_id`            INT UNSIGNED  NOT NULL,
    `student_id`           INT UNSIGNED  DEFAULT NULL COMMENT 'NULL if child not identified',
    `type`                 ENUM(
                               'missing_child',
                               'medical_emergency',
                               'bullying',
                               'staff_complaint',
                               'general_concern'
                           ) NOT NULL,
    `description`          TEXT          NOT NULL,
    `conversation_snippet` TEXT          DEFAULT NULL,
    `status`               ENUM('open','reviewed','closed') NOT NULL DEFAULT 'open',
    `reviewed_by`          INT UNSIGNED  DEFAULT NULL,
    `reviewed_at`          DATETIME      DEFAULT NULL,
    `created_at`           TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_inc_status`   (`status`),
    KEY `idx_inc_type`     (`type`),
    KEY `idx_inc_parent`   (`parent_id`),

    CONSTRAINT `fk_inc_parent`
        FOREIGN KEY (`parent_id`)  REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_inc_reviewer`
        FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Urgent incidents escalated from parent AI chat to the admin office';


-- School knowledge base — admins add entries the AI can reference
CREATE TABLE IF NOT EXISTS `school_knowledge` (
    `id`         INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `category`   VARCHAR(80)    NOT NULL COMMENT 'e.g. fees, timetable, uniform, holidays',
    `question`   VARCHAR(255)   NOT NULL,
    `answer`     TEXT           NOT NULL,
    `is_active`  TINYINT(1)     NOT NULL DEFAULT 1,
    `updated_at` TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_sk_category` (`category`),
    KEY `idx_sk_active`   (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='School info the parent AI assistant can answer questions from';


-- Seed with placeholder entries (admin should update these)
-- Admins should update these entries with real dates/policies.
-- The AI will only answer from these entries — blank or missing info
-- makes it respond "I don't have that information. Please contact the school office."
INSERT INTO `school_knowledge` (`category`, `question`, `answer`) VALUES
('fees',     'When are school fees due?',          'Fee deadline information has not been added to the system yet. Please contact the school admin office directly for the exact amount and due date.'),
('holidays', 'When are the school holidays?',      'The term calendar has not been added to the system yet. Please contact the school admin office or check the notice board for holiday dates.'),
('uniform',  'What is the school uniform policy?', 'Students are required to wear the full school uniform on all school days. Contact the admin office for the detailed uniform policy.'),
('exams',    'When are the exams?',                'Exam timetables have not been added to the system yet. Please check the school notice board or contact the admin office.');
