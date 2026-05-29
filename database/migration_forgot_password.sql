-- ─────────────────────────────────────────────────────────────────────────────
-- EduTrack — Migration: Forgot-Password System
-- Run once on an existing database (safe to re-run — uses IF NOT EXISTS / IF EXISTS).
-- On a fresh install this migration is already included in schema.sql.
-- ─────────────────────────────────────────────────────────────────────────────

-- 1. Add reset-count column to users table (tracks how many email resets used).
--    Ignored if column already exists.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS password_reset_count
        TINYINT UNSIGNED NOT NULL DEFAULT 0
        AFTER must_change_password;

-- 2. Token table — stores short-lived tokens sent via email.
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id          INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED        NOT NULL,
    token_hash  VARCHAR(255)        NOT NULL COMMENT 'SHA-256 hash of the plain token',
    expires_at  DATETIME            NOT NULL,
    used_at     DATETIME            DEFAULT NULL,
    created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_token_hash (token_hash),
    KEY         idx_user_id  (user_id),
    CONSTRAINT  fk_prt_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Admin-queue table — used when email reset is unavailable or limit reached.
CREATE TABLE IF NOT EXISTS password_reset_requests (
    id           INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED        NOT NULL,
    status       ENUM('pending','approved','rejected')
                                     NOT NULL DEFAULT 'pending',
    resolved_by  INT UNSIGNED        DEFAULT NULL,
    resolved_at  DATETIME            DEFAULT NULL,
    created_at   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY          idx_prr_user   (user_id),
    KEY          idx_prr_status (status),
    CONSTRAINT   fk_prr_user
        FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT   fk_prr_resolver
        FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
