<?php
/**
 * EduTrack — Live Attendance Feed Endpoint
 *
 * Polled by the lecturer's dashboard JavaScript every N seconds
 * (configured by LIVE_FEED_INTERVAL_SECONDS in config.php).
 * Returns the current list of students who have scanned for an
 * active session, plus session status information.
 *
 * Method:  GET
 * URL:     /api/attendance/live.php?session_id=123
 * Access:  Lecturer only
 *
 * Query parameters:
 *   session_id  int  required
 *
 * Success response (200):
 *   {
 *     "success":        true,
 *     "session": {
 *       "id":           int,
 *       "unit_code":    string,
 *       "unit_name":    string,
 *       "expires_at":   string,
 *       "is_active":    bool,
 *       "seconds_left": int     seconds until expiry (0 if expired/closed)
 *     },
 *     "scans":          array,  list of present students
 *     "scan_count":     int,
 *     "total_enrolled": int,
 *     "server_time":    string
 *   }
 *
 * Error responses:
 *   400 — missing session_id
 *   403 — session not owned by this lecturer
 *   405 — wrong HTTP method
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/helpers/QRHelper.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

Auth::requireRole('lecturer', true);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

$sessionId = (int) ($_GET['session_id'] ?? 0);

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

// ── Fetch live data ───────────────────────────────────────────────────────────
$data    = QRHelper::getLiveData($sessionId);
$session = $data['session'];

// Compute seconds remaining until QR expires
$secondsLeft = 0;
if ($session && $session['is_active']) {
    $secondsLeft = max(0, strtotime($session['expires_at']) - time());
}

echo json_encode([
    'success' => true,
    'session' => [
        'id'           => (int) ($session['id'] ?? 0),
        'unit_code'    => $session['unit_code']  ?? '',
        'unit_name'    => $session['unit_name']  ?? '',
        'expires_at'   => $session['expires_at'] ?? '',
        'is_active'    => (bool) ($session['is_active'] ?? false),
        'seconds_left' => $secondsLeft,
    ],
    'scans'         => $data['scans'],
    'scan_count'    => $data['scan_count'],
    'total_enrolled'=> $data['total_enrolled'],
    'server_time'   => $data['server_time'],
]);