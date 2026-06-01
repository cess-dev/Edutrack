# EduTrack — Technical Documentation

> Complete reference for developers. Covers every file, class, method, database table, API endpoint, and client-side behaviour in the system.

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Directory Structure](#2-directory-structure)
3. [Configuration & Environment](#3-configuration--environment)
4. [Database Schema](#4-database-schema)
5. [Backend — Middleware](#5-backend--middleware)
6. [Backend — Models](#6-backend--models)
7. [Backend — Helpers](#7-backend--helpers)
8. [Backend — Services](#8-backend--services)
9. [API Reference](#9-api-reference)
10. [Frontend Portals](#10-frontend-portals)
11. [JavaScript Layer](#11-javascript-layer)
12. [Routing & Server](#12-routing--server)
13. [Security Implementation](#13-security-implementation)
14. [Email System](#14-email-system)
15. [File Uploads & PDF Exports](#15-file-uploads--pdf-exports)

---

## 1. Architecture Overview

EduTrack is a **PHP 8.2 monolith** using a front-controller pattern with no external PHP framework. All HTTP requests hit `index.php`, which resolves a clean URL to a PHP view file. API calls are separate PHP scripts under `api/` returning JSON.

```
Browser request
    ↓
Apache / PHP built-in server
    ↓
.htaccess mod_rewrite  →  index.php (router)
    ↓
public/{role}/{page}.php  (HTML views)
           OR
api/{group}/{action}.php  (JSON API)
    ↓
backend/middleware/auth.php   (session + CSRF + guards)
    ↓
backend/models/*.php          (data access)
    ↓
config/database.php           (PDO singleton)
    ↓
MySQL (edutrack_db)
```

**Key design decisions:**
- No ORM — raw parameterised PDO queries in all models.
- No template engine — raw PHP in all view files.
- All state is server-side session (`$_SESSION`). No JWT.
- CSRF tokens are session-bound and rotated on every POST.
- Passwords: `bcrypt cost=12` with server-side pepper.
- QR tokens: HMAC-SHA256 signed, time-limited, single-use per session.

---

## 2. Directory Structure

```
edutrack/
├── config/
│   ├── config.php               Central constants — reads from .env
│   └── database.php             DB singleton class (PDO)
│
├── database/
│   ├── schema.sql               Full database schema + views
│   ├── migration_course_enrollments.sql
│   ├── migration_must_change_password.sql
│   ├── migration_forgot_password.sql
│   └── migration_otp_column.sql
│
├── backend/
│   ├── middleware/
│   │   └── auth.php             Auth class (session, guards, CSRF, audit)
│   ├── models/
│   │   ├── UserModel.php        All user/account operations
│   │   ├── AttendanceModel.php  Attendance + disputes
│   │   └── MarksModel.php       Assessments + marks + grades
│   ├── helpers/
│   │   ├── QRHelper.php         QR token generation + session management
│   │   └── PDFHelper.php        PDF report generation (mPDF)
│   └── services/
│       └── EmailService.php     PHPMailer SMTP wrapper
│
├── api/
│   ├── auth/
│   │   ├── login.php            Two-step login (credentials + OTP)
│   │   ├── verify_otp.php       OTP verification
│   │   ├── logout.php           Session destroy
│   │   ├── forgot_password.php  Password reset request
│   │   ├── reset_password.php   Token-based password reset
│   │   └── session_check.php    Keep-alive + CSRF refresh
│   ├── attendance/
│   │   ├── scan.php             QR scan submission + geofence check
│   │   ├── session_create.php   Lecturer starts QR session
│   │   ├── session_close.php    Close session + auto-absent
│   │   ├── manual_mark.php      Override attendance manually
│   │   ├── live.php             Live scan feed (AJAX poll)
│   │   ├── register.php         Full class register for a session
│   │   ├── history.php          Student attendance history (paginated)
│   │   ├── dispute_submit.php   Student raises dispute
│   │   └── dispute_review.php   Lecturer approves/rejects dispute
│   ├── marks/
│   │   ├── assessment_create.php  Create CAT/exam/assignment
│   │   ├── publish.php            Toggle visibility to students
│   │   ├── upload.php             Bulk marks via CSV
│   │   └── view.php               Student/parent marks view
│   ├── reports/
│   │   ├── transcript.php         Student transcript PDF
│   │   ├── class_report.php       Session attendance register PDF
│   │   └── marks_sheet.php        Unit marks sheet PDF
│   ├── profile/
│   │   ├── change_password.php    Change own password
│   │   └── update_contact.php     Update email/phone
│   └── admin/
│       ├── users_create.php
│       ├── users_bulk_students.php
│       ├── users_bulk_lecturers.php
│       ├── users_bulk_parents.php
│       ├── users_update.php
│       ├── users_toggle_active.php
│       ├── users_reset_password.php
│       ├── students_search.php
│       ├── suggest_reg.php
│       ├── course_create.php / course_update.php
│       ├── unit_create.php / unit_update.php
│       ├── enrollment_add.php / enrollment_remove.php / enrollment_bulk.php
│       ├── link_parent.php / parent_link_bulk.php
│       ├── settings_update.php
│       ├── password_reset_approve.php
│       └── close_all_sessions.php
│
├── public/
│   ├── assets/
│   │   ├── js/
│   │   │   ├── ajax.js          Fetch wrapper, CSRF, session keep-alive
│   │   │   └── qr-scanner.js    Camera + jsQR + location + submission
│   │   ├── css/
│   │   │   ├── base.css
│   │   │   ├── login.css
│   │   │   ├── student.css
│   │   │   ├── lecturer.css
│   │   │   ├── parent.css
│   │   │   └── admin.css
│   │   └── vendor/
│   │       └── jsQR.min.js      QR code decoding library
│   ├── auth/
│   │   ├── forgot_password.php  Password reset request form
│   │   └── reset_password.php   Token-based reset form
│   ├── student/                 Student portal pages
│   ├── lecturer/                Lecturer portal pages
│   ├── parent/                  Parent portal pages
│   ├── admin/                   Admin portal pages
│   ├── errors/                  403, 404, 500 error pages
│   └── partials/                Shared sidebar HTML components
│       ├── sidebar_student.php
│       ├── sidebar_lecturer.php
│       ├── sidebar_parent.php
│       └── sidebar_admin.php
│
├── index.php                    Front controller + URL router
├── serve.php                    PHP built-in server router
├── .htaccess                    Apache mod_rewrite rules
├── .env                         Environment variables (Git-ignored)
├── composer.json                Dependencies: mpdf, phpmailer, phpunit
├── vendor/                      Composer packages
├── logs/                        PHP error log (production)
├── uploads/                     User-uploaded files (outside web root)
└── exports/                     Generated PDFs before download
```

---

## 3. Configuration & Environment

### `.env` File

The `.env` file at project root is parsed by `config/config.php` using a built-in loader (no third-party dotenv library). Values are written to `$_ENV` and `putenv()`. Existing environment variables are never overwritten.

**All supported keys:**

```ini
# Database
DB_HOST=localhost
DB_PORT=3306
DB_NAME=edutrack_db
DB_USER=root
DB_PASS=
DB_CHARSET=utf8mb4

# Application
APP_NAME=EduTrack
BASE_URL=http://localhost:8080
APP_ENV=development          # development | production
SCHOOL_NAME=Your School Name
ACADEMIC_YEAR=2025/2026
ACTIVE_SEMESTER=2

# Security
APP_SECRET=<64-char hex>     # HMAC key for QR tokens
PASSWORD_PEPPER=<32-char hex> # Prepended to passwords before bcrypt
SESSION_NAME=EDUTRACK_SESSION
SESSION_LIFETIME=28800        # seconds (8 hours)
CSRF_TOKEN_BYTES=32

# Attendance
ATTENDANCE_WINDOW_MINUTES=10
ATTENDANCE_ALERT_THRESHOLD=75
DISPUTE_WINDOW_HOURS=24
LIVE_FEED_INTERVAL_SECONDS=5

# File uploads
MAX_CSV_SIZE_BYTES=5242880
ROWS_PER_PAGE=25

# SMTP
SMTP_ENABLED=true
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_AUTH=true
SMTP_USER=Edutrack76@gmail.com
SMTP_PASS=<gmail-app-password>
SMTP_FROM_EMAIL=Edutrack76@gmail.com
SMTP_FROM_NAME=EduTrack

# Timezone
TIMEZONE=Africa/Nairobi

# Geofencing
GEOFENCE_ENABLED=false
SCHOOL_LAT=-1.286389
SCHOOL_LNG=36.817223
SCHOOL_RADIUS_METERS=200
```

### `config/config.php` — Constants

Every `.env` value maps to a PHP constant. Key constants:

| Constant | Type | Source | Purpose |
|---|---|---|---|
| `BASE_URL` | string | `.env` | Absolute URL prefix for all links |
| `ROOT_PATH` | string | computed | Absolute filesystem path of project root |
| `APP_SECRET` | string | `.env` | HMAC-SHA256 key for QR token signing |
| `PASSWORD_PEPPER` | string | `.env` | Concatenated before bcrypt |
| `SESSION_LIFETIME` | int | `.env` | PHP session `gc_maxlifetime` |
| `CSRF_TOKEN_BYTES` | int | `.env` | Random bytes for CSRF token |
| `ATTENDANCE_WINDOW_MINUTES` | int | `.env` | QR code validity after session start |
| `ATTENDANCE_ALERT_THRESHOLD` | int | `.env` | Attendance % below which alerts fire |
| `DISPUTE_WINDOW_HOURS` | int | `.env` | Window after session close to submit dispute |
| `LIVE_FEED_INTERVAL_SECONDS` | int | `.env` | Poll interval for live attendance |
| `GEOFENCE_ENABLED` | bool | `.env` | Enable server-side GPS validation on scans |
| `SCHOOL_LAT` | float | `.env` | School GPS latitude |
| `SCHOOL_LNG` | float | `.env` | School GPS longitude |
| `SCHOOL_RADIUS_METERS` | int | `.env` | Geofence radius |
| `SMTP_ENABLED` | bool | `.env` | Master email switch |
| `PASSWORD_RESET_EMAIL_LIMIT` | int | hardcoded | Max email resets before admin queue |
| `PASSWORD_RESET_TOKEN_HOURS` | int | hardcoded | Reset link validity |
| `ROWS_PER_PAGE` | int | `.env` | Default pagination size |
| `UPLOADS_PATH` | string | computed | `ROOT_PATH/uploads` |
| `EXPORTS_PATH` | string | computed | `ROOT_PATH/exports` |

Also sets PHP ini values:
```php
date_default_timezone_set($_ENV['TIMEZONE'] ?? 'UTC');
ini_set('session.use_only_cookies', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_trans_sid', 0);
ini_set('session.cache_limiter', 'nocache');
```

### `config/database.php` — DB Singleton

```php
class DB {
    private static ?PDO $instance = null;

    // Returns shared PDO connection
    public static function connect(): PDO

    // SELECT returning one row as array, or null
    public static function row(string $sql, array $params = []): ?array

    // SELECT returning array of rows
    public static function rows(string $sql, array $params = []): array

    // INSERT — returns last insert ID
    public static function insert(string $sql, array $params = []): int

    // UPDATE/DELETE — returns affected row count
    public static function execute(string $sql, array $params = []): int

    // Run raw query string (used for migrations/schema)
    public static function query(string $sql, array $params = []): PDOStatement
}
```

PDO options set:
- `ATTR_ERRMODE` → `ERRMODE_EXCEPTION`
- `ATTR_DEFAULT_FETCH_MODE` → `FETCH_ASSOC`
- `ATTR_EMULATE_PREPARES` → `false`
- Charset: `utf8mb4`

---

## 4. Database Schema

### Table: `users`

```sql
id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
reg_number           VARCHAR(30) UNIQUE NOT NULL      -- STU2025001, LEC001, PAR001, ADMIN001
full_name            VARCHAR(120) NOT NULL
email                VARCHAR(120)                     -- optional; UNIQUE if set
phone                VARCHAR(20)
password_hash        VARCHAR(255) NOT NULL             -- bcrypt + pepper
role                 ENUM('admin','lecturer','student','parent') NOT NULL
is_active            TINYINT(1) DEFAULT 1
must_change_password TINYINT(1) DEFAULT 0             -- set for bulk-created accounts
password_reset_count TINYINT UNSIGNED DEFAULT 0       -- tracks email resets used
login_otp            VARCHAR(6)                       -- active login OTP (plain, short-lived)
login_otp_expires    DATETIME                         -- when login_otp expires
last_login           DATETIME
created_by           INT UNSIGNED → users.id (SET NULL)
created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP
updated_at           TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

**Indexes:** `uq_reg_number`, `uq_email` (partial — only when email not null), `idx_role`, `idx_is_active`

---

### Table: `courses`

```sql
id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
code             VARCHAR(20) UNIQUE NOT NULL       -- BCS, BCOM, BED
name             VARCHAR(150) NOT NULL
department       VARCHAR(100)
duration_years   TINYINT DEFAULT 4
is_active        TINYINT(1) DEFAULT 1
created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

---

### Table: `units`

```sql
id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
course_id      INT UNSIGNED NOT NULL → courses.id (CASCADE)
lecturer_id    INT UNSIGNED → users.id (SET NULL)
code           VARCHAR(20) UNIQUE NOT NULL       -- BCS101, BCOM201
name           VARCHAR(150) NOT NULL
semester       TINYINT NOT NULL DEFAULT 1        -- 1 or 2
year_of_study  TINYINT NOT NULL DEFAULT 1        -- 1–6
credit_hours   TINYINT DEFAULT 3
is_active      TINYINT(1) DEFAULT 1
created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

---

### Table: `student_course_enrollments`

Master enrollment table. Every time a student is enrolled in a course for an academic period, one row is created here. Unit-level rows in `enrollments` are derived from this.

```sql
id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
student_id     INT UNSIGNED NOT NULL → users.id (CASCADE)
course_id      INT UNSIGNED NOT NULL → courses.id (CASCADE)
year_of_study  TINYINT NOT NULL
academic_year  VARCHAR(12) NOT NULL                 -- e.g. 2025/2026
semester       TINYINT DEFAULT 1
source         ENUM('manual','csv') DEFAULT 'manual'
enrolled_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
enrolled_by    INT UNSIGNED → users.id (SET NULL)

UNIQUE (student_id, course_id, academic_year, semester)
```

When a new unit is added to a course via `api/admin/unit_create.php`, the system automatically inserts matching rows into `enrollments` for all students enrolled in that course/year/semester.

---

### Table: `enrollments`

Student-to-unit registrations. Derived from `student_course_enrollments`.

```sql
id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
student_id     INT UNSIGNED NOT NULL → users.id (CASCADE)
unit_id        INT UNSIGNED NOT NULL → units.id (CASCADE)
academic_year  VARCHAR(12) NOT NULL
semester       TINYINT DEFAULT 1
enrolled_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP

UNIQUE (student_id, unit_id, academic_year, semester)
```

---

### Table: `parent_student_links`

Many-to-many between parents and students.

```sql
parent_id      INT UNSIGNED NOT NULL → users.id (CASCADE)
student_id     INT UNSIGNED NOT NULL → users.id (CASCADE)
relationship   VARCHAR(50) DEFAULT 'Parent'    -- Parent, Mother, Father, Guardian, Sibling
linked_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP

PRIMARY KEY (parent_id, student_id)
KEY idx_psl_student (student_id)
```

---

### Table: `attendance_sessions`

One row per QR session started by a lecturer.

```sql
id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
unit_id        INT UNSIGNED NOT NULL → units.id (RESTRICT)
lecturer_id    INT UNSIGNED NOT NULL → users.id (RESTRICT)
academic_year  VARCHAR(12) NOT NULL
semester       TINYINT DEFAULT 1
session_token  VARCHAR(64) UNIQUE NOT NULL      -- 32 random bytes as hex
token_hmac     VARCHAR(128) NOT NULL            -- HMAC-SHA256(token|unit_id|expires_at, APP_SECRET)
started_at     DATETIME DEFAULT CURRENT_TIMESTAMP
expires_at     DATETIME NOT NULL                -- started_at + ATTENDANCE_WINDOW_MINUTES
closed_at      DATETIME                         -- set when closed manually
is_active      TINYINT(1) DEFAULT 1
note           VARCHAR(255)
```

---

### Table: `attendance_logs`

One row per student per session.

```sql
id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
session_id     INT UNSIGNED NOT NULL → attendance_sessions.id (CASCADE)
student_id     INT UNSIGNED NOT NULL → users.id (CASCADE)
status         ENUM('present','absent','excused') DEFAULT 'absent'
method         ENUM('qr_scan','manual','auto_absent')
scanned_at     DATETIME                         -- null for absent rows
marked_by      INT UNSIGNED → users.id (SET NULL)  -- lecturer if method=manual
created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP

UNIQUE (session_id, student_id)
```

Rows are inserted on:
1. **QR scan** → status=`present`, method=`qr_scan`
2. **Manual override** → status=whatever, method=`manual`
3. **Session close** → bulk-insert `absent`/`auto_absent` for every student who didn't scan

---

### Table: `assessments`

```sql
id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
unit_id          INT UNSIGNED NOT NULL → units.id (CASCADE)
name             VARCHAR(100) NOT NULL          -- CAT 1, Final Exam, Practical 1
type             ENUM('cat','assignment','practical','project','final_exam') DEFAULT 'cat'
max_score        DECIMAL(6,2) DEFAULT 100.00
weight_percent   DECIMAL(5,2) DEFAULT 0.00      -- contribution to final grade
assessment_date  DATE
is_published     TINYINT(1) DEFAULT 0           -- 1 = visible to students
created_by       INT UNSIGNED → users.id (SET NULL)
created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

PHP enforces that total weights per unit do not exceed 100%.

---

### Table: `marks`

```sql
id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
student_id       INT UNSIGNED NOT NULL → users.id (CASCADE)
assessment_id    INT UNSIGNED NOT NULL → assessments.id (CASCADE)
score            DECIMAL(6,2) NOT NULL
uploaded_by      INT UNSIGNED NOT NULL → users.id (RESTRICT)
uploaded_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
updated_at       TIMESTAMP ON UPDATE CURRENT_TIMESTAMP

UNIQUE (student_id, assessment_id)
```

---

### Table: `disputes`

```sql
id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
student_id       INT UNSIGNED NOT NULL → users.id (CASCADE)
session_id       INT UNSIGNED NOT NULL → attendance_sessions.id (CASCADE)
reason           TEXT NOT NULL
status           ENUM('pending','approved','rejected') DEFAULT 'pending'
reviewer_id      INT UNSIGNED → users.id (SET NULL)
reviewer_note    TEXT
reviewed_at      DATETIME
created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP

UNIQUE (student_id, session_id)
```

---

### Table: `system_settings`

Key-value store for admin-editable runtime configuration.

```sql
setting_key    VARCHAR(80) PRIMARY KEY
setting_value  TEXT
description    VARCHAR(255)
updated_by     INT UNSIGNED → users.id (SET NULL)
updated_at     TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

Default keys: `school_name`, `academic_year`, `active_semester`, `attendance_threshold`, `dispute_window_hours`, `attendance_window_minutes`.

---

### Table: `audit_logs`

Immutable append-only audit trail. Never updated or deleted.

```sql
id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
user_id        INT UNSIGNED → users.id (SET NULL)
action         VARCHAR(80) NOT NULL
target_type    VARCHAR(50)                      -- table name
target_id      INT UNSIGNED                     -- record ID
detail         JSON                             -- extra context
ip_address     VARCHAR(45)
created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

---

### Table: `password_reset_tokens`

Short-lived tokens for email-based password reset.

```sql
id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
user_id        INT UNSIGNED NOT NULL → users.id (CASCADE)
token_hash     VARCHAR(255) NOT NULL             -- SHA-256 of raw token
expires_at     DATETIME NOT NULL
used_at        DATETIME                          -- set when token is consumed
created_at     DATETIME DEFAULT CURRENT_TIMESTAMP

UNIQUE KEY uq_token_hash (token_hash)
```

---

### Table: `password_reset_requests`

Admin approval queue for users who cannot receive reset emails.

```sql
id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
user_id        INT UNSIGNED NOT NULL → users.id (CASCADE)
status         ENUM('pending','approved','rejected') DEFAULT 'pending'
resolved_by    INT UNSIGNED → users.id (SET NULL)
resolved_at    DATETIME
created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
```

---

### Views

**`vw_attendance_summary`**
```sql
SELECT student_id, unit_id, academic_year, semester,
       student_name, unit_code, unit_name,
       total_sessions,
       SUM(status='present')                                AS attended,
       SUM(status='absent' OR status='auto_absent')         AS absent,
       SUM(status='excused')                                AS excused,
       ROUND(SUM(status='present') / total_sessions * 100, 1) AS attendance_percent
FROM attendance_logs
JOIN attendance_sessions ...
JOIN units ...
JOIN users ...
GROUP BY student_id, unit_id, academic_year, semester
```

**`vw_unit_grades`**
```sql
SELECT student_id, unit_id, student_name, unit_code, unit_name,
       SUM(m.score / a.max_score * a.weight_percent) AS weighted_total,
       COUNT(m.id)    AS assessments_submitted,
       COUNT(a.id)    AS assessments_total
FROM marks m
JOIN assessments a ...
JOIN units u ...
JOIN users s ...
WHERE a.is_published = 1
GROUP BY student_id, unit_id
```

---

## 5. Backend — Middleware

### `backend/middleware/auth.php` — `Auth` class

All methods are `static`. Included at the top of every page and API file.

#### Session Bootstrap

```php
Auth::startSession(): void
```
- Sets `session_name(SESSION_NAME)` and configures cookie parameters.
- Calls `session_start()`.
- Sends all security headers (CSP, HSTS, X-Frame-Options, Permissions-Policy, etc.).
- Regenerates session ID every 30 minutes to prevent fixation.

**Security headers sent on every request:**
```
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
Referrer-Policy: strict-origin-when-cross-origin
X-XSS-Protection: 1; mode=block
Permissions-Policy: camera=(self), microphone=(), geolocation=(self), fullscreen=(self)
Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval';
                         style-src 'self' 'unsafe-inline'; img-src 'self' data:;
                         font-src 'self'; connect-src 'self'; object-src 'none';
                         frame-ancestors 'self'; base-uri 'self'; form-action 'self';
Strict-Transport-Security: max-age=31536000; includeSubDomains; preload  (HTTPS only)
```

#### Guard Functions

```php
Auth::requireLogin(bool $isApi = false): void
```
- Redirects to login selector (HTML) or returns HTTP 401 JSON (API) if not logged in.
- Updates `last_login` once per session (SESSION flag `_login_recorded`).

```php
Auth::requireRole(string $role, bool $isApi = false): void
```
- Calls `requireLogin()` first.
- If role doesn't match: HTML → redirect `/error/403`, API → HTTP 403 JSON.

```php
Auth::requireAnyRole(array $roles, bool $isApi = false): void
```
- Same as `requireRole` but accepts a list.

```php
Auth::redirectIfLoggedIn(): void
```
- Used on login pages — if already logged in, redirect to role dashboard.

#### Authentication

```php
Auth::attempt(string $identifier, string $plainPassword): bool
```
- Accepts reg_number **or** email (auto-detected via `filter_var`).
- Always runs `password_verify` (timing-safe, even for missing users — uses a dummy hash).
- On success: regenerates session ID, writes to `$_SESSION`, logs `user_login` audit event.
- Session keys set: `user_id`, `reg_number`, `full_name`, `email`, `role`, `must_change_password`, `_created`.

```php
Auth::loginAsUser(array $user): void
```
- Completes login without re-running credential check (used after OTP verification).
- Regenerates session ID, writes the same session keys as `attempt()`.

```php
Auth::logout(): void
```
- Logs `user_logout` audit event, clears `$_SESSION`, destroys cookie, destroys session, redirects to role login page.

#### Session Accessors

| Method | Return | Description |
|---|---|---|
| `Auth::isLoggedIn()` | bool | `user_id` and `role` exist in session |
| `Auth::id()` | ?int | Current user's database ID |
| `Auth::role()` | ?string | Current user's role |
| `Auth::name()` | ?string | Current user's `full_name` |
| `Auth::user()` | array | All session user data |
| `Auth::mustChangePassword()` | bool | Flag for bulk-created accounts |
| `Auth::clearMustChangePassword()` | void | Clear flag after password change |

#### CSRF Protection

```php
Auth::csrfToken(): string
```
- Returns existing token from session, or generates a new 32-byte random hex token.
- Called on every page that renders a form — output is placed in `<meta name="csrf-token">`.

```php
Auth::verifyCsrf(bool $isApi = false): void
```
- Checks token in this order: `$_POST['csrf_token']` → `X-CSRF-Token` header → JSON body field.
- Verified via `hash_equals()` (timing-safe).
- On success: rotates token, sends new token in `X-New-CSRF-Token` response header.
- On failure: HTTP 419 JSON (API) or exits (HTML).

#### Audit Logging

```php
Auth::audit(
    string $action,
    string $targetType = '',
    ?int   $targetId   = null,
    array  $detail     = []
): void
```
- Inserts one row into `audit_logs`.
- Never throws — silently fails if DB is unavailable.
- `ip_address` is taken from `$_SERVER['REMOTE_ADDR']`.

**Common action strings used across the codebase:**
`user_login`, `user_logout`, `user_created`, `bulk_create_students`, `bulk_create_lecturers`, `bulk_create_parents`, `password_changed`, `password_reset`, `contact_updated`, `session_created`, `session_closed`, `attendance_scanned`, `manual_mark`, `dispute_submitted`, `dispute_reviewed`, `assessment_created`, `assessment_published`, `marks_uploaded`, `password_reset_approve`, `password_reset_reject`, `all_sessions_closed`

---

## 6. Backend — Models

### `UserModel.php`

#### Account Creation

```php
UserModel::create(array $data): array
// Returns: { success: bool, id: ?int, message: string }
// $data keys: reg_number, full_name, email?, phone?, password, role, created_by?, must_change_password?
// Validates: reg_number unique, email unique, password strength, role in allowed list
// Hashes password: password_hash($password . PASSWORD_PEPPER, PASSWORD_BCRYPT, ['cost' => 12])
```

```php
UserModel::bulkCreateStudents(array $rows, int $createdBy, string $defaultPass = 'Student@1'): array
// Returns: { created, skipped, errors[], accounts[] }
// CSV columns: reg_number (required), full_name, email?, phone?
// Sets must_change_password=1 on all new accounts
```

```php
UserModel::bulkCreateLecturers(array $rows, int $createdBy, string $defaultPass = 'Lecturer@1'): array
// Returns: { created, skipped, errors[], accounts[] }
// Auto-generates reg_number: LEC001, LEC002...
// Sets must_change_password=1
```

```php
UserModel::bulkCreateParents(array $rows, int $createdBy, string $defaultPass = 'Parent@1'): array
// Returns: { created, linked, skipped, errors[], accounts[] }
// CSV columns: full_name, email?, phone?, student_reg_number?, relationship?
// De-duplicates: same parent on multiple rows (two children) = one account + two links
// Sets must_change_password=1
```

#### Profile Reads

```php
UserModel::findById(int $id): ?array
UserModel::findByRegNumber(string $regNumber): ?array
UserModel::listUsers(string $role = 'all', string $search = '', int $page = 1, int $perPage = ROWS_PER_PAGE): array
// Returns: { rows[], total, pages, page }
UserModel::getLecturerOptions(): array          // all active lecturers
UserModel::getStudentOptions(string $search = ''): array
UserModel::getLinkedStudents(int $parentId): array  // students linked to a parent
```

#### Profile Updates

```php
UserModel::updateContact(int $userId, string $email, string $phone): array
// Validates email uniqueness (excluding own record)

UserModel::changePassword(int $userId, string $currentPassword, string $newPassword): array
// Verifies current password, validates strength, prevents re-use, clears must_change_password

UserModel::adminResetPassword(int $targetUserId, string $newPassword, int $adminId): array
// No current-password check; logs audit

UserModel::setActive(int $targetUserId, bool $active, int $adminId): array
// Admin cannot deactivate own account
```

#### Password Strength Validation

```php
UserModel::validatePasswordStrength(string $password): array
// Returns: { valid: bool, message: string }
// Rules: min 8 chars, at least 1 uppercase, 1 lowercase, 1 digit, 1 special char
```

#### Registration Number Generation

```php
UserModel::generateRegNumber(string $role): string
// Queries MAX existing reg_number with role prefix, increments
// Prefixes: student→STU, lecturer→LEC, parent→PAR, admin→ADMIN
```

#### Parent–Student Linking

```php
UserModel::linkParentToStudent(int $parentId, int $studentId, string $relationship = 'Parent'): array
// INSERT IGNORE into parent_student_links
UserModel::unlinkParentFromStudent(int $parentId, int $studentId): array
```

#### Course Enrollment

```php
UserModel::enrollStudentInCourse(
    int $studentId, int $courseId, int $yearOfStudy,
    string $academicYear, int $semester, string $source = 'manual', int $enrolledBy = 0
): array
// Creates student_course_enrollments row
// Then calls deriveCourseUnitEnrollments() to create unit-level rows

UserModel::deriveCourseUnitEnrollments(
    int $studentId, int $courseId, int $yearOfStudy, string $academicYear, int $semester
): int
// Inserts enrollment rows for all active units in course matching year/semester
// Returns count of rows inserted

UserModel::unenrollStudentFromCourse(
    int $studentId, int $courseId, string $academicYear, int $semester
): bool
// Removes student_course_enrollments row + all derived unit enrollment rows

UserModel::getStudentCourseEnrollment(int $studentId, string $academicYear, int $semester): ?array
UserModel::getStudentCourseHistory(int $studentId): array
UserModel::getCourseEnrollmentList(string $academicYear, string $semester, string $search = ''): array
```

---

### `AttendanceModel.php`

#### Recording

```php
AttendanceModel::recordScan(int $sessionId, int $studentId): bool
// INSERT IGNORE — returns true if inserted, false if already exists (race condition guard)
// Sets status='present', method='qr_scan', scanned_at=NOW()

AttendanceModel::manualMark(int $sessionId, int $studentId, string $status, int $markedBy): bool
// INSERT ... ON DUPLICATE KEY UPDATE
// status in: 'present', 'absent', 'excused'
```

#### Student History

```php
AttendanceModel::getStudentHistory(int $studentId, int $page = 1, int $perPage = ROWS_PER_PAGE): array
// Returns: { rows[], total, pages, page }
// Includes: status, method, scanned_at, session_started_at, unit_code, unit_name, lecturer_name

AttendanceModel::getStudentSummary(int $studentId, string $academicYear, int $semester): array
// Reads from vw_attendance_summary — one row per enrolled unit
// Returns: attended, absent, excused, total_sessions, attendance_percent per unit
```

#### Lecturer History

```php
AttendanceModel::getLecturerSessions(int $lecturerId, int $page = 1, int $perPage = ROWS_PER_PAGE): array
// Returns: { rows[], total, pages, page }
// Each row: session info + present_count, absent_count, total_logged

AttendanceModel::getSessionRegister(int $sessionId): array
// Full class list: every enrolled student + their status
// LEFT JOIN from enrollments to attendance_logs — missing logs shown as 'absent'

AttendanceModel::getUnitAttendanceSummary(int $unitId, string $academicYear, int $semester): array
// From vw_attendance_summary — all students in unit, ordered by attendance_percent ASC
```

#### Analytics

```php
AttendanceModel::getAtRiskStudents(int $unitId = 0, int $lecturerId = 0, ...): array
// attendance_percent < ATTENDANCE_ALERT_THRESHOLD
// unitId=0 = all units; lecturerId=0 = school-wide

AttendanceModel::getUnitTrend(int $unitId, string $academicYear, int $semester): array
// One row per session: date, present_count, total_logged, percent
// Used by Chart.js line charts
```

#### Disputes

```php
AttendanceModel::submitDispute(int $studentId, int $sessionId, string $reason): array
// Verifies student was marked absent/excused (not present)
// Checks dispute window (session closed_at + DISPUTE_WINDOW_HOURS)
// Prevents duplicate (UNIQUE student_id, session_id)
// Returns: { success, message }

AttendanceModel::getLecturerDisputes(int $lecturerId, string $status = 'pending'): array
AttendanceModel::getAdminDisputes(string $status = 'all'): array
AttendanceModel::reviewDispute(int $disputeId, string $status, string $note, int $reviewerId): bool
// On approve: updates attendance_logs row to status='present', method='manual'
```

---

### `MarksModel.php`

#### Grade Boundaries

| Grade | Range | Points | Remark |
|---|---|---|---|
| A | 70–100% | 4.0 | Distinction |
| B | 60–69% | 3.0 | Credit |
| C | 50–59% | 2.0 | Pass |
| D | 40–49% | 1.0 | Marginal Fail |
| E | 0–39% | 0.0 | Fail |

Grade is assigned only when `weight_earned >= 100%` (all weighted assessments submitted).

#### Assessments

```php
MarksModel::createAssessment(array $data): array
// Validates total weight won't exceed 100% for the unit
// Fields: unit_id, name, type, max_score, weight_percent, assessment_date?, created_by

MarksModel::getUnitAssessments(int $unitId, bool $publishedOnly = false): array
// Returns assessments with marks_uploaded count and class_average

MarksModel::togglePublish(int $assessmentId, int $lecturerId): array
// Verifies lecturer owns the unit; toggles is_published
// Returns: { success, published: bool, message }
```

#### Marks Entry

```php
MarksModel::saveMark(int $studentId, int $assessmentId, float $score, int $uploadedBy): array
// Validates: student enrolled, score in [0, max_score]
// INSERT ... ON DUPLICATE KEY UPDATE

MarksModel::bulkUploadFromCsv(array $file, int $assessmentId, int $uploadedBy): array
// CSV columns: reg_number, score
// All valid rows saved in a transaction
// Returns: { success, saved, skipped, errors[] }
```

#### Grade Views

```php
MarksModel::getStudentMarks(int $studentId, string $academicYear, int $semester): array
// Published assessments only
// Grouped by unit_id
// Computes weighted_total = SUM((score / max_score) * weight_percent)
// Returns grade letter, grade_points, remark per unit

MarksModel::getUnitMarksSheet(int $unitId, string $academicYear, int $semester): array
// Full class grid (all assessments, published + unpublished)
// Returns: { assessments[], students[] with scores[] keyed by assessment_id }

MarksModel::calculateGpa(int $studentId, string $academicYear, int $semester): float
// Sum of grade_points / number of units with assigned grades
```

---

## 7. Backend — Helpers

### `QRHelper.php`

#### Token Structure

The QR code encodes a JSON string:
```json
{
  "v": 1,
  "t": "64-character-hex-token",
  "s": 42,
  "u": 7,
  "e": "2025-06-01 14:30:00"
}
```
`v` = payload version, `t` = session token, `s` = session_id, `u` = unit_id, `e` = expires_at.

This JSON is what jsQR decodes from the camera frame and what is sent to the scan API.

#### Methods

```php
QRHelper::createSession(
    int $unitId, int $lecturerId, string $academicYear,
    int $semester, ?string $note = null
): array
// Closes any lingering open sessions for this unit first
// Generates token: 32 random bytes → 64 hex chars (bin2hex(random_bytes(32)))
// Signs: hash_hmac('sha256', "$token|$unitId|$expiresAt", APP_SECRET)
// Inserts into attendance_sessions
// Returns: { session_id, token, payload (JSON string), expires_at, window_minutes }

QRHelper::validateScan(string $rawQrString, int $studentId): array
// Returns: { valid: bool, session_id, unit_id, error, error_code }
// Checks (in order):
//   1. JSON valid with keys v, t, s, u, e
//   2. Version == PAYLOAD_VERSION (1)
//   3. Session exists in DB
//   4. is_active == 1 (not closed)
//   5. expires_at > NOW() (not expired)
//   6. HMAC valid: hash_equals(stored_hmac, hash_hmac(...))
//   7. Student enrolled in unit
//   8. Student has not already scanned (SELECT count from attendance_logs)
// All HMAC comparisons use hash_equals() for timing safety

QRHelper::closeSession(int $sessionId, int $lecturerId): array
// Verifies lecturer owns session
// Sets is_active=0, closed_at=NOW()
// Finds all enrolled students without an attendance_log row
// Bulk-inserts them as status='absent', method='auto_absent'
// Transaction: begin → update session → bulk insert → commit
// Returns: { closed: bool, absent_marked: int }

QRHelper::getLiveData(int $sessionId): array
// Returns: { session info, scans[], scan_count, total_enrolled, server_time }

QRHelper::closeAllActive(): int
// Emergency: closes all is_active=1 sessions
// Returns count closed
```

---

### `PDFHelper.php`

Uses **mPDF** (composer package `mpdf/mpdf`). All PDFs use A4 format, UTF-8 encoding, and a shared CSS stylesheet.

```php
PDFHelper::attendanceReport(int $sessionId): \Mpdf\Mpdf
// Class register for one session
// Shows each student: name, reg, status, method, scanned_at
// Summary: present count, total, %

PDFHelper::studentTranscript(int $studentId): \Mpdf\Mpdf
// Student's full academic record
// Reads from vw_unit_grades
// Shows: unit code, unit name, weighted total, grade, grade points, remark
// Computes and shows GPA

PDFHelper::unitAttendanceSummary(int $unitId, string $academicYear, int $semester): \Mpdf\Mpdf
// All students in a unit with attendance %
// Students below ATTENDANCE_ALERT_THRESHOLD highlighted in red

PDFHelper::marksSheet(int $unitId, string $academicYear, int $semester): \Mpdf\Mpdf
// Landscape orientation
// Grid: students × assessments
// Shows each score, final weighted total, grade

PDFHelper::save(\Mpdf\Mpdf $mpdf, string $filename): string
// Saves to EXPORTS_PATH, returns absolute path

PDFHelper::download(\Mpdf\Mpdf $mpdf, string $filename): void
// Streams as Content-Disposition: attachment

PDFHelper::inline(\Mpdf\Mpdf $mpdf, string $filename): void
// Streams as Content-Disposition: inline (open in browser PDF viewer)
```

---

## 8. Backend — Services

### `EmailService.php`

Wraps **PHPMailer** (vendor package `phpmailer/phpmailer`). Only sends when `SMTP_ENABLED = true`.

```php
EmailService::isEnabled(): bool
// Returns true only if SMTP_ENABLED constant is true

EmailService::isDeliverableAddress(string $email): bool
// Returns false for domains: .local, .test, .invalid, .example, .localhost, etc.
// Used to skip fake/development email addresses

EmailService::send(string $to, string $subject, string $html, string $text = ''): bool
// Configures PHPMailer with SMTP constants
// Port 465 → PHPMailer::ENCRYPTION_SMTPS
// Port 587 → PHPMailer::ENCRYPTION_STARTTLS
// Plain text auto-generated from HTML via strip_tags() if not provided
// SMTP debug output logged to error_log in development
// Returns false on any failure; logs error

EmailService::sendOtp(string $to, string $name, string $otp): bool
// 6-digit code in branded HTML email
// States: expires in 10 minutes

EmailService::sendPasswordReset(string $to, string $name, string $token, string $role = 'student'): bool
// One-time reset link: {BASE_URL}/auth/reset-password?token=...&role=...
// Link valid for PASSWORD_RESET_TOKEN_HOURS

EmailService::sendPasswordResetApproved(string $to, string $name, string $tempPass): bool
// Notifies user that admin approved their reset request
// Includes temporary password in styled code block
```

---

## 9. API Reference

### Common Patterns

Every API file follows this pattern:
```php
defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
// + model/helper requires as needed

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');
Auth::requireRole('student', true);   // or requireLogin / requireAnyRole
Auth::verifyCsrf(true);               // on all state-changing requests

// Parse JSON or form body
// Validate inputs
// Call model
// Return JSON
```

**Standard HTTP status codes used:**

| Code | Meaning |
|---|---|
| 200 | OK |
| 201 | Created |
| 400 | Bad request / invalid input |
| 401 | Not authenticated (session expired) |
| 403 | Forbidden (wrong role or not enrolled) |
| 404 | Resource not found |
| 405 | Wrong HTTP method |
| 409 | Conflict (duplicate scan, already linked) |
| 410 | Gone (session closed, token expired) |
| 419 | CSRF token invalid |
| 422 | Unprocessable (bad credentials, wrong OTP) |
| 429 | Rate limited |
| 503 | Service unavailable (SMTP failure) |

---

### Auth Endpoints

#### `POST /api/auth/login.php`
**Access:** Public

**Request:**
```json
{ "reg_number": "STU2025001", "password": "Password@1" }
```

**Step 1 response (OTP required — SMTP enabled, deliverable email):**
```json
{
  "success": true,
  "step": "otp",
  "email_hint": "si***@gmail.com",
  "message": "A 6-digit code has been sent to si***@gmail.com. It expires in 10 minutes."
}
```

Session stores: `$_SESSION['otp_pending'] = { user, otp_hash, expires, attempts }`
DB stores: `users.login_otp`, `users.login_otp_expires` (plaintext, 10-min expiry)

**Step 1 response (direct login — no SMTP or no email):**
```json
{
  "success": true,
  "step": "done",
  "user": { "id": 5, "full_name": "Simon Iscariot", "role": "lecturer", "reg_number": "LEC004" },
  "redirect": "https://192.168.150.218/edutrack/lecturer/dashboard"
}
```

**Rate limiting:** 5 failed attempts → 429 lockout for 5 minutes (IP-based, session-stored). Credentials errors return HTTP 422 (not 401) to avoid triggering session-expiry handler.

---

#### `POST /api/auth/verify_otp.php`
**Access:** Public (guarded by session `otp_pending`)

**Request:**
```json
{ "otp": "482917" }
```

**Response:**
```json
{
  "success": true,
  "message": "Login successful.",
  "user": { "id": 5, "full_name": "...", "role": "lecturer", "reg_number": "LEC004" },
  "redirect": "https://.../lecturer/dashboard"
}
```

OTP verification uses `password_verify(submitted_otp, stored_bcrypt_hash)`. Max 5 attempts before OTP is invalidated. On success: `Auth::loginAsUser()` is called, DB column cleared.

**Error codes:** 400 (empty OTP), 410 (expired or too many attempts), 422 (wrong code)

---

#### `POST /api/auth/forgot_password.php`
**Access:** Public

**Request:** `{ "identifier": "STU2025001" }` (reg_number or email)

**Routing logic:**
1. Look up user by reg_number or email
2. User not found → generic success (no method field)
3. SMTP disabled or no email → admin queue
4. `password_reset_count >= PASSWORD_RESET_EMAIL_LIMIT` → admin queue
5. Otherwise → email token flow

**Response:**
```json
{ "success": true, "method": "email"|"admin_queue", "message": "..." }
```

When `method` is absent (user not found), JS shows the same "check inbox" state to avoid leaking account existence.

---

#### `POST /api/auth/reset_password.php`
**Access:** Public (token-gated)

**Request:** `{ "token": "...", "password": "...", "password2": "..." }`

Validates: token exists in DB, not expired, not used. Sets new password, marks token `used_at=NOW()`.

---

#### `GET /api/auth/session_check.php`
**Access:** Any authenticated user

**Response:**
```json
{ "logged_in": true, "user": { ... }, "session_expires_in": 28800, "csrf_token": "..." }
```

Returns HTTP 401 if session expired. Called by `ajax.js` every 5 minutes.

---

### Attendance Endpoints

#### `POST /api/attendance/session_create.php`
**Access:** Lecturer

**Request:** `{ "unit_id": 7, "note": "Week 5 lecture" }`

**Response:**
```json
{
  "success": true,
  "session_id": 42,
  "qr_payload": "{\"v\":1,\"t\":\"abc123...\",\"s\":42,\"u\":7,\"e\":\"2025-06-01 14:30:00\"}",
  "expires_at": "2025-06-01 14:30:00",
  "window_minutes": 10,
  "unit": { "id": 7, "code": "BCS301", "name": "Database Systems" }
}
```

---

#### `POST /api/attendance/scan.php`
**Access:** Student

**Request:**
```json
{
  "qr_data": "{\"v\":1,\"t\":\"abc...\",\"s\":42,\"u\":7,\"e\":\"...\"}",
  "lat": -1.286389,
  "lng": 36.817223
}
```

**Geofence check (if `GEOFENCE_ENABLED`):**
- Uses Haversine formula: `distance = R * 2 * atan2(sqrt(a), sqrt(1-a))`
- If `distance > SCHOOL_RADIUS_METERS` → HTTP 403, `error_code: OUTSIDE_GEOFENCE`
- If no lat/lng provided → HTTP 403, `error_code: LOCATION_REQUIRED`

**Success response:**
```json
{
  "success": true,
  "message": "Attendance recorded. You are marked present.",
  "unit_name": "BCS301 — Database Systems",
  "session_id": 42,
  "scanned_at": "2025-06-01 14:22:17"
}
```

**Error codes:** `INVALID_PAYLOAD`, `VERSION_MISMATCH`, `SESSION_NOT_FOUND`, `SESSION_CLOSED`, `TOKEN_EXPIRED`, `HMAC_INVALID`, `NOT_ENROLLED`, `ALREADY_SCANNED`, `OUTSIDE_GEOFENCE`, `LOCATION_REQUIRED`

---

#### `POST /api/attendance/session_close.php`
**Access:** Lecturer

**Request:** `{ "session_id": 42 }`

**Response:** `{ "success": true, "closed": true, "absent_marked": 12 }`

---

#### `GET /api/attendance/live.php`
**Access:** Lecturer

**Query params:** `session_id=42`

**Response:**
```json
{
  "session": { "id": 42, "started_at": "...", "expires_at": "...", "is_active": 1 },
  "scans": [
    { "student_id": 10, "reg_number": "STU2025001", "full_name": "Jane Doe", "scanned_at": "..." }
  ],
  "scan_count": 15,
  "total_enrolled": 38,
  "server_time": "2025-06-01 14:22:30"
}
```

---

#### `POST /api/attendance/dispute_submit.php`
**Access:** Student

**Request:** `{ "session_id": 42, "reason": "I was present but forgot my phone." }`

Validates: student was marked absent, session is within `DISPUTE_WINDOW_HOURS` of closing.

---

#### `POST /api/attendance/dispute_review.php`
**Access:** Lecturer

**Request:** `{ "dispute_id": 5, "status": "approved", "reviewer_note": "Confirmed present." }`

On `approved`: also updates `attendance_logs` row to `status='present'`, `method='manual'`.

---

### Marks Endpoints

#### `POST /api/marks/assessment_create.php`
**Access:** Lecturer

**Request:**
```json
{
  "unit_id": 7,
  "name": "CAT 1",
  "type": "cat",
  "max_score": 30,
  "weight_percent": 30,
  "assessment_date": "2025-06-10"
}
```

**Response:** `{ "success": true, "id": 15, "message": "Assessment created." }`

---

#### `POST /api/marks/upload.php`
**Access:** Lecturer

**Request:** `multipart/form-data` with `csv_file` (file) and `assessment_id` (int).

**CSV format:**
```
reg_number,score
STU2025001,25.5
STU2025002,28
```

**Response:** `{ "success": true, "saved": 35, "skipped": 2, "errors": [...] }`

---

#### `GET /api/marks/view.php`
**Access:** Student / Parent (for linked students)

**Query params:** `student_id=10&academic_year=2025/2026&semester=2`

**Response:** Array of units, each with `assessments[]`, `weighted_total`, `grade`, `grade_points`, `remark`.

---

### Reports Endpoints

| Endpoint | Method | Access | Output |
|---|---|---|---|
| `/api/reports/transcript.php` | GET | Student, Parent | PDF download |
| `/api/reports/class_report.php` | GET | Lecturer, Admin | PDF download |
| `/api/reports/marks_sheet.php` | GET | Lecturer, Admin | PDF download |

---

### Admin Endpoints (selected)

#### `POST /api/admin/users_bulk_students.php`
CSV columns: `reg_number, full_name, email, phone`
Sets `must_change_password=1`, password=`Student@1`
Returns: `{ created, skipped, errors[], accounts[] }`

#### `POST /api/admin/users_bulk_lecturers.php`
CSV columns: `full_name, email, phone`
Auto-generates `LEC001...`, password=`Lecturer@1`

#### `POST /api/admin/users_bulk_parents.php`
CSV columns: `full_name, email, phone, student_reg_number, relationship`
De-duplicates parents; links to students if reg provided; password=`Parent@1`

#### `POST /api/admin/enrollment_bulk.php`
CSV columns: `reg_number, full_name, course_code, year_of_study, semester`
Calls `UserModel::enrollStudentInCourse()` → creates SCE row + derives unit enrollments

#### `POST /api/admin/password_reset_approve.php`
**Request:** `{ "request_id": 5, "action": "approve"|"reject" }`
On approve: generates readable temp password (`BlueEagle123!` format), sets `must_change_password=1`, emails if SMTP enabled.
**Response:** `{ "success": true, "action": "approved", "temp_pass": "BlueEagle123!", "emailed": true }`

#### `POST /api/admin/close_all_sessions.php`
Emergency endpoint. Closes all `is_active=1` sessions.
**Response:** `{ "closed_count": 3 }`

---

## 10. Frontend Portals

### Common Page Pattern

Every authenticated page:
```php
Auth::startSession();
Auth::requireRole('student'); // or requireLogin / requireAnyRole
$user      = Auth::user();
$csrfToken = Auth::csrfToken();
// ... DB queries for page data ...
?>
<!DOCTYPE html>
<html data-base-url="<?= BASE_URL ?>">
<head>
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
  ...
</head>
<body>
<?php include 'partials/sidebar_{role}.php'; ?>
<!-- Page content -->
<script src="ajax.js"></script>
<script>
const BASE_URL = <?= json_encode(BASE_URL) ?>;
// Page-specific JS
</script>
```

---

### Student Portal (`public/student/`)

| Page | URL Route | What it does |
|---|---|---|
| `login.php` | `/student/login` | Two-step login form (credentials → OTP → redirect) |
| `dashboard.php` | `/student/dashboard` | Attendance summary per unit + recent marks + at-risk warnings |
| `scan.php` | `/student/scan` | QR scanner interface — camera + jsQR + geofence |
| `attendance.php` | `/student/attendance` | Attendance detail per unit (paginated table) |
| `marks.php` | `/student/marks` | Published marks grouped by unit with weighted totals and grades |
| `disputes.php` | `/student/disputes` | Submit disputes + view dispute history and status |
| `transcript.php` | `/student/transcript` | Full GPA + grade table + PDF download link |
| `history.php` | `/student/history` | Full attendance log across all sessions (paginated) |
| `profile.php` | `/student/profile` | Change password + update contact details |

---

### Lecturer Portal (`public/lecturer/`)

| Page | URL Route | What it does |
|---|---|---|
| `login.php` | `/lecturer/login` | Two-step login |
| `dashboard.php` | `/lecturer/dashboard` | Assigned units + session count + at-risk students |
| `session_start.php` | `/lecturer/session/start` | Start QR session — renders QR code with countdown |
| `session_live.php` | `/lecturer/session/live` | Live scan feed, polls every 5 sec |
| `sessions.php` | `/lecturer/sessions` | Session history + per-session register + PDF download |
| `marks.php` | `/lecturer/marks` | Create assessments + single-entry + CSV upload |
| `marksheet.php` | `/lecturer/marksheet` | Class marks grid + PDF download |
| `disputes.php` | `/lecturer/disputes` | Review pending disputes for own sessions |
| `analytics.php` | `/lecturer/analytics` | Chart.js attendance trend + at-risk table |
| `profile.php` | `/lecturer/profile` | Change password + update contact |

---

### Parent Portal (`public/parent/`)

| Page | URL Route | What it does |
|---|---|---|
| `login.php` | `/parent/login` | Two-step login |
| `dashboard.php` | `/parent/dashboard` | All linked children's summary cards |
| `attendance.php` | `/parent/attendance?student_id=X` | Child attendance per unit |
| `marks.php` | `/parent/marks?student_id=X` | Child published marks |
| `transcript.php` | `/parent/transcript?student_id=X` | Child transcript + PDF |
| `history.php` | `/parent/history?student_id=X` | Child full attendance log |
| `profile.php` | `/parent/profile` | Change password + contact |

---

### Admin Portal (`public/admin/`)

| Page | URL Route | What it does |
|---|---|---|
| `login.php` | `/admin/login` | Two-step login + "Admin access only" warning banner |
| `dashboard.php` | `/admin/dashboard` | User counts + recent audit events + at-risk students |
| `users.php` | `/admin/users` | User list + create/edit/activate/deactivate + bulk import + password reset requests panel |
| `courses.php` | `/admin/courses` | Create/edit courses and their units |
| `enrollments.php` | `/admin/enrollments` | Enroll students in courses (single or CSV bulk) |
| `attendance.php` | `/admin/attendance` | School-wide attendance analytics + at-risk |
| `disputes.php` | `/admin/disputes` | All disputes with filter and review actions |
| `reports.php` | `/admin/reports` | Generate any PDF report |
| `audit.php` | `/admin/audit` | Immutable audit log with filter |
| `settings.php` | `/admin/settings` | Edit system_settings table values |
| `profile.php` | `/admin/profile` | Change password + contact |

---

### Sidebar Partials (`public/partials/`)

Each role has its own sidebar partial. All sidebars:
- Show `must_change_password` warning banner if flag is set.
- Query DB for badge counts (disputes, active sessions, pending OTPs, pending password resets).
- Highlight active nav item by comparing current route.

**Admin sidebar** additionally queries:
- `$pendingDisputes` → badge on Disputes link
- `$activeSessions` → badge on Attendance link
- `$pendingPasswordResets` → contributes to Users link badge
- `$activeOtpCount` → contributes to Users link badge

**Student sidebar** queries:
- `$studentPendingDisputes` → badge on Disputes link

**Lecturer sidebar** queries:
- `$pendingDisputes` → badge on Disputes link (scoped to their sessions)

---

## 11. JavaScript Layer

### `public/assets/js/ajax.js`

Centralized AJAX and session management module.

#### `Api` Object

```javascript
Api.init()
// Called on DOMContentLoaded (only on authenticated pages with CSRF meta tag)
// Reads CSRF token from <meta name="csrf-token">
// Starts session keep-alive timer (every 5 minutes)
// Binds [data-logout] click handlers

Api.get(url, params = {})
// Appends params as query string
// Returns Promise<object>

Api.post(url, data = {})
// Content-Type: application/json
// Auto-injects X-CSRF-Token header and csrf_token in body
// Returns Promise<object>

Api.upload(url, formOrData)
// Content-Type: multipart/form-data (let browser set boundary)
// Appends csrf_token to FormData
// Returns Promise<object>

Api.withLoading(button, asyncFn)
// Disables button + shows loading text during asyncFn()
// Restores button on completion

Api.showError(err)
// Shows Toast.show('error', err.message)
```

#### `Toast` Object

```javascript
Toast.show(type, message, durationMs = 4000)
// type: 'success' | 'error' | 'warning' | 'info'
// Creates fixed-position toast at bottom of screen
// Auto-removes after durationMs
```

#### CSRF Flow

1. Page renders: `<meta name="csrf-token" content="abc123">` (from `Auth::csrfToken()`)
2. `Api.init()` reads it into `_csrfToken`
3. Every `Api.post()` / `Api.upload()` sends it as `X-CSRF-Token` header
4. Server verifies via `Auth::verifyCsrf()`, rotates, sends new token in `X-New-CSRF-Token`
5. `ajax.js` reads new token from response header and updates `_csrfToken`

#### Session Expiry

```javascript
// 401 from any API call → _handleSessionExpiry()
// Redirects to /auth/logout (which redirects to role login + ?expired=1)
// Note: 422 and 429 are NOT treated as session expiry
```

---

### `public/assets/js/qr-scanner.js`

Camera + jsQR + geolocation + scan submission.

#### `QRScanner` Object

```javascript
QRScanner.init({
  scanEndpoint:    `${BASE_URL}/api/attendance/scan.php`,
  requireLocation: false,        // true if PHP constant GEOFENCE_ENABLED is true
  videoId:         'qr-video',   // <video> element ID
  canvasId:        'qr-canvas',  // hidden <canvas> ID
  statusId:        'scanner-status',
  startBtnId:      'start-btn',
  stopBtnId:       'stop-btn',
  scanIntervalMs:  200,          // frame capture interval
  cooldownMs:      3000,         // pause after scan attempt
  onSuccess:       (data) => {},
  onError:         (data) => {},
})

QRScanner.start()   // async — requests camera, starts decode loop
QRScanner.stop()    // stops camera, GPS, decode loop
```

#### Camera Permission Strategy

`start()` does NOT await before `getUserMedia()`. The call happens synchronously within the click-handler user gesture, which Chrome requires. Permissions API check only happens **after** a failure, to diagnose the cause.

**Constraint fallback ladder:**
```javascript
const attempts = [
  { video: { facingMode: { ideal: 'environment' } }, audio: false },
  { video: { facingMode: 'user' },                   audio: false },
  { video: true,                                     audio: false },
];
```

Each constraint set is tried in order. Permission errors abort immediately; device/constraint errors try the next set.

#### Decode Loop

Uses `requestAnimationFrame` for battery efficiency. Every `scanIntervalMs`:
1. Checks `video.readyState === HAVE_ENOUGH_DATA`
2. Draws frame to hidden canvas
3. Calls `jsQR(imageData.data, width, height, { inversionAttempts: 'dontInvert' })`
4. If QR found and different from `lastCode`: submits to server

#### Scan Submission

```javascript
const body = { qr_data: decodedString };
if (currentPos) {
  body.lat               = currentPos.lat;
  body.lng               = currentPos.lng;
  body.location_accuracy = currentPos.accuracy;
}
// POST to scanEndpoint with credentials: 'same-origin'
```

**Fatal error codes** (stop scanner): `NOT_ENROLLED`, `HMAC_INVALID`, `OUTSIDE_GEOFENCE`, `LOCATION_REQUIRED`

**Non-fatal error codes** (3-sec cooldown, then continue): `TOKEN_EXPIRED`, `SESSION_CLOSED`, `ALREADY_SCANNED`, `SESSION_NOT_FOUND`

#### GPS Watch

```javascript
// Started in parallel with camera when requireLocation=true
navigator.geolocation.watchPosition(
  (pos) => { currentPos = { lat, lng, accuracy } },
  (err) => { /* show status message */ },
  { enableHighAccuracy: true, timeout: 15000, maximumAge: 10000 }
);
// Cleared in stop()
```

---

## 12. Routing & Server

### `index.php` — Front Controller

Reads `REQUEST_URI`, strips `BASE_URL` path prefix, looks up in `$routes` array. If found, `require`s the target PHP file. If not found, returns 404.

**Root URL behaviour:**
- Logged in → redirect to `/{role}/dashboard`
- Not logged in → show portal selector page (inline HTML with role cards)

**Routes table (abbreviated):**
```
student/login          → public/student/login.php
student/dashboard      → public/student/dashboard.php
student/scan           → public/student/scan.php
student/history        → public/student/history.php
lecturer/session/start → public/lecturer/session_start.php
lecturer/session/live  → public/lecturer/session_live.php
admin/users            → public/admin/users.php
admin/audit            → public/admin/audit.php
auth/forgot-password   → public/auth/forgot_password.php
auth/reset-password    → public/auth/reset_password.php
error/403              → public/errors/403.php
api/auth/logout        → api/auth/logout.php
```

All `api/` endpoints are called directly by the browser (not routed through `index.php`). The `.htaccess` file routes all non-file requests through `index.php`.

### `.htaccess`

```apache
RewriteEngine On
RewriteBase /edutrack/

# Pass requests for real files and directories directly
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d

# Everything else → index.php
RewriteRule ^ index.php [L]
```

### `serve.php` — Built-in Server Router

Used with `php -S 0.0.0.0:8080 serve.php`. Returns `false` for real files (CSS, JS, images) so PHP's built-in server serves them directly. All other requests are routed through `index.php`.

**Start command:**
```bash
cd c:/xampp/htdocs/edutrack
php -S 0.0.0.0:8080 serve.php
```

---

## 13. Security Implementation

### Password Security

```
User input: "Password@1"
Storage:    password_hash("Password@1" . "b428852c0d71a5faa88de7e77779300e", BCRYPT, cost=12)
Verify:     password_verify("Password@1" . PEPPER, $hash)
```

Timing-safe: `password_verify` always runs (even for non-existent users, using a dummy hash).

### QR Token Security

```
token       = bin2hex(random_bytes(32))           // 64 hex chars
hmac_input  = "$token|$unit_id|$expires_at"
hmac        = hash_hmac('sha256', hmac_input, APP_SECRET)
```

Validation uses `hash_equals($stored_hmac, $computed_hmac)` — timing-safe.

The HMAC binds the token to a specific unit and expiry time, preventing:
- Replay attacks (expired tokens rejected)
- Token substitution (HMAC includes unit_id)
- Forgery (requires APP_SECRET)

### Session Security

| Setting | Value | Purpose |
|---|---|---|
| `session.use_only_cookies` | 1 | Never expose session ID in URL |
| `session.use_strict_mode` | 1 | Reject externally supplied session IDs |
| `session.cookie_httponly` | 1 | JavaScript cannot read session cookie |
| `session.cookie_samesite` | Lax | CSRF mitigation at cookie level |
| `session.use_trans_sid` | 0 | Never embed session ID in links |
| `session.cookie_secure` | 1 | HTTPS-only in production |
| Session regeneration | Every 30 min + on login | Prevent session fixation |

### Geofencing

Haversine formula (server-side only):
```php
$R  = 6_371_000; // Earth radius in metres
$a  = sin($Δφ/2)² + cos($φ1) * cos($φ2) * sin($Δλ/2)²;
$d  = $R * 2 * atan2(sqrt($a), sqrt(1-$a));
```

Client-side GPS can be spoofed on rooted devices; this is a deterrent, not a cryptographic guarantee.

### OTP Security

- 6-digit numeric code: `str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT)`
- Stored in session as bcrypt hash (cannot be read even if session is compromised)
- Stored in DB as plaintext (admin visibility, 10-min expiry, cleared on use)
- Max 5 wrong guesses before OTP is invalidated
- Rate limited: part of the overall login rate-limiting

### CSRF Protection

- Token: `bin2hex(random_bytes(32))` — 64-character hex string
- One token per session, rotated after every successful POST
- Verified with `hash_equals()` (timing-safe)
- Sent in three locations by JS (fallback order): `X-CSRF-Token` header → JSON body → FormData field

---

## 14. Email System

SMTP credentials are loaded from `.env`. PHPMailer is used.

**Gmail App Password setup:**
- `SMTP_HOST=smtp.gmail.com`, `SMTP_PORT=587`, `SMTP_AUTH=true`
- `SMTP_PASS` must be a Google App Password (16-char, no spaces), not the Gmail login password
- 2-Step Verification must be enabled on the Google account

**Email types sent:**

| Trigger | Method | Content |
|---|---|---|
| Login with email account | `sendOtp()` | 6-digit code, 10-min expiry |
| Forgot password (email route) | `sendPasswordReset()` | One-time reset link |
| Admin approves manual reset | `sendPasswordResetApproved()` | Temp password in styled box |

**SMTP failure behaviour:**
- `EmailService::send()` returns `false`
- Login: returns HTTP 503 with clear message; OTP still stored in DB column for admin to read and forward manually
- Forgot password: queued in `password_reset_requests` for admin approval
- Admin sees active OTPs panel on Users page; sidebar shows badge count

---

## 15. File Uploads & PDF Exports

### CSV Uploads

- Max size: `MAX_CSV_SIZE_BYTES` (5 MB)
- Allowed MIME types: `text/csv`, `text/plain`, `application/vnd.ms-excel`
- Validated with `is_uploaded_file($tmpName)` before reading
- Parsed with `fgetcsv()` — handles quoted fields, commas in values
- Header row auto-detected by checking known column names on row 1
- Errors per row returned to UI; valid rows saved in transaction

### PDF Generation

- Library: mPDF via Composer
- All PDFs generated to `EXPORTS_PATH` first, then streamed
- `PDFHelper::download()` → `Content-Disposition: attachment`
- `PDFHelper::inline()` → `Content-Disposition: inline`
- PDFs include: school name, APP_NAME, generation date, styled tables
- Transcript and marks sheet compute grades at generation time from DB

### File Upload Security

- CSV files stored in `UPLOADS_PATH` (outside web root — not accessible via browser)
- PDF exports stored in `EXPORTS_PATH` (also outside web root)
- Temporary files cleaned up after processing
- `is_uploaded_file()` verified before any file read
