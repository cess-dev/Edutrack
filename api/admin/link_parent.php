<?php
/**
 * EduTrack — Admin: Link Parent to Student
 *
 * Method:  POST
 * URL:     /api/admin/link_parent.php
 * Access:  Admin only
 *
 * Request body (JSON):
 *   parent_id:    int
 *   student_id:   int
 *   relationship: string  (optional, default 'Parent')
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
$parentId     = (int)($body['parent_id']    ?? 0);
$studentId    = (int)($body['student_id']   ?? 0);
$relationship = trim($body['relationship']  ?? 'Parent');

if ($parentId <= 0 || $studentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Valid parent_id and student_id are required.']);
    exit;
}

$result = UserModel::linkParentToStudent($parentId, $studentId, $relationship);

if (!$result['success']) {
    http_response_code(400);
    echo json_encode($result);
    exit;
}

Auth::audit('parent_linked', 'parent_student_links', $studentId, [
    'parent_id'    => $parentId,
    'relationship' => $relationship,
]);

echo json_encode($result);