<?php
/**
 * EduTrack — Admin: Update User Contact Details
 *
 * Method:  POST
 * URL:     /api/admin/users_update.php
 * Access:  Admin only
 *
 * Request body (JSON):
 *   user_id: int
 *   email:   string|null
 *   phone:   string|null
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
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
$email  = $body['email'] ?? null;
$phone  = $body['phone'] ?? null;

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid user_id is required.']);
    exit;
}

$result = UserModel::updateContact($userId, $email ?? '', $phone ?? '');

if (!$result['success']) {
    http_response_code(400);
    echo json_encode($result);
    exit;
}

Auth::audit('user_updated', 'users', $userId);
echo json_encode($result);