<?php
/**
 * EduTrack — Manual Attendance Mark
 *
 * Allows a lecturer to manually set a student's attendance status
 * for a specific session (override QR scan or auto-absent).
 *
 * Method:  POST
 * URL:     /api/attendance/manual_mark.php
 * Access:  Lecturer only
 *
 * Request body (JSON):
 *   session_id: int
 *   student_id: int
 *   status:     'present' | 'absent' | 'excused'
 *
 * Success response (200):
 *   { "success": true, "message": string }
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/AttendanceModel.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');
Auth::requireRole('lecturer', true);
Auth::verifyCsrf(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$sessionId = (int)($body['session_id'] ?? 0);
$studentId = (int)($body['student_id'] ?? 0);
$status    = trim($body['status']      ?? '');

if ($sessionId <= 0 || $studentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Valid session_id and student_id are required.']);
    exit;
}

if (!in_array($status, ['present', 'absent', 'excused'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => "Status must be 'present', 'absent', or 'excused'."]);
    exit;
}

// Verify the session belongs to this lecturer
$session = DB::row(
    "SELECT id FROM attendance_sessions
     WHERE id = ? AND lecturer_id = ?",
    [$sessionId, Auth::id()]
);

if (!$session) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Session not found or access denied.']);
    exit;
}

$ok = AttendanceModel::manualMark($sessionId, $studentId, $status, Auth::id());

if (!$ok) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Failed to update attendance record.']);
    exit;
}

Auth::audit('attendance_manual_mark', 'attendance_logs', $sessionId, [
    'student_id' => $studentId,
    'status'     => $status,
]);

echo json_encode([
    'success' => true,
    'message' => "Attendance updated: student marked {$status}.",
]);