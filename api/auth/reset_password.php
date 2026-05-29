<?php
/**
 * EduTrack — Reset Password via Token API
 *
 * Method:  POST (application/json or form-encoded)
 * URL:     /api/auth/reset_password.php
 * Access:  Public (not authenticated) — validated by token
 *
 * Request body:
 *   {
 *     "token":    "<raw token from email link>",
 *     "password": "<new password>",
 *     "password2": "<confirm new password>"
 *   }
 *
 * On success:
 *   - Sets the new password (bcrypt + pepper)
 *   - Marks must_change_password = 0
 *   - Marks the token as used
 *   - Returns { success: true, message: "..." }
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/UserModel.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// ── Parse input ───────────────────────────────────────────────────────────────
$raw   = file_get_contents('php://input');
$json  = json_decode($raw, true);

$rawToken = trim($json['token']     ?? $_POST['token']     ?? '');
$password = trim($json['password']  ?? $_POST['password']  ?? '');
$confirm  = trim($json['password2'] ?? $_POST['password2'] ?? '');

if (empty($rawToken)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Reset token is missing.']);
    exit;
}

if (empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'New password is required.']);
    exit;
}

if ($password !== $confirm) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit;
}

// ── Validate password strength via UserModel helper ───────────────────────────
$strength = UserModel::validatePasswordStrength($password);
if (!$strength['valid']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $strength['message']]);
    exit;
}

// ── Look up token ─────────────────────────────────────────────────────────────
$tokenHash = hash('sha256', $rawToken);

$tokenRow = DB::row(
    "SELECT t.id, t.user_id, t.expires_at, t.used_at,
            u.id AS uid, u.reg_number, u.full_name, u.email, u.is_active
     FROM password_reset_tokens t
     JOIN users u ON u.id = t.user_id
     WHERE t.token_hash = ?
     LIMIT 1",
    [$tokenHash]
);

if (!$tokenRow) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired reset link. Please request a new one.']);
    exit;
}

if ($tokenRow['used_at'] !== null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'This reset link has already been used. Please request a new one.']);
    exit;
}

if (strtotime($tokenRow['expires_at']) < time()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'This reset link has expired. Please request a new one.']);
    exit;
}

if (!$tokenRow['is_active']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'This account has been deactivated. Contact your administrator.']);
    exit;
}

// ── Set new password ──────────────────────────────────────────────────────────
$newHash = password_hash($password . PASSWORD_PEPPER, PASSWORD_BCRYPT, ['cost' => 12]);

DB::execute(
    "UPDATE users
     SET password_hash = ?, must_change_password = 0
     WHERE id = ?",
    [$newHash, $tokenRow['user_id']]
);

// Mark token as used
DB::execute(
    "UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?",
    [$tokenRow['id']]
);

// If the user has an active session, update it too
if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$tokenRow['user_id']) {
    Auth::clearMustChangePassword();
}

echo json_encode([
    'success' => true,
    'message' => 'Your password has been reset successfully. You can now sign in.',
]);
