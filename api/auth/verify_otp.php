<?php
/**
 * EduTrack — OTP Verification API
 *
 * Validates the 6-digit code after login.php returns step:"otp".
 *
 * Verification order:
 *   1. Session-based hash (primary — fastest, no DB read)
 *   2. DB column fallback — used when session is missing (e.g. tab was
 *      closed and re-opened after the admin relayed the code verbally).
 *      Requires the user to also send their reg_number so we can look
 *      them up without a session.
 *
 * Method:  POST (application/json)
 * URL:     /api/auth/verify_otp.php
 * Access:  Public (session-gated or DB-gated)
 *
 * Request body:
 *   { "otp": "123456" }                          ← session path
 *   { "otp": "123456", "reg_number": "LEC004" }  ← DB fallback path
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

// ── Parse body ────────────────────────────────────────────────────────────────
$raw       = file_get_contents('php://input');
$json      = json_decode($raw, true);
$otp       = trim($json['otp']        ?? $_POST['otp']        ?? '');
$regNumber = strtoupper(trim($json['reg_number'] ?? $_POST['reg_number'] ?? ''));

if (empty($otp)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter the 6-digit code.']);
    exit;
}

// ── Path 1: session-based verification ───────────────────────────────────────
$pending = $_SESSION['otp_pending'] ?? null;

if ($pending) {

    if (time() > $pending['expires']) {
        unset($_SESSION['otp_pending']);
        // Clear DB OTP too
        if (!empty($pending['user']['id'])) {
            DB::execute("UPDATE users SET login_otp = NULL, login_otp_expires = NULL WHERE id = ?",
                [$pending['user']['id']]);
        }
        http_response_code(410);
        echo json_encode(['success' => false, 'expired' => true,
            'message' => 'Your code has expired. Please sign in again.']);
        exit;
    }

    if ($pending['attempts'] >= 5) {
        unset($_SESSION['otp_pending']);
        if (!empty($pending['user']['id'])) {
            DB::execute("UPDATE users SET login_otp = NULL, login_otp_expires = NULL WHERE id = ?",
                [$pending['user']['id']]);
        }
        http_response_code(429);
        echo json_encode(['success' => false, 'expired' => true,
            'message' => 'Too many incorrect attempts. Please sign in again.']);
        exit;
    }

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

    // ── Session OTP correct ───────────────────────────────────────────────────
    $user = $pending['user'];
    unset($_SESSION['otp_pending']);

    // Clear DB OTP (single-use)
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
    exit;
}

// ── Path 2: DB fallback (session missing — admin relayed code manually) ───────
// Requires reg_number so we can identify the user without a session.
if (empty($regNumber)) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'No login in progress. Please sign in again.',
    ]);
    exit;
}

$dbUser = DB::row(
    "SELECT id, reg_number, full_name, email, role, is_active,
            must_change_password, login_otp, login_otp_expires
     FROM users
     WHERE (reg_number = ? OR (email IS NOT NULL AND email = ?))
       AND is_active = 1
       AND login_otp IS NOT NULL
     LIMIT 1",
    [$regNumber, $regNumber]
);

if (!$dbUser) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'No active code found. Please sign in again to get a new one.',
    ]);
    exit;
}

if (strtotime($dbUser['login_otp_expires']) < time()) {
    DB::execute("UPDATE users SET login_otp = NULL, login_otp_expires = NULL WHERE id = ?",
        [$dbUser['id']]);
    http_response_code(410);
    echo json_encode([
        'success' => false,
        'expired' => true,
        'message' => 'Your code has expired. Please sign in again.',
    ]);
    exit;
}

if ($dbUser['login_otp'] !== $otp) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Incorrect code. Please check with your administrator.',
    ]);
    exit;
}

// ── DB OTP correct ────────────────────────────────────────────────────────────
DB::execute("UPDATE users SET login_otp = NULL, login_otp_expires = NULL WHERE id = ?",
    [$dbUser['id']]);

Auth::loginAsUser($dbUser);

echo json_encode([
    'success'  => true,
    'message'  => 'Login successful.',
    'user'     => [
        'id'         => $dbUser['id'],
        'full_name'  => $dbUser['full_name'],
        'role'       => $dbUser['role'],
        'reg_number' => $dbUser['reg_number'],
    ],
    'redirect' => BASE_URL . '/' . $dbUser['role'] . '/dashboard',
]);
