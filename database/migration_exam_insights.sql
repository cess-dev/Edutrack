-- =============================================================================
-- EduTrack — Exam Insights Feature Migration
-- =============================================================================

-- TABLE: exam_uploads
CREATE TABLE IF NOT EXISTS `exam_uploads` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `unit_id`           INT UNSIGNED    NOT NULL,
    `lecturer_id`       INT UNSIGNED    NOT NULL,
    `academic_year`     VARCHAR(12)     NOT NULL,
    `semester`          TINYINT         NOT NULL DEFAULT 1,
    `exam_title`        VARCHAR(200)    NOT NULL COMMENT 'e.g. CAT 1, Final Exam 2025',
    `file_path`         VARCHAR(500)    NOT NULL,
    `original_filename` VARCHAR(255)    NOT NULL,
    `extracted_text`    LONGTEXT        DEFAULT NULL COMMENT 'Text extracted from PDF',
    `avg_score`         DECIMAL(5,2)    DEFAULT NULL COMMENT 'Lecturer-entered average score',
    `most_failed`       TEXT            DEFAULT NULL COMMENT 'Most failed questions/topics',
    `observations`      TEXT            DEFAULT NULL COMMENT 'General lecturer observations',
    `analysis_status`   ENUM('pending','analyzing','completed','failed')
                                        NOT NULL DEFAULT 'pending',
    `uploaded_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `analyzed_at`       DATETIME        DEFAULT NULL,

    PRIMARY KEY (`id`),
    KEY `idx_eu_unit`      (`unit_id`),
    KEY `idx_eu_lecturer`  (`lecturer_id`),
    KEY `idx_eu_unit_year` (`unit_id`, `academic_year`, `semester`),

    CONSTRAINT `fk_eu_unit`
        FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_eu_lecturer`
        FOREIGN KEY (`lecturer_id`) REFERENCES `users`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TABLE: exam_insights
CREATE TABLE IF NOT EXISTS `exam_insights` (
    `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `upload_id`       INT UNSIGNED  NOT NULL,
    `ai_summary`      TEXT          NOT NULL,
    `ai_suggestions`  JSON          NOT NULL,
    `ai_comparisons`  JSON          DEFAULT NULL,
    `model_used`      VARCHAR(100)  DEFAULT NULL,
    `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_ei_upload` (`upload_id`),

    CONSTRAINT `fk_ei_upload`
        FOREIGN KEY (`upload_id`) REFERENCES `exam_uploads`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TABLE: exam_suggestions
CREATE TABLE IF NOT EXISTS `exam_suggestions` (
    `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `insight_id`      INT UNSIGNED  NOT NULL,
    `suggestion_text` TEXT          NOT NULL,
    `category`        ENUM('teaching','assessment','content','student_support','other')
                                    NOT NULL DEFAULT 'other',
    `status`          ENUM('pending','executed','dismissed')
                                    NOT NULL DEFAULT 'pending',
    `follow_up_notes` TEXT          DEFAULT NULL,
    `executed_at`     DATETIME      DEFAULT NULL,
    `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_es_insight` (`insight_id`),

    CONSTRAINT `fk_es_insight`
        FOREIGN KEY (`insight_id`) REFERENCES `exam_insights`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
