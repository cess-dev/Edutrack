<?php
/**
 * EduTrack — Admin: Remove Enrollment
 *
 * Removes a student from a unit by enrollment ID.
 * Does NOT delete attendance logs or marks — historical data is preserved.
 *
 * Method:  POST
 * URL:     /api/admin/enrollment_remove.php
 * Access:  Admin only
 *
 * Request body (JSON):
 *   enrollment_id: int
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

$body         = json_decode(file_get_contents('php://input'), true) ?? [];
$enrollmentId = (int)($body['enrollment_id'] ?? 0);

if ($enrollmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid enrollment_id is required.']);
    exit;
}

// Fetch enrollment details before deleting for the audit log
$enrollment = DB::row(
    "SELECT e.id, e.student_id, e.unit_id, e.academic_year, e.semester,
            stu.full_name AS student_name, u.code AS unit_code
     FROM enrollments e
     JOIN users stu ON stu.id = e.student_id
     JOIN units u   ON u.id   = e.unit_id
     WHERE e.id = ?",
    [$enrollmentId]
);

if (!$enrollment) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Enrollment not found.']);
    exit;
}

$affected = DB::execute(
    "DELETE FROM enrollments WHERE id = ?",
    [$enrollmentId]
);

if (!$affected) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to remove enrollment.']);
    exit;
}

Auth::audit('student_unenrolled', 'enrollments', $enrollment['student_id'], [
    'unit_id'      => $enrollment['unit_id'],
    'unit_code'    => $enrollment['unit_code'],
    'academic_year'=> $enrollment['academic_year'],
    'semester'     => $enrollment['semester'],
]);

echo json_encode([
    'success' => true,
    'message' => "{$enrollment['student_name']} removed from {$enrollment['unit_code']}.",
]);