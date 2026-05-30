<?php
/**
 * EduTrack — Login API Endpoint
 *
 * Two-step login when SMTP is configured:
 *   Step 1 — POST reg_number + password
 *             Valid credentials with email on file → OTP sent, returns step:"otp"
 *             Valid credentials but no email       → session created, returns redirect
 *   Step 2 — handled by /api/auth/verify_otp.php
 *
 * Single-step login when SMTP is disabled:
 *   POST reg_number + password → session created, returns redirect immediately
 *
 * Method:  POST
 * URL:     /api/auth/login.php
 * Access:  Public
 *
 * Success (direct login):
 *   { "success": true, "step": "done", "user": {...}, "redirect": "..." }
 *
 * OTP required:
 *   { "success": true, "step": "otp", "email_hint": "ed***@gmail.com", "message": "..." }
 *
 * Error:
 *   { "success": false, "message": "...", "attempts_remaining"?: int }
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/services/EmailService.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');

// Already logged in → go straight to dashboard
if (Auth::isLoggedIn()) {
    echo json_encode([
        'success'  => true,
        'step'     => 'done',
        'message'  => 'Already logged in.',
        'user'     => Auth::user(),
        'redirect' => BASE_URL . '/' . Auth::role() . '/dashboard',
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// ── Parse body ────────────────────────────────────────────────────────────────
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($ct, 'application/json')) {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $regNumber = trim($body['reg_number'] ?? '');
    $password  = $body['password'] ?? '';
} else {
    $regNumber = trim($_POST['reg_number'] ?? '');
    $password  = $_POST['password'] ?? '';
}

if (empty($regNumber) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Registration number and password are required.']);
    exit;
}

// ── Rate limiting ─────────────────────────────────────────────────────────────
$ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$attemptKey = 'login_attempts_' . md5($ip);
$lockoutKey = 'login_lockout_'  . md5($ip);

if (isset($_SESSION[$lockoutKey]) && $_SESSION[$lockoutKey] > time()) {
    $wait = ceil(($_SESSION[$lockoutKey] - time()) / 60);
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => "Too many failed attempts. Please wait {$wait} minute(s).",
    ]);
    exit;
}

// ── Credential validation (manual — does not write session) ───────────────────
$identifier = trim($regNumber);
$isEmail    = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
$column     = $isEmail ? 'email' : 'reg_number';

$user = DB::row(
    "SELECT id, reg_number, full_name, email, phone,
            password_hash, role, is_active, must_change_password
     FROM users
     WHERE {$column} = ?
     LIMIT 1",
    [$identifier]
);

// Timing-safe: always run password_verify even when user not found
$validHash = $user['password_hash'] ?? '$2y$12$invalidpaddingthatnevermatchesXXXXXXXXXXXXXXXXXXX';
$passOk    = password_verify($password . PASSWORD_PEPPER, $validHash);

if (!$user || !$user['is_active'] || !$passOk) {
    $_SESSION[$attemptKey] = ($_SESSION[$attemptKey] ?? 0) + 1;

    if ($_SESSION[$attemptKey] >= 5) {
        $_SESSION[$lockoutKey] = time() + 300; // 5 minutes
        $_SESSION[$attemptKey] = 0;
        // 429 — not 401 — so ajax.js does NOT treat this as session expiry
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Too many failed attempts. Please wait 5 minutes before trying again.']);
        exit;
    }

    $remaining = 5 - $_SESSION[$attemptKey];
    // 422 — not 401 — so ajax.js does NOT misread this as session expiry
    http_response_code(422);
    echo json_encode([
        'success'            => false,
        'message'            => 'Invalid registration number or password.',
        'attempts_remaining' => $remaining,
    ]);
    exit;
}

// ── Credentials valid — clear rate-limit counters ─────────────────────────────
unset($_SESSION[$attemptKey], $_SESSION[$lockoutKey]);

// ── Route: OTP or direct login ────────────────────────────────────────────────
$smtpOn       = EmailService::isEnabled();
$hasEmail     = !empty($user['email']);
$deliverable  = $hasEmail && EmailService::isDeliverableAddress($user['email']);

if ($smtpOn && $deliverable) {
    // Generate 6-digit OTP
    $otp        = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires    = time() + 600; // 10 minutes
    $expiresAt  = date('Y-m-d H:i:s', $expires);

    // ── Persist OTP in the users table (plaintext, short-lived) ──────────────
    // This is the in-app fallback: if email delivery fails the admin can view
    // the code on the Users page and relay it to the user by phone/WhatsApp.
    DB::execute(
        "UPDATE users SET login_otp = ?, login_otp_expires = ? WHERE id = ?",
        [$otp, $expiresAt, $user['id']]
    );

    // ── Store hashed OTP in session as the primary verification ──────────────
    $_SESSION['otp_pending'] = [
        'user'     => $user,
        'otp_hash' => password_hash($otp, PASSWORD_BCRYPT, ['cost' => 10]),
        'expires'  => $expires,
        'attempts' => 0,
    ];
    unset($_SESSION['otp_pending']['user']['password_hash']);

    // ── Try to email the code ─────────────────────────────────────────────────
    $sent = EmailService::sendOtp($user['email'], $user['full_name'], $otp);

    // Mask email for display: "ed***@gmail.com"
    [$local, $domain] = explode('@', $user['email'], 2);
    $emailHint = substr($local, 0, min(3, strlen($local))) . '***@' . $domain;

    if ($sent) {
        $message = "A 6-digit code has been sent to {$emailHint}. It expires in 10 minutes.";
    } else {
        // Email failed — OTP is still valid in DB. Admin can view it on the Users page.
        $message = "We could not deliver the code by email. "
                 . "Please ask your administrator to look up your one-time code "
                 . "(visible on the Admin → Users page).";
    }

    echo json_encode([
        'success'      => true,
        'step'         => 'otp',
        'email_hint'   => $sent ? $emailHint : null,
        'smtp_failed'  => !$sent,
        'message'      => $message,
    ]);

} else {
    // SMTP not configured or user has no email → skip OTP
    Auth::loginAsUser($user);

    $redirect = BASE_URL . '/' . $user['role'] . '/dashboard';
    echo json_encode([
        'success'  => true,
        'step'     => 'done',
        'message'  => 'Login successful.',
        'user'     => [
            'id'         => $user['id'],
            'full_name'  => $user['full_name'],
            'role'       => $user['role'],
            'reg_number' => $user['reg_number'],
        ],
        'redirect' => $redirect,
    ]);
}
