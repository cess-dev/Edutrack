<?php
/**
 * EduTrack — Timetable Upload & Vision Extraction Endpoint
 *
 * Accepts a timetable file (image, PDF, or CSV), saves it, runs AI extraction,
 * and returns the extracted slots for the lecturer to review before confirming.
 *
 * Method:  POST  multipart/form-data
 * Access:  Lecturer only
 * Fields:  timetable (file)
 *
 * Success (200):
 *   { "success": true, "timetable_id": int, "slots": [...] }
 *
 * Error (400/403/500):
 *   { "success": false, "error": "..." }
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/services/TimetableVisionService.php';

set_time_limit(300);
ini_set('display_errors', 0);

Auth::startSession();
Auth::requireRole('lecturer');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$user = Auth::user();

// ── Validate upload ───────────────────────────────────────────────────────────

if (empty($_FILES['timetable']) || $_FILES['timetable']['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server temporary directory missing.',
        UPLOAD_ERR_CANT_WRITE => 'Server could not write the file.',
    ];
    $code = $_FILES['timetable']['error'] ?? UPLOAD_ERR_NO_FILE;
    $msg  = $uploadErrors[$code] ?? 'Upload failed.';
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

$file     = $_FILES['timetable'];
$tmpPath  = $file['tmp_name'];
$origName = basename($file['name']);
$size     = $file['size'];

if ($size > MAX_TIMETABLE_SIZE_BYTES) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File is too large. Maximum allowed: ' . round(MAX_TIMETABLE_SIZE_BYTES / 1048576, 0) . ' MB.']);
    exit;
}

// Detect MIME from actual file content (not browser-supplied)
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($tmpPath);

$allowedMimes = array_merge(
    ALLOWED_TIMETABLE_IMAGE_MIMES,
    ['application/pdf', 'text/csv', 'text/plain', 'application/vnd.ms-excel']
);

if (!in_array($mimeType, $allowedMimes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => "Unsupported file type ({$mimeType}). Please upload a JPG, PNG, PDF, or CSV."]);
    exit;
}

// ── Save file ─────────────────────────────────────────────────────────────────

if (!is_dir(TIMETABLE_UPLOADS_PATH)) {
    mkdir(TIMETABLE_UPLOADS_PATH, 0755, true);
}

$ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
$safeName = 'tt_' . $user['id'] . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$destPath = TIMETABLE_UPLOADS_PATH . '/' . $safeName;

if (!move_uploaded_file($tmpPath, $destPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not save the uploaded file.']);
    exit;
}

// ── Determine file type label ─────────────────────────────────────────────────

$fileTypeLabel = 'image';
if ($mimeType === 'application/pdf') {
    $fileTypeLabel = 'pdf';
} elseif (in_array($mimeType, ['text/csv', 'text/plain', 'application/vnd.ms-excel'], true)) {
    $fileTypeLabel = 'csv';
}

// ── Insert timetable record ───────────────────────────────────────────────────

$academicYear = DB::row("SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year'")['setting_value'] ?? ACADEMIC_YEAR;
$semester     = (int)(DB::row("SELECT setting_value FROM system_settings WHERE setting_key = 'active_semester'")['setting_value'] ?? ACTIVE_SEMESTER);

$timetableId = (int)DB::insert(
    "INSERT INTO timetables (lecturer_id, academic_year, semester, file_path, original_filename, file_type, extraction_status)
     VALUES (?, ?, ?, ?, ?, ?, 'pending')",
    [$user['id'], $academicYear, $semester, $destPath, $origName, $fileTypeLabel]
);

// ── Run extraction ────────────────────────────────────────────────────────────

$result = TimetableVisionService::extract($destPath, $mimeType);

if (!$result['success']) {
    // Mark as failed but keep the record so the file isn't orphaned
    DB::execute("UPDATE timetables SET extraction_status = 'failed' WHERE id = ?", [$timetableId]);
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $result['error']]);
    exit;
}

$slots = $result['slots'];

// ── Merge consecutive slots for the same unit/day/room ───────────────────────
// e.g. BIT101 8:00-9:00 + BIT101 9:00-10:00 → BIT101 8:00-10:00

usort($slots, fn($a, $b) =>
    $a['day_of_week'] <=> $b['day_of_week']
    ?: strcmp($a['unit_code'], $b['unit_code'])
    ?: strcmp($a['start_time'], $b['start_time'])
);

$merged = [];
foreach ($slots as $slot) {
    $last = end($merged);
    if ($last !== false
        && $last['day_of_week'] === $slot['day_of_week']
        && $last['unit_code']   === $slot['unit_code']
        && $last['room']        === $slot['room']
        && $last['end_time']    === $slot['start_time']
    ) {
        $merged[array_key_last($merged)]['end_time'] = $slot['end_time'];
    } else {
        $merged[] = $slot;
    }
}
$slots = $merged;

// Re-sort by day then start time for the review table
usort($slots, fn($a, $b) =>
    $a['day_of_week'] <=> $b['day_of_week']
    ?: strcmp($a['start_time'], $b['start_time'])
);

// ── Auto-fill unit names from the system (own units first, then all) ─────────

$allUnits = DB::rows(
    "SELECT code, name, lecturer_id FROM units WHERE is_active = 1 ORDER BY lecturer_id = ? DESC",
    [$user['id']]
);
$unitNameMap = [];
foreach ($allUnits as $u) {
    $key = strtoupper(trim($u['code']));
    if (!isset($unitNameMap[$key])) {
        $unitNameMap[$key] = $u['name'];
    }
}

foreach ($slots as &$slot) {
    $code = strtoupper($slot['unit_code']);
    if (empty($slot['unit_name']) && isset($unitNameMap[$code])) {
        $slot['unit_name'] = $unitNameMap[$code];
    }
}
unset($slot);

// ── Persist raw extraction ────────────────────────────────────────────────────

DB::execute(
    "UPDATE timetables SET extraction_status = 'extracted', raw_extraction = ? WHERE id = ?",
    [json_encode($slots), $timetableId]
);

echo json_encode([
    'success'      => true,
    'timetable_id' => $timetableId,
    'slots'        => $slots,
    'slot_count'   => count($slots),
]);
