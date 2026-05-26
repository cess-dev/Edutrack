-- =============================================================================
-- EduTrack Migration — must_change_password flag
-- =============================================================================
-- Run this ONCE on an existing database.
-- Skip if doing a fresh import from schema.sql (column is already there).
--
-- Usage (phpMyAdmin):
--   edutrack_db → Import → choose this file → Go
-- =============================================================================

ALTER TABLE `users`
  ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Set to 1 after bulk-create so user is prompted to change temp password'
  AFTER `is_active`;
