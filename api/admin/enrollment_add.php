<?php
/**
 * EduTrack — Admin: Enroll Student in Unit
 *
 * Method:  POST
 * URL:     /api/admin/enrollment_add.php
 * Access:  Admin only
 *
 * Request body (JSON):
 *   student_id, unit_id, academic_year, semester
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
$unitId       = (int)($body['unit_id']       ?? 0);
$academicYear = trim($body['academic_year']  ?? '');
$semester     = (int)($body['semester']      ?? 0);

if ($studentId <= 0 || $unitId <= 0 || empty($academicYear) || !in_array($semester,[1,2],true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'student_id, unit_id, academic_year and semester (1 or 2) are required.']);
    exit;
}

$result = UserModel::enrollStudent($studentId, $unitId, $academicYear, $semester);

if (!$result['success']) {
    http_response_code(400);
    echo json_encode($result);
    exit;
}

Auth::audit('student_enrolled', 'enrollments', $studentId, [
    'unit_id'      => $unitId,
    'academic_year'=> $academicYear,
    'semester'     => $semester,
]);

http_response_code(201);
echo json_encode($result);