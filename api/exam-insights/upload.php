<?php
defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/services/ExamInsightsService.php';

set_time_limit(120);
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

$unitId    = (int)($_POST['unit_id'] ?? 0);
$examTitle = trim($_POST['exam_title'] ?? '');

if ($unitId <= 0 || $examTitle === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'unit_id and exam_title are required.']);
    exit;
}

$unit = DB::row("SELECT id FROM units WHERE id = ? AND lecturer_id = ? AND is_active = 1", [$unitId, $user['id']]);
if (!$unit) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unit not found or not assigned to you.']);
    exit;
}

if (empty($_FILES['exam_pdf']) || $_FILES['exam_pdf']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No file uploaded or upload error.']);
    exit;
}

$file    = $_FILES['exam_pdf'];
$tmpPath = $file['tmp_name'];
$size    = $file['size'];

if ($size > MAX_EXAM_SIZE_BYTES) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File too large. Max: ' . round(MAX_EXAM_SIZE_BYTES / 1048576) . ' MB.']);
    exit;
}

$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($tmpPath);
if (!in_array($mimeType, ALLOWED_EXAM_MIMES, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Only PDF files are accepted.']);
    exit;
}

if (!is_dir(EXAM_UPLOADS_PATH)) {
    mkdir(EXAM_UPLOADS_PATH, 0755, true);
}

$origName = basename($file['name']);
$safeName = 'exam_' . $user['id'] . '_' . $unitId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.pdf';
$destPath = EXAM_UPLOADS_PATH . '/' . $safeName;

if (!move_uploaded_file($tmpPath, $destPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not save the uploaded file.']);
    exit;
}

$extractedText = ExamInsightsService::extractPdfText($destPath);

$academicYear = DB::row("SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year'")['setting_value'] ?? ACADEMIC_YEAR;
$semester     = (int)(DB::row("SELECT setting_value FROM system_settings WHERE setting_key = 'active_semester'")['setting_value'] ?? ACTIVE_SEMESTER);

$uploadId = (int)DB::insert(
    "INSERT INTO exam_uploads (unit_id, lecturer_id, academic_year, semester, exam_title, file_path, original_filename, extracted_text)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
    [$unitId, $user['id'], $academicYear, $semester, $examTitle, $destPath, $origName, $extractedText]
);

echo json_encode([
    'success'      => true,
    'upload_id'    => $uploadId,
    'text_preview' => $extractedText ? substr($extractedText, 0, 500) : null,
    'has_text'     => !empty($extractedText),
]);
