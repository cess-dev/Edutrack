-- ============================================================================
-- EduTrack — Make urgency nullable so AI assigns it after submission
-- Run once: mysql -u root edutrack_db < migration_messages_nullable_urgency.sql
-- ============================================================================

ALTER TABLE `parent_messages`
    MODIFY COLUMN `urgency` TINYINT UNSIGNED DEFAULT NULL
        COMMENT 'AI-assigned: 1=Urgent 2=High 3=Normal 4=Low 5=Informational. NULL = not yet ranked.';
