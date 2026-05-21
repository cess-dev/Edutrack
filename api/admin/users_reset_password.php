<?php
/**
 * EduTrack — Admin: Reset User Password
 *
 * Method:  POST
 * URL:     /api/admin/users_reset_password.php
 * Access:  Admin only
 *
 * Request body (JSON):
 *   target_user_id: int
 *   new_password:   string
 */

define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/UserModel.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');
Auth::requireRole('admin', true);
Auth::verifyCsrf(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body         = json_decode(file_get_contents('php://input'), true) ?? [];
$targetUserId = (int)($body['target_user_id'] ?? 0);
$newPassword  = $body['new_password'] ?? '';

if ($targetUserId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid target_user_id is required.']);
    exit;
}

$result = UserModel::adminResetPassword($targetUserId, $newPassword, Auth::id());

if (!$result['success']) {
    http_response_code(400);
    echo json_encode($result);
    exit;
}

echo json_encode($result);