<?php
/**
 * EduTrack — OTP Verification API
 *
 * Validates the 6-digit code after login.php returns step:"otp".
 * Verification is session-based (the hashed OTP is stored in $_SESSION['otp_pending']).
 * On success the session OTP and DB column are both cleared.
 *
 * Method:  POST (application/json)
 * URL:     /api/auth/verify_otp.php
 * Access:  Public (session-gated by otp_pending)
 *
 * Request body:
 *   { "otp": "123456" }
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
$raw  = file_get_contents('php://input');
$json = json_decode($raw, true);
$otp  = trim($json['otp'] ?? $_POST['otp'] ?? '');

if (empty($otp)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter the 6-digit code.']);
    exit;
}

// ── Load pending OTP from session ─────────────────────────────────────────────
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
    DB::execute("UPDATE users SET login_otp = NULL, login_otp_expires = NULL WHERE id = ?",
        [$pending['user']['id'] ?? 0]);
    http_response_code(410);
    echo json_encode([
        'success' => false,
        'expired' => true,
        'message' => 'Your code has expired. Please sign in again to get a new one.',
    ]);
    exit;
}

// ── Check attempts (max 5 wrong guesses) ─────────────────────────────────────
if ($pending['attempts'] >= 5) {
    unset($_SESSION['otp_pending']);
    DB::execute("UPDATE users SET login_otp = NULL, login_otp_expires = NULL WHERE id = ?",
        [$pending['user']['id'] ?? 0]);
    http_response_code(429);
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
    http_response_code(422);
    echo json_encode([
        'success'            => false,
        'message'            => 'Incorrect code. ' . ($remaining > 0
            ? "{$remaining} attempt(s) remaining."
            : 'No attempts left.'),
        'attempts_remaining' => $remaining,
    ]);
    exit;
}

// ── OTP correct — complete login ──────────────────────────────────────────────
$user = $pending['user'];
unset($_SESSION['otp_pending']);

// Clear the DB column (single-use)
DB::execute("UPDATE users SET login_otp = NULL, login_otp_expires = NULL WHERE id = ?",
    [$user['id']]);

Auth::loginAsUser($user);

echo json_encode([
    'success'  => true,
    'message'  => 'Login successful.',
    'user'     => [
        'id'         => $user['id'],
        'full_name'  => $user['full_name'],
        'role'       => $user['role'],
        'reg_number' => $user['reg_number'],
    ],
    'redirect' => BASE_URL . '/' . $user['role'] . '/dashboard',
]);
