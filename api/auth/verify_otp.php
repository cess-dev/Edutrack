<?php
/**
 * EduTrack — OTP Verification API
 *
 * Called after the login API returns step:"otp".
 * Validates the 6-digit code stored in the session and, if correct,
 * completes the login by writing the full user session.
 *
 * Method:  POST (application/json)
 * URL:     /api/auth/verify_otp.php
 * Access:  Public (session-gated by otp_pending)
 *
 * Request body:
 *   { "otp": "123456" }
 *
 * Success (200):
 *   { "success": true, "user": {...}, "redirect": "..." }
 *
 * Errors:
 *   400 — missing/empty OTP
 *   401 — wrong code, or too many attempts, or session expired
 *   404 — no pending OTP in session (login not started or already used)
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// ── Parse OTP from body ───────────────────────────────────────────────────────
$raw = file_get_contents('php://input');
$json = json_decode($raw, true);
$otp = trim($json['otp'] ?? $_POST['otp'] ?? '');

if (empty($otp)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter the 6-digit code.']);
    exit;
}

// ── Check session for pending OTP ────────────────────────────────────────────
$pending = $_SESSION['otp_pending'] ?? null;

if (!$pending) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'No login in progress. Please sign in again.',
    ]);
    exit;
}

// ── Check expiry ──────────────────────────────────────────────────────────────
if (time() > $pending['expires']) {
    unset($_SESSION['otp_pending']);
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'expired' => true,
        'message' => 'Your code has expired. Please sign in again to get a new one.',
    ]);
    exit;
}

// ── Check attempts (max 5 wrong guesses before lockout) ───────────────────────
if ($pending['attempts'] >= 5) {
    unset($_SESSION['otp_pending']);
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'expired' => true,
        'message' => 'Too many incorrect attempts. Please sign in again.',
    ]);
    exit;
}

// ── Verify code ───────────────────────────────────────────────────────────────
if (!password_verify($otp, $pending['otp_hash'])) {
    $_SESSION['otp_pending']['attempts']++;
    $remaining = 5 - $_SESSION['otp_pending']['attempts'];

    http_response_code(401);
    echo json_encode([
        'success'            => false,
        'message'            => 'Incorrect code. ' . ($remaining > 0 ? "{$remaining} attempt(s) remaining." : 'No attempts left.'),
        'attempts_remaining' => $remaining,
    ]);
    exit;
}

// ── OTP correct — complete login ──────────────────────────────────────────────
$user = $pending['user'];
unset($_SESSION['otp_pending']);

Auth::loginAsUser($user);

$redirect = BASE_URL . '/' . $user['role'] . '/dashboard';

echo json_encode([
    'success'  => true,
    'message'  => 'Login successful.',
    'user'     => [
        'id'         => $user['id'],
        'full_name'  => $user['full_name'],
        'role'       => $user['role'],
        'reg_number' => $user['reg_number'],
    ],
    'redirect' => $redirect,
]);
