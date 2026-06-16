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

DB::execute(
    "INSERT INTO timetables (lecturer_id, academic_year, semester, file_path, original_filename, file_type, extraction_status)
     VALUES (?, ?, ?, ?, ?, ?, 'pending')",
    [$user['id'], $academicYear, $semester, $destPath, $origName, $fileTypeLabel]
);
$timetableId = (int)DB::lastInsertId();

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
