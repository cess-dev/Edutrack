-- ─────────────────────────────────────────────────────────────────────────────
-- EduTrack — Migration: In-App OTP Fallback
-- Run once. Safe to re-run (IF NOT EXISTS / IF EXISTS guards).
-- ─────────────────────────────────────────────────────────────────────────────
--
-- Adds two columns to `users` so the app can store each user's current
-- login OTP directly in the database.  This lets the admin view and relay
-- a code to the user when the email (SMTP) delivery fails.
--
-- Security note: OTPs are stored in plaintext intentionally — they are
-- 6-digit codes that expire in 10 minutes and are single-use.  The
-- exposure window is tiny and the benefit (graceful SMTP fallback) outweighs
-- the risk for a locally-hosted school system.
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS login_otp
        VARCHAR(6) DEFAULT NULL
        COMMENT 'Current login OTP (plaintext, short-lived). NULL when not active.'
        AFTER password_reset_count,

    ADD COLUMN IF NOT EXISTS login_otp_expires
        DATETIME DEFAULT NULL
        COMMENT 'When the active login_otp expires (UTC).'
        AFTER login_otp;
