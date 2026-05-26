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

// ── Auto-enroll students already course-enrolled for this combination ─────────
// Any student who has a student_course_enrollments record for this course,
// year_of_study, and semester should automatically get a unit enrollment row.
// We use the active academic_year from system settings.
$activeAcademicYear = DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year'"
)['setting_value'] ?? '';

$autoEnrolled = 0;
if ($activeAcademicYear !== '') {
    $courseStudents = DB::rows(
        "SELECT student_id, academic_year
         FROM student_course_enrollments
         WHERE course_id    = ?
           AND year_of_study = ?
           AND semester      = ?
           AND academic_year = ?",
        [$courseId, $year, $semester, $activeAcademicYear]
    );

    foreach ($courseStudents as $cs) {
        $dup = DB::row(
            "SELECT id FROM enrollments
             WHERE student_id = ? AND unit_id = ? AND academic_year = ? AND semester = ?",
            [$cs['student_id'], (int)$id, $cs['academic_year'], $semester]
        );
        if (!$dup) {
            DB::insert(
                "INSERT INTO enrollments (student_id, unit_id, academic_year, semester)
                 VALUES (?, ?, ?, ?)",
                [$cs['student_id'], (int)$id, $cs['academic_year'], $semester]
            );
            $autoEnrolled++;
        }
    }
}

Auth::audit('unit_created', 'units', (int)$id, [
    'course_id'     => $courseId,
    'code'          => $code,
    'auto_enrolled' => $autoEnrolled,
]);

$message = "Unit '{$code}' added successfully.";
if ($autoEnrolled > 0) {
    $message .= " {$autoEnrolled} existing student(s) auto-enrolled.";
}

http_response_code(201);
echo json_encode([
    'success'       => true,
    'id'            => (int)$id,
    'auto_enrolled' => $autoEnrolled,
    'message'       => $message,
]);