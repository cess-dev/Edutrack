<?php
/**
 * EduTrack — Forgot Password API
 *
 * Method:  POST (application/json or form-encoded)
 * URL:     /api/auth/forgot_password.php
 * Access:  Public (not authenticated)
 *
 * Request body:
 *   { "identifier": "STU2024001"  }   ← reg_number OR email address
 *
 * Routing logic:
 *   1. Look up user by reg_number or email.
 *   2. If SMTP is disabled            → admin queue
 *   3. If user has no email address   → admin queue
 *   4. If reset_count >= EMAIL_LIMIT  → admin queue
 *   5. Otherwise                      → generate token, send email, increment count
 *
 * Response always returns success:true with a generic message to avoid
 * leaking whether the account exists.
 *
 *   { "success": true, "method": "email"|"admin_queue", "message": "..." }
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/services/EmailService.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// ── Parse input ───────────────────────────────────────────────────────────────
$raw        = file_get_contents('php://input');
$json       = json_decode($raw, true);
$identifier = trim($json['identifier'] ?? $_POST['identifier'] ?? '');

if (empty($identifier)) {
    echo json_encode([
        'success' => false,
        'message' => 'Please provide your registration number or email address.',
    ]);
    exit;
}

// ── Lookup user (by reg_number OR email) ─────────────────────────────────────
$user = DB::row(
    "SELECT id, reg_number, full_name, email, role, is_active, password_reset_count
     FROM users
     WHERE (reg_number = ? OR (email IS NOT NULL AND email = ?))
       AND is_active = 1
     LIMIT 1",
    [strtoupper($identifier), $identifier]
);

// Generic success response — don't reveal whether the account exists
$genericOk = [
    'success' => true,
    'message' => 'If that account exists, you will receive further instructions shortly.',
];

if (!$user) {
    // Sleep briefly to prevent timing-based account enumeration
    usleep(random_int(50000, 200000));
    echo json_encode($genericOk);
    exit;
}

$userId     = (int) $user['id'];
$resetCount = (int) ($user['password_reset_count'] ?? 0);
$emailLimit = defined('PASSWORD_RESET_EMAIL_LIMIT') ? (int) PASSWORD_RESET_EMAIL_LIMIT : 3;

// ── Decide which path to take ─────────────────────────────────────────────────
$hasEmail    = !empty($user['email']);
$smtpEnabled = EmailService::isEnabled();
$underLimit  = $resetCount < $emailLimit;

$useEmail = $hasEmail && $smtpEnabled && $underLimit;

if ($useEmail) {
    // ── Email path: generate token, send link ─────────────────────────────────
    $tokenHours = defined('PASSWORD_RESET_TOKEN_HOURS') ? (int) PASSWORD_RESET_TOKEN_HOURS : 24;
    $rawToken   = bin2hex(random_bytes(32));          // 64 hex chars
    $tokenHash  = hash('sha256', $rawToken);
    $expiresAt  = date('Y-m-d H:i:s', strtotime("+{$tokenHours} hours"));

    // Invalidate any existing unused tokens for this user
    DB::execute(
        "DELETE FROM password_reset_tokens WHERE user_id = ? AND used_at IS NULL",
        [$userId]
    );

    // Insert new token
    DB::insert(
        "INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
         VALUES (?, ?, ?)",
        [$userId, $tokenHash, $expiresAt]
    );

    // Increment reset count
    DB::execute(
        "UPDATE users SET password_reset_count = password_reset_count + 1 WHERE id = ?",
        [$userId]
    );

    // Send email (returns false if mail() fails, but we still report success)
    $sent = EmailService::sendPasswordReset(
        $user['email'],
        $user['full_name'],
        $rawToken,
        $user['role']
    );

    echo json_encode(array_merge($genericOk, [
        'method' => 'email',
    ]));

} else {
    // ── Admin queue path ──────────────────────────────────────────────────────
    // Check if there's already a pending request for this user
    $existing = DB::row(
        "SELECT id FROM password_reset_requests
         WHERE user_id = ? AND status = 'pending'
         LIMIT 1",
        [$userId]
    );

    if (!$existing) {
        DB::insert(
            "INSERT INTO password_reset_requests (user_id) VALUES (?)",
            [$userId]
        );
    }

    // Choose a specific message based on reason
    if (!$hasEmail) {
        $detail = 'Your account has no email address on file. An administrator will need to reset it manually.';
    } elseif (!$smtpEnabled) {
        $detail = 'Email is not configured on this system. An administrator will need to reset your password manually.';
    } else {
        $detail = 'You have reached the maximum number of email resets. An administrator will need to verify your identity and reset your password.';
    }

    echo json_encode([
        'success' => true,
        'method'  => 'admin_queue',
        'message' => $detail,
    ]);
}
