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
 *   email        (optional)
 *   phone        (optional)
 *   student_id   (optional, parent role only) — link parent to this student on creation
 *   relationship (optional, parent role only) — e.g. "Mother", "Father", "Guardian"
 *
 * Success response (201):
 *   { "success": true, "id": int, "message": string }
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

// ── Optional: link parent to a student immediately on creation ────────────────
$role      = strtolower(trim($body['role'] ?? ''));
$studentId = isset($body['student_id']) ? (int) $body['student_id'] : 0;
$rel       = trim($body['relationship'] ?? 'Parent') ?: 'Parent';

if ($role === 'parent' && $studentId > 0) {
    $link = UserModel::linkParentToStudent($result['id'], $studentId, $rel);

    if ($link['success']) {
        // Append the link confirmation to the creation message
        $result['message'] .= ' ' . $link['message'];
        $result['linked']   = true;
    }
    // Non-fatal: the parent account was still created successfully even if
    // the link failed (e.g. student ID no longer exists). The admin can
    // link manually via the 🔗 button.
}

Auth::audit('user_created', 'users', $result['id'], [
    'role'            => $body['role'] ?? '',
    'reg_number'      => strtoupper(trim($body['reg_number'] ?? '')),
    'linked_student'  => $studentId ?: null,
    'relationship'    => $studentId ? $rel : null,
]);

http_response_code(201);
echo json_encode($result);