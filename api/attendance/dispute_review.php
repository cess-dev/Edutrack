<?php
/**
 * EduTrack — Review Attendance Dispute
 *
 * Called when a lecturer approves or rejects a student dispute.
 * On approval, attendance_logs row is updated to 'excused'.
 *
 * Method:  POST
 * URL:     /api/attendance/dispute_review.php
 * Access:  Lecturer only
 *
 * Request body (JSON):
 *   dispute_id: int
 *   decision:   'approved' | 'rejected'
 *   note:       string  (optional)
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
Auth::requireRole('lecturer', true);
Auth::verifyCsrf(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$disputeId = (int)($body['dispute_id'] ?? 0);
$decision  = trim($body['decision']    ?? '');
$note      = trim($body['note']        ?? '');

if ($disputeId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid dispute_id is required.']);
    exit;
}

if (!in_array($decision, ['approved', 'rejected'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => "Decision must be 'approved' or 'rejected'.'"]);
    exit;
}

$result = AttendanceModel::reviewDispute($disputeId, Auth::id(), $decision, $note);

if (!$result['success']) {
    http_response_code(400);
    echo json_encode($result);
    exit;
}

Auth::audit(
    "dispute_{$decision}",
    'disputes',
    $disputeId,
    ['reviewer_id' => Auth::id()]
);

echo json_encode($result);