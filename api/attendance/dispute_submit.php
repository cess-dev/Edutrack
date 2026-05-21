<?php
/**
 * EduTrack — Submit Attendance Dispute
 *
 * Called when a student submits a dispute for an absent session.
 *
 * Method:  POST
 * URL:     /api/attendance/dispute_submit.php
 * Access:  Student only
 *
 * Request body (JSON):
 *   session_id: int
 *   reason:     string
 *
 * Success response (200):
 *   { "success": true, "message": string }
 */

define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/AttendanceModel.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');
Auth::requireRole('student', true);
Auth::verifyCsrf(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$sessionId = (int)($body['session_id'] ?? 0);
$reason    = trim($body['reason'] ?? '');

if ($sessionId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid session_id is required.']);
    exit;
}

if (strlen($reason) < 10) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please provide a more detailed reason (at least 10 characters).']);
    exit;
}

$result = AttendanceModel::submitDispute(Auth::id(), $sessionId, $reason);

if (!$result['success']) {
    http_response_code(400);
    echo json_encode($result);
    exit;
}

Auth::audit('dispute_submitted', 'disputes', $sessionId, [
    'student_id' => Auth::id(),
]);

echo json_encode($result);