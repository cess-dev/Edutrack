<?php
/**
 * EduTrack — Close Attendance Session Endpoint
 *
 * Called when the lecturer clicks "End Session" or when the frontend
 * timer reaches zero and auto-closes the session.
 *
 * Closing a session:
 *  1. Sets is_active = 0 and records closed_at timestamp
 *  2. Auto-marks all enrolled students who did NOT scan as absent
 *
 * Method:  POST
 * URL:     /api/attendance/session_close.php
 * Access:  Lecturer only
 *
 * Request body (JSON or form-encoded):
 *   session_id  int  required
 *
 * Success response (200):
 *   {
 *     "success":       true,
 *     "message":       string,
 *     "absent_marked": int    number of students auto-marked absent
 *   }
 *
 * Error responses:
 *   400 — missing session_id
 *   403 — session not found or not owned by this lecturer
 *   405 — wrong HTTP method
 */

define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/helpers/QRHelper.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');

Auth::requireRole('lecturer', true);
Auth::verifyCsrf(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// ── Parse request ─────────────────────────────────────────────────────────────
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (str_contains($contentType, 'application/json')) {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $sessionId = (int) ($body['session_id'] ?? 0);
} else {
    $sessionId = (int) ($_POST['session_id'] ?? 0);
}

if ($sessionId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid session_id is required.']);
    exit;
}

// ── Close session via QRHelper ────────────────────────────────────────────────
try {
    $result = QRHelper::closeSession($sessionId, Auth::id());
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to close session. Please try again.']);
    exit;
}

if (!$result['closed']) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Session not found, already closed, or you do not have permission to close it.',
    ]);
    exit;
}

// ── Audit log ─────────────────────────────────────────────────────────────────
Auth::audit('session_closed', 'attendance_sessions', $sessionId, [
    'absent_marked' => $result['absent_marked'],
]);

// ── Success response ──────────────────────────────────────────────────────────
echo json_encode([
    'success'       => true,
    'message'       => 'Session closed. ' . $result['absent_marked'] . ' student(s) auto-marked absent.',
    'absent_marked' => $result['absent_marked'],
]);