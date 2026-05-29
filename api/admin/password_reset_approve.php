<?php
/**
 * EduTrack — Admin: Approve a Password Reset Request
 *
 * Method:  POST (application/json)
 * URL:     /api/admin/password_reset_approve.php
 * Access:  Admin only
 *
 * Request body:
 *   { "request_id": 5, "action": "approve" | "reject" }
 *
 * On approve:
 *   - A random temp password is generated.
 *   - The user's password is set to it with must_change_password = 1.
 *   - If the user has an email and SMTP is enabled, the new temp password is
 *     emailed to them.
 *   - The request row is marked approved.
 *
 * Response:
 *   {
 *     "success":    true,
 *     "action":     "approved" | "rejected",
 *     "temp_pass":  "<generated password>",   ← only on approve
 *     "emailed":    bool,                      ← only on approve
 *     "message":    string
 *   }
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/services/EmailService.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');
Auth::requireRole('admin', true);
Auth::verifyCsrf(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$raw       = file_get_contents('php://input');
$json      = json_decode($raw, true);
$requestId = (int) ($json['request_id'] ?? 0);
$action    = trim($json['action'] ?? 'approve');

if ($requestId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'request_id is required.']);
    exit;
}

if (!in_array($action, ['approve', 'reject'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'action must be "approve" or "reject".']);
    exit;
}

// ── Fetch the request ─────────────────────────────────────────────────────────
$req = DB::row(
    "SELECT r.id, r.user_id, r.status,
            u.full_name, u.email, u.reg_number, u.is_active
     FROM password_reset_requests r
     JOIN users u ON u.id = r.user_id
     WHERE r.id = ? AND r.status = 'pending'
     LIMIT 1",
    [$requestId]
);

if (!$req) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Reset request not found or already resolved.']);
    exit;
}

if (!$req['is_active']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'This account is deactivated — reactivate it first.']);
    exit;
}

$adminId = Auth::id();

if ($action === 'reject') {
    DB::execute(
        "UPDATE password_reset_requests
         SET status = 'rejected', resolved_by = ?, resolved_at = NOW()
         WHERE id = ?",
        [$adminId, $requestId]
    );

    Auth::audit('password_reset_reject', 'users', $req['user_id'], [
        'request_id' => $requestId,
    ]);

    echo json_encode([
        'success' => true,
        'action'  => 'rejected',
        'message' => "Password reset request for {$req['full_name']} has been rejected.",
    ]);
    exit;
}

// ── Approve: generate temp password ──────────────────────────────────────────
// Build a readable temp password: Adj + Noun + 3-digit number + symbol
$adjectives = ['Blue','Fast','Strong','Bright','Smart','Quick','Bold','Calm'];
$nouns      = ['Tiger','Eagle','River','Storm','Cloud','Cedar','Maple','Stone'];
$tempPass   = $adjectives[array_rand($adjectives)]
            . $nouns[array_rand($nouns)]
            . str_pad(random_int(1, 999), 3, '0', STR_PAD_LEFT)
            . '!';

$newHash = password_hash($tempPass . PASSWORD_PEPPER, PASSWORD_BCRYPT, ['cost' => 12]);

// Update user's password and flag them to change it
DB::execute(
    "UPDATE users
     SET password_hash = ?, must_change_password = 1, password_reset_count = 0
     WHERE id = ?",
    [$newHash, $req['user_id']]
);

// Resolve the request
DB::execute(
    "UPDATE password_reset_requests
     SET status = 'approved', resolved_by = ?, resolved_at = NOW()
     WHERE id = ?",
    [$adminId, $requestId]
);

// Invalidate any outstanding email tokens
DB::execute(
    "DELETE FROM password_reset_tokens WHERE user_id = ? AND used_at IS NULL",
    [$req['user_id']]
);

// Try to email the new temp password
$emailed = false;
if (!empty($req['email']) && EmailService::isEnabled()) {
    $emailed = EmailService::sendPasswordResetApproved(
        $req['email'],
        $req['full_name'],
        $tempPass
    );
}

Auth::audit('password_reset_approve', 'users', $req['user_id'], [
    'request_id' => $requestId,
    'emailed'    => $emailed,
]);

echo json_encode([
    'success'   => true,
    'action'    => 'approved',
    'temp_pass' => $tempPass,
    'emailed'   => $emailed,
    'message'   => "Password reset for {$req['full_name']} approved."
                 . ($emailed ? ' New password has been emailed.' : ' Share the temp password below with the user.'),
]);
