<?php
/**
 * EduTrack — Admin: Close All Active Sessions
 *
 * Emergency endpoint that closes every open attendance session
 * and auto-marks absent students for each one.
 *
 * Method:  POST
 * URL:     /api/admin/close_all_sessions.php
 * Access:  Admin only
 *
 * Success response (200):
 *   {
 *     "success":       true,
 *     "sessions_closed": int,
 *     "absent_marked":   int,
 *     "message":         string
 *   }
 */

define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/helpers/QRHelper.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');
Auth::requireRole('admin', true);
Auth::verifyCsrf(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// Find all open sessions
$activeSessions = DB::rows(
    "SELECT id, lecturer_id
     FROM attendance_sessions
     WHERE is_active = 1"
);

if (empty($activeSessions)) {
    echo json_encode([
        'success'         => true,
        'sessions_closed' => 0,
        'absent_marked'   => 0,
        'message'         => 'No active sessions to close.',
    ]);
    exit;
}

$sessionsClosed = 0;
$totalAbsent    = 0;

foreach ($activeSessions as $s) {
    try {
        $result = QRHelper::closeSession((int)$s['id'], (int)$s['lecturer_id']);
        if ($result['closed']) {
            $sessionsClosed++;
            $totalAbsent += $result['absent_marked'];
        }
    } catch (Exception $e) {
        // Log but continue closing the rest
        error_log("Failed to close session {$s['id']}: " . $e->getMessage());
    }
}

Auth::audit('all_sessions_closed', 'attendance_sessions', null, [
    'sessions_closed' => $sessionsClosed,
    'absent_marked'   => $totalAbsent,
]);

echo json_encode([
    'success'         => true,
    'sessions_closed' => $sessionsClosed,
    'absent_marked'   => $totalAbsent,
    'message'         => "{$sessionsClosed} session(s) closed. "
                       . "{$totalAbsent} student(s) auto-marked absent.",
]);