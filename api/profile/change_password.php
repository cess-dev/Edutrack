<?php
/**
 * EduTrack — Profile: Change Password
 * Any authenticated user can change their own password.
 *
 * Method: POST · URL: /api/profile/change_password.php
 * Access: Any logged-in role
 *
 * Request body (JSON):
 *   current_password: string
 *   new_password:     string
 */

define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/UserModel.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');
Auth::requireLogin(true);
Auth::verifyCsrf(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$current = $body['current_password'] ?? '';
$new     = $body['new_password']     ?? '';

if (empty($current) || empty($new)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Both current and new passwords are required.',
    ]);
    exit;
}

$result = UserModel::changePassword(Auth::id(), $current, $new);

if (!$result['success']) {
    http_response_code(400);
}

if ($result['success']) {
    Auth::audit('password_changed', 'users', Auth::id());
}

echo json_encode($result);