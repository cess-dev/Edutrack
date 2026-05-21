<?php
/**
 * EduTrack — Admin: Toggle User Active Status
 *
 * Method:  POST
 * URL:     /api/admin/users_toggle_active.php
 * Access:  Admin only
 *
 * Request body (JSON):
 *   user_id: int
 *   active:  bool
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

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$userId = (int)($body['user_id'] ?? 0);
$active = filter_var($body['active'] ?? false, FILTER_VALIDATE_BOOLEAN);

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid user_id is required.']);
    exit;
}

$result = UserModel::setActive($userId, $active, Auth::id());

if (!$result['success']) {
    http_response_code(400);
    echo json_encode($result);
    exit;
}

echo json_encode($result);