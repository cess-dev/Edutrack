<?php
/**
 * EduTrack — Admin: Create Course
 *
 * Method:  POST
 * URL:     /api/admin/course_create.php
 * Access:  Admin only
 *
 * Request body (JSON):
 *   code:           string  (e.g. "BCS")
 *   name:           string
 *   department:     string  (optional)
 *   duration_years: int     (default 4)
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
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

$body  = json_decode(file_get_contents('php://input'), true) ?? [];
$code  = strtoupper(trim($body['code']  ?? ''));
$name  = trim($body['name']  ?? '');
$dept  = trim($body['department']     ?? '') ?: null;
$years = max(1, min(6, (int)($body['duration_years'] ?? 4)));

if (empty($code) || empty($name)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Course code and name are required.']);
    exit;
}

// Check code uniqueness
$exists = DB::row("SELECT id FROM courses WHERE code = ?", [$code]);
if ($exists) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => "Course code '{$code}' already exists."]);
    exit;
}

$id = DB::insert(
    "INSERT INTO courses (code, name, department, duration_years)
     VALUES (?, ?, ?, ?)",
    [$code, $name, $dept, $years]
);

Auth::audit('course_created', 'courses', (int)$id, ['code' => $code]);

http_response_code(201);
echo json_encode([
    'success' => true,
    'id'      => (int)$id,
    'message' => "Course '{$code}' created successfully.",
]);