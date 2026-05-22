<?php
/**
 * EduTrack — Create Attendance Session Endpoint
 *
 * Called by the lecturer when they click "Start Attendance" for a class.
 * Creates a signed QR session in the database and returns the QR payload
 * string that the frontend encodes into a QR image using QRCode.js.
 *
 * Method:  POST
 * URL:     /api/attendance/session_create.php
 * Access:  Lecturer only
 *
 * Request body (JSON or form-encoded):
 *   unit_id        int     required — unit the session is for
 *   note           string  optional — session note (e.g. "Week 4 lecture")
 *
 * Success response (201):
 *   {
 *     "success":        true,
 *     "session_id":     int,
 *     "qr_payload":     string,   JSON string to encode into QR image
 *     "expires_at":     string,   datetime — when the QR becomes invalid
 *     "window_minutes": int,      how long the QR is valid
 *     "unit": {
 *       "id":   int,
 *       "code": string,
 *       "name": string
 *     }
 *   }
 *
 * Error responses:
 *   400 — missing/invalid unit_id
 *   403 — unit does not belong to this lecturer
 *   405 — wrong HTTP method
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/helpers/QRHelper.php';

// ── Bootstrap ─────────────────────────────────────────────────────────────────
Auth::startSession();
header('Content-Type: application/json; charset=utf-8');

// ── Auth guard ────────────────────────────────────────────────────────────────
Auth::requireRole('lecturer', true);
Auth::verifyCsrf(true);

// ── Method guard ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// ── Parse request ─────────────────────────────────────────────────────────────
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (str_contains($contentType, 'application/json')) {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $unitId = (int) ($body['unit_id'] ?? 0);
    $note   = trim($body['note'] ?? '');
} else {
    $unitId = (int) ($_POST['unit_id'] ?? 0);
    $note   = trim($_POST['note'] ?? '');
}

// ── Validation ────────────────────────────────────────────────────────────────
if ($unitId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid unit_id is required.']);
    exit;
}

$lecturerId = Auth::id();

// Verify the unit exists and belongs to this lecturer
$unit = DB::row(
    "SELECT id, code, name, course_id
     FROM units
     WHERE id = ? AND lecturer_id = ? AND is_active = 1",
    [$unitId, $lecturerId]
);

if (!$unit) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unit not found or you are not assigned to teach this unit.',
    ]);
    exit;
}

// ── Read active academic context from system_settings ─────────────────────────
$academicYear = DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year'"
)['setting_value'] ?? ACADEMIC_YEAR;

$semester = (int) (DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'active_semester'"
)['setting_value'] ?? ACTIVE_SEMESTER);

// ── Create session via QRHelper ───────────────────────────────────────────────
try {
    $session = QRHelper::createSession(
        unitId:       $unitId,
        lecturerId:   $lecturerId,
        academicYear: $academicYear,
        semester:     $semester,
        note:         $note ?: null
    );
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to create session. Please try again.',
    ]);
    exit;
}

// ── Audit log ─────────────────────────────────────────────────────────────────
Auth::audit('session_created', 'attendance_sessions', $session['session_id'], [
    'unit_id'      => $unitId,
    'unit_code'    => $unit['code'],
    'expires_at'   => $session['expires_at'],
]);

// ── Success response ──────────────────────────────────────────────────────────
http_response_code(201);
echo json_encode([
    'success'        => true,
    'session_id'     => $session['session_id'],
    'qr_payload'     => $session['payload'],
    'expires_at'     => $session['expires_at'],
    'window_minutes' => $session['window_minutes'],
    'unit'           => [
        'id'   => (int) $unit['id'],
        'code' => $unit['code'],
        'name' => $unit['name'],
    ],
]);