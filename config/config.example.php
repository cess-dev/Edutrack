<?php
/**
 * EduTrack — Central Application Configuration (EXAMPLE)
 *
 * 1. Copy this file to config.php in the same directory
 * 2. Fill in your actual database and security values
 * 3. Never commit config.php to version control
 *
 * All other files in the project read settings from these constants —
 * nothing is hardcoded anywhere else.
 */

// ── Guard: prevent direct browser access ─────────────────────────────────────
if (!defined('EDUTRACK_LOADED')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

// ─────────────────────────────────────────────────────────────────────────────
// DATABASE
// ─────────────────────────────────────────────────────────────────────────────

/** MySQL host — almost always localhost when using XAMPP */
define('DB_HOST', 'localhost');

/** Database name — must match the database you created in phpMyAdmin */
define('DB_NAME', 'edutrack_db');

/** MySQL username — XAMPP default is root */
define('DB_USER', 'root');

/** MySQL password — XAMPP default is blank; change on production */
define('DB_PASS', '');

/** MySQL port — 3306 is the default */
define('DB_PORT', '3306');

/** MySQL character set — always utf8mb4 for full Unicode + emoji support */
define('DB_CHARSET', 'utf8mb4');


// ─────────────────────────────────────────────────────────────────────────────
// APPLICATION
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Base URL of the application — no trailing slash.
 * On XAMPP this is typically http://localhost/edutrack
 * On LAN: use the server's IP, e.g. http://192.168.1.10/edutrack
 */
define('BASE_URL', 'http://localhost/edutrack');

/**
 * Absolute filesystem path to the project root — no trailing slash.
 * __DIR__ resolves to the config/ folder, so we go one level up.
 */
define('ROOT_PATH', dirname(__DIR__));

/** Application name — used in page titles, emails, and PDF headers */
define('APP_NAME', 'EduTrack');

/** School / institution name — appears on reports and parent portal */
define('SCHOOL_NAME', 'Your School Name');

/** Current academic year label — update each year */
define('ACADEMIC_YEAR', '2025/2026');

/** Active semester: 1 or 2 */
define('ACTIVE_SEMESTER', 2);

/**
 * Application environment.
 * 'development' — shows full PHP errors, enables debug logging.
 * 'production'  — suppresses errors to users, logs quietly.
 */
define('APP_ENV', 'development');


// ─────────────────────────────────────────────────────────────────────────────
// SECURITY
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Secret key used to sign QR attendance tokens (HMAC-SHA256).
 * Must be at least 32 random characters.
 * Generate one: php -r "echo bin2hex(random_bytes(32));"
 * NEVER share or commit this value.
 */
define('APP_SECRET', 'GENERATE_A_NEW_VALUE_WITH_PHP_COMMAND_ABOVE');

/**
 * Salt prefix added to all password hashes.
 * Generate: php -r "echo bin2hex(random_bytes(16));"
 * Do not change after users are created — existing passwords will break.
 */
define('PASSWORD_PEPPER', 'GENERATE_A_NEW_VALUE_WITH_PHP_COMMAND_ABOVE');

/** PHP session name — avoids conflicts with other XAMPP projects */
define('SESSION_NAME', 'EDUTRACK_SESSION');

/** Session lifetime in seconds (default: 8 hours) */
define('SESSION_LIFETIME', 28800);

/** CSRF token length in bytes */
define('CSRF_TOKEN_BYTES', 32);


// ─────────────────────────────────────────────────────────────────────────────
// ATTENDANCE
// ─────────────────────────────────────────────────────────────────────────────

/**
 * How long (in minutes) a generated QR code remains valid.
 * Students must scan within this window after the lecturer starts the session.
 */
define('ATTENDANCE_WINDOW_MINUTES', 10);

/**
 * Attendance percentage below which the system triggers a parent alert.
 * E.g. 75 means: alert when student attends fewer than 75% of sessions.
 */
define('ATTENDANCE_ALERT_THRESHOLD', 75);

/**
 * How many hours after a session closes a student can submit a dispute.
 * E.g. 24 = disputes must be raised within 24 hours.
 */
define('DISPUTE_WINDOW_HOURS', 24);

/**
 * How often (in seconds) the lecturer's live attendance feed refreshes via AJAX.
 */
define('LIVE_FEED_INTERVAL_SECONDS', 5);


// ─────────────────────────────────────────────────────────────────────────────
// FILE UPLOADS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Absolute path to the uploads directory.
 * Intentionally outside public/ so files cannot be accessed via browser.
 */
define('UPLOADS_PATH', ROOT_PATH . '/uploads');

/**
 * Absolute path where generated PDF exports are saved before download.
 */
define('EXPORTS_PATH', ROOT_PATH . '/exports');

/** Maximum CSV upload size in bytes (default: 5 MB) */
define('MAX_CSV_SIZE_BYTES', 5 * 1024 * 1024);

/** Allowed MIME types for CSV uploads */
define('ALLOWED_CSV_MIMES', ['text/csv', 'text/plain', 'application/vnd.ms-excel']);


// ─────────────────────────────────────────────────────────────────────────────
// EMAIL / NOTIFICATIONS  (optional — leave SMTP_ENABLED false to skip)
// ─────────────────────────────────────────────────────────────────────────────

/** Set to true to enable email notifications via PHPMailer */
define('SMTP_ENABLED', false);

/** SMTP server host */
define('SMTP_HOST', 'localhost');

/** SMTP port — 25 for local, 587 for TLS relay */
define('SMTP_PORT', 25);

/** SMTP authentication — set true if your relay requires login */
define('SMTP_AUTH', false);

/** SMTP username (if SMTP_AUTH is true) */
define('SMTP_USER', '');

/** SMTP password (if SMTP_AUTH is true) */
define('SMTP_PASS', '');

/** The "From" address on all outgoing emails */
define('SMTP_FROM_EMAIL', 'no-reply@school.local');

/** The "From" display name on all outgoing emails */
define('SMTP_FROM_NAME', APP_NAME);


// ─────────────────────────────────────────────────────────────────────────────
// PAGINATION
// ─────────────────────────────────────────────────────────────────────────────

/** Number of rows per page on tables (attendance logs, student lists, etc.) */
define('ROWS_PER_PAGE', 25);


// ─────────────────────────────────────────────────────────────────────────────
// ERROR HANDLING
// ─────────────────────────────────────────────────────────────────────────────

if (APP_ENV === 'development') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(E_ALL);
    ini_set('log_errors', 1);
    ini_set('error_log', ROOT_PATH . '/logs/php_errors.log');
}


// ─────────────────────────────────────────────────────────────────────────────
// TIMEZONE
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Set to your local timezone.
 * Full list: https://www.php.net/manual/en/timezones.php
 * East Africa: Africa/Nairobi
 */
date_default_timezone_set('Africa/Nairobi');
