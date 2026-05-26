<?php
/**
 * EduTrack — Admin: Remove Course Enrollment
 *
 * Removes a student's course-level enrollment record AND all derived
 * unit enrollments for that course / academic_year / semester.
 * Attendance logs and marks are preserved — only the enrollment link is removed.
 *
 * Method:  POST
 * URL:     /api/admin/enrollment_remove.php
 * Access:  Admin only
 *
 * Request body (JSON):
 *   sce_id        int   — student_course_enrollments.id
 *   student_id    int
 *   course_id     int
 *   academic_year string
 *   semester      int
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
$sceId        = (int)($body['sce_id']        ?? 0);
$studentId    = (int)($body['student_id']    ?? 0);
$courseId     = (int)($body['course_id']     ?? 0);
$academicYear = trim($body['academic_year']  ?? '');
$semester     = (int)($body['semester']      ?? 0);

if ($studentId <= 0 || $courseId <= 0 || empty($academicYear) || !in_array($semester, [1, 2], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'student_id, course_id, academic_year and semester are required.']);
    exit;
}

$result = UserModel::unenrollStudentFromCourse($studentId, $courseId, $academicYear, $semester);

if (!$result['success']) {
    http_response_code(404);
    echo json_encode($result);
    exit;
}

Auth::audit('student_unenrolled_course', 'student_course_enrollments', $studentId, [
    'sce_id'        => $sceId,
    'course_id'     => $courseId,
    'academic_year' => $academicYear,
    'semester'      => $semester,
    'units_removed' => $result['units_removed'],
]);

echo json_encode($result);
