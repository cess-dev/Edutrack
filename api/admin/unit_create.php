<?php
/**
 * EduTrack — Admin: Create Unit
 *
 * Method:  POST
 * URL:     /api/admin/unit_create.php
 * Access:  Admin only
 *
 * Request body (JSON):
 *   course_id, code, name, semester, year_of_study,
 *   credit_hours, lecturer_id (optional)
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

$body       = json_decode(file_get_contents('php://input'), true) ?? [];
$courseId   = (int)($body['course_id']    ?? 0);
$code       = strtoupper(trim($body['code'] ?? ''));
$name       = trim($body['name']           ?? '');
$semester   = (int)($body['semester']      ?? 1);
$year       = (int)($body['year_of_study'] ?? 1);
$credits    = (int)($body['credit_hours']  ?? 3);
$lecturerId = !empty($body['lecturer_id']) ? (int)$body['lecturer_id'] : null;

if ($courseId <= 0 || empty($code) || empty($name)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'course_id, code and name are required.']);
    exit;
}

if (!in_array($semester, [1, 2], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Semester must be 1 or 2.']);
    exit;
}

// Verify the course exists
$course = DB::row("SELECT id FROM courses WHERE id = ?", [$courseId]);
if (!$course) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Course not found.']);
    exit;
}

// Check unit code uniqueness
$exists = DB::row("SELECT id FROM units WHERE code = ?", [$code]);
if ($exists) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => "Unit code '{$code}' already exists."]);
    exit;
}

$id = DB::insert(
    "INSERT INTO units (course_id, code, name, semester, year_of_study, credit_hours, lecturer_id)
     VALUES (?, ?, ?, ?, ?, ?, ?)",
    [$courseId, $code, $name, $semester, $year, $credits, $lecturerId]
);

Auth::audit('unit_created', 'units', (int)$id, [
    'course_id' => $courseId,
    'code'      => $code,
]);

http_response_code(201);
echo json_encode([
    'success' => true,
    'id'      => (int)$id,
    'message' => "Unit '{$code}' added successfully.",
]);