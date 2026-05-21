<?php
/**
 * EduTrack — Admin: Create User Account
 *
 * Method:  POST
 * URL:     /api/admin/users_create.php
 * Access:  Admin only
 *
 * Request body (JSON):
 *   full_name, reg_number, role, password,
 *   email (optional), phone (optional)
 *
 * Success response (201):
 *   { "success": true, "id": int, "message": string }
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

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$result = UserModel::create([
    'full_name'  => $body['full_name']  ?? '',
    'reg_number' => $body['reg_number'] ?? '',
    'role'       => $body['role']       ?? '',
    'password'   => $body['password']   ?? '',
    'email'      => $body['email']      ?? '',
    'phone'      => $body['phone']      ?? '',
    'created_by' => Auth::id(),
]);

if (!$result['success']) {
    http_response_code(400);
    echo json_encode($result);
    exit;
}

Auth::audit('user_created', 'users', $result['id'], [
    'role'       => $body['role'] ?? '',
    'reg_number' => strtoupper(trim($body['reg_number'] ?? '')),
]);

http_response_code(201);
echo json_encode($result);