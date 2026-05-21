<?php
/**
 * EduTrack — Admin: Update Course
 *
 * Method:  POST
 * URL:     /api/admin/course_update.php
 * Access:  Admin only
 *
 * Request body (JSON):
 *   course_id, code, name, department, duration_years, is_active
 */

define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');
Auth::requireRole('admin', true);
Auth::verifyCsrf(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$courseId = (int)($body['course_id'] ?? 0);
$code     = strtoupper(trim($body['code'] ?? ''));
$name     = trim($body['name']            ?? '');
$dept     = trim($body['department']      ?? '') ?: null;
$years    = max(1, min(6, (int)($body['duration_years'] ?? 4)));
$isActive = (int)filter_var($body['is_active'] ?? 1, FILTER_VALIDATE_BOOLEAN);

if ($courseId <= 0 || empty($code) || empty($name)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'course_id, code and name are required.']);
    exit;
}

// Check code uniqueness (excluding this course)
$conflict = DB::row(
    "SELECT id FROM courses WHERE code = ? AND id != ?",
    [$code, $courseId]
);
if ($conflict) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => "Course code '{$code}' is already used."]);
    exit;
}

DB::execute(
    "UPDATE courses
     SET code = ?, name = ?, department = ?, duration_years = ?, is_active = ?
     WHERE id = ?",
    [$code, $name, $dept, $years, $isActive, $courseId]
);

Auth::audit('course_updated', 'courses', $courseId);
echo json_encode(['success' => true, 'message' => 'Course updated.']);