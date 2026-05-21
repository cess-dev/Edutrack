<?php
/**
 * EduTrack — QR Scan Submission Endpoint
 *
 * Called by the student's browser the moment jsQR decodes a valid QR image.
 * Validates the token and records the student as present.
 *
 * Method:  POST
 * URL:     /api/attendance/scan.php
 * Access:  Student only
 *
 * Request body (JSON — always sent via fetch() from the scanner page):
 *   qr_data  string  required — raw string decoded by jsQR from the camera
 *
 * Success response (200):
 *   {
 *     "success":    true,
 *     "message":    "Attendance recorded. You are marked present.",
 *     "unit_name":  string,
 *     "session_id": int,
 *     "scanned_at": string   server datetime
 *   }
 *
 * Error responses:
 *   400 — missing qr_data
 *   401 — not authenticated
 *   409 — already scanned / other validation failure (with error_code)
 *   405 — wrong HTTP method
 */

define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/helpers/QRHelper.php';
require_once __DIR__ . '/../../backend/models/AttendanceModel.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');

// ── Auth guard ────────────────────────────────────────────────────────────────
Auth::requireRole('student', true);

// ── Method guard ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// Scan submissions are always JSON from the fetch() in qr-scanner.js
$body  = json_decode(file_get_contents('php://input'), true) ?? [];
$qrData = trim($body['qr_data'] ?? '');

if (empty($qrData)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No QR data received.']);
    exit;
}

$studentId = Auth::id();

// ── Validate scan via QRHelper ────────────────────────────────────────────────
$validation = QRHelper::validateScan($qrData, $studentId);

if (!$validation['valid']) {
    // Map specific error codes to HTTP status codes
    $statusMap = [
        'INVALID_PAYLOAD'  => 400,
        'VERSION_MISMATCH' => 400,
        'SESSION_NOT_FOUND'=> 404,
        'SESSION_CLOSED'   => 410,   // Gone — session is permanently closed
        'TOKEN_EXPIRED'    => 410,
        'HMAC_INVALID'     => 400,
        'NOT_ENROLLED'     => 403,
        'ALREADY_SCANNED'  => 409,   // Conflict — already recorded
    ];

    $status = $statusMap[$validation['error_code']] ?? 400;
    http_response_code($status);
    echo json_encode([
        'success'    => false,
        'message'    => $validation['error'],
        'error_code' => $validation['error_code'],
    ]);
    exit;
}

// ── Record attendance ─────────────────────────────────────────────────────────
$sessionId = $validation['session_id'];
$unitId    = $validation['unit_id'];

$recorded = AttendanceModel::recordScan($sessionId, $studentId);

if (!$recorded) {
    // INSERT IGNORE returned 0 rows — race condition duplicate
    http_response_code(409);
    echo json_encode([
        'success'    => false,
        'message'    => 'Your attendance has already been recorded for this session.',
        'error_code' => 'ALREADY_SCANNED',
    ]);
    exit;
}

// ── Fetch unit name for the success toast ─────────────────────────────────────
$unit = DB::row(
    "SELECT code, name FROM units WHERE id = ?",
    [$unitId]
);

$unitLabel = $unit ? "{$unit['code']} — {$unit['name']}" : 'Unknown Unit';
$scannedAt = date('Y-m-d H:i:s');

// ── Audit log ─────────────────────────────────────────────────────────────────
Auth::audit('attendance_scanned', 'attendance_logs', $sessionId, [
    'unit_id'   => $unitId,
    'student_id'=> $studentId,
]);

// ── Success response ──────────────────────────────────────────────────────────
echo json_encode([
    'success'    => true,
    'message'    => 'Attendance recorded. You are marked present.',
    'unit_name'  => $unitLabel,
    'session_id' => $sessionId,
    'scanned_at' => $scannedAt,
]);