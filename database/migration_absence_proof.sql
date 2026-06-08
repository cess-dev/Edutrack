-- ============================================================================
-- EduTrack — Add proof_description to parent_absence_reports
-- Run once: mysql -u root edutrack_db < migration_absence_proof.sql
-- ============================================================================

ALTER TABLE `parent_absence_reports`
    ADD COLUMN `proof_description` VARCHAR(500) DEFAULT NULL
        COMMENT 'Evidence or proof the parent described (doctor note, etc.)'
        AFTER `reason`;
