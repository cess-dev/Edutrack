<?php
/**
 * EduTrack — Session Register
 *
 * Returns every enrolled student with their attendance status
 * for a specific session. Used by the live session page register table.
 *
 * Method:  GET
 * URL:     /api/attendance/register.php?session_id=123
 * Access:  Lecturer only
 *
 * Success response (200):
 *   {
 *     "success": true,
 *     "rows": [
 *       {
 *         "student_id", "reg_number", "full_name",
 *         "status", "method", "scanned_at"
 *       }
 *     ]
 *   }
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/AttendanceModel.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

Auth::requireRole('lecturer', true);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$sessionId = (int)($_GET['session_id'] ?? 0);

if ($sessionId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid session_id is required.']);
    exit;
}

// Verify the session belongs to this lecturer
$ownership = DB::row(
    "SELECT id FROM attendance_sessions
     WHERE id = ? AND lecturer_id = ?",
    [$sessionId, Auth::id()]
);

if (!$ownership) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Session not found or access denied.']);
    exit;
}

$rows = AttendanceModel::getSessionRegister($sessionId);

echo json_encode([
    'success' => true,
    'rows'    => $rows,
]);