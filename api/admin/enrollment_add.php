<?php
/**
 * EduTrack — Admin: Enroll Student in Course
 *
 * Creates a master course enrollment (student_course_enrollments) and
 * derives unit-level enrollment rows for every active unit matching
 * the course / year_of_study / semester combination.
 *
 * Method:  POST
 * URL:     /api/admin/enrollment_add.php
 * Access:  Admin only
 *
 * Request body (JSON):
 *   student_id, course_id, year_of_study, academic_year, semester
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
$yearOfStudy  = (int)($body['year_of_study'] ?? 0);
$academicYear = trim($body['academic_year']  ?? '');
$semester     = (int)($body['semester']      ?? 0);

if ($studentId <= 0 || $courseId <= 0 || $yearOfStudy <= 0
    || empty($academicYear) || !in_array($semester, [1, 2], true)
) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'student_id, course_id, year_of_study, academic_year and semester (1 or 2) are all required.',
    ]);
    exit;
}

$result = UserModel::enrollStudentInCourse(
    $studentId,
    $courseId,
    $yearOfStudy,
    $academicYear,
    $semester,
    'manual',
    Auth::id()
);

if (!$result['success']) {
    http_response_code(400);
    echo json_encode($result);
    exit;
}

Auth::audit('student_enrolled_course', 'student_course_enrollments', $studentId, [
    'course_id'     => $courseId,
    'year_of_study' => $yearOfStudy,
    'academic_year' => $academicYear,
    'semester'      => $semester,
    'units_enrolled'=> $result['units_enrolled'],
]);

http_response_code(201);
echo json_encode($result);
