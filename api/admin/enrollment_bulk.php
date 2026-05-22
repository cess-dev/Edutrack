<?php
/**
 * EduTrack — Admin: Bulk Enroll Student in Course Units
 *
 * Enrolls a student in every active unit for a given course,
 * year of study, and semester. Skips units already enrolled in.
 *
 * Method:  POST
 * URL:     /api/admin/enrollment_bulk.php
 * Access:  Admin only
 *
 * Request body (JSON):
 *   student_id, course_id, year_of_study, academic_year, semester
 *
 * Success response (200):
 *   { "success": true, "enrolled": int, "skipped": int, "message": string }
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

$body         = json_decode(file_get_contents('php://input'), true) ?? [];
$studentId    = (int)($body['student_id']    ?? 0);
$courseId     = (int)($body['course_id']     ?? 0);
$year         = (int)($body['year_of_study'] ?? 0);
$academicYear = trim($body['academic_year']  ?? '');
$semester     = (int)($body['semester']      ?? 0);

if ($studentId <= 0 || $courseId <= 0 || $year <= 0 || empty($academicYear) || !in_array($semester,[1,2],true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit;
}

// Verify student exists
$student = DB::row(
    "SELECT id, full_name FROM users WHERE id = ? AND role = 'student'",
    [$studentId]
);
if (!$student) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Student not found.']);
    exit;
}

// Fetch all active units for this course / year / semester
$targetUnits = DB::rows(
    "SELECT id FROM units
     WHERE course_id = ? AND year_of_study = ? AND semester = ? AND is_active = 1",
    [$courseId, $year, $semester]
);

if (empty($targetUnits)) {
    echo json_encode([
        'success'  => false,
        'message'  => "No active units found for Year {$year}, Semester {$semester} in this course.",
    ]);
    exit;
}

$enrolled = 0;
$skipped  = 0;

foreach ($targetUnits as $unit) {
    $result = UserModel::enrollStudent(
        $studentId,
        $unit['id'],
        $academicYear,
        $semester
    );
    if ($result['success']) {
        $enrolled++;
    } else {
        $skipped++;
    }
}

Auth::audit('bulk_enrollment', 'enrollments', $studentId, [
    'course_id'    => $courseId,
    'year'         => $year,
    'semester'     => $semester,
    'enrolled'     => $enrolled,
    'skipped'      => $skipped,
]);

echo json_encode([
    'success'  => true,
    'enrolled' => $enrolled,
    'skipped'  => $skipped,
    'message'  => "{$enrolled} unit(s) enrolled" .
                  ($skipped ? ", {$skipped} already enrolled (skipped)." : '.'),
]);