<?php
/**
 * EduTrack — Marks Upload Endpoint
 *
 * Handles two upload modes from the lecturer dashboard:
 *   1. Single mark  — one student, one score, submitted via JSON
 *   2. Bulk CSV     — many students at once via multipart file upload
 *
 * Method:  POST
 * URL:     /api/marks/upload.php
 * Access:  Lecturer only
 *
 * ── Single mark (JSON body) ───────────────────────────────────────────────────
 * Request body:
 *   mode:           "single"
 *   assessment_id:  int     required
 *   student_id:     int     required
 *   score:          float   required
 *
 * Success response (200):
 *   { "success": true, "message": "Mark saved." }
 *
 * ── Bulk CSV upload (multipart/form-data) ────────────────────────────────────
 * Form fields:
 *   mode:           "bulk"
 *   assessment_id:  int     required
 *   csv:            file    required — CSV with columns: reg_number, score
 *
 * Success response (200):
 *   {
 *     "success": true,
 *     "message": "14 mark(s) saved, 2 row(s) skipped.",
 *     "saved":   int,
 *     "skipped": int,
 *     "errors":  [ { row, reg_number, reason } ]
 *   }
 *
 * Error responses:
 *   400 — missing/invalid fields
 *   403 — assessment not owned by this lecturer
 *   405 — wrong HTTP method
 */

define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/MarksModel.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');

Auth::requireRole('lecturer', true);
Auth::verifyCsrf(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// ── Detect upload mode ────────────────────────────────────────────────────────
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isBulk      = str_contains($contentType, 'multipart/form-data');

if ($isBulk) {
    $mode         = trim($_POST['mode'] ?? 'bulk');
    $assessmentId = (int) ($_POST['assessment_id'] ?? 0);
} else {
    $body         = json_decode(file_get_contents('php://input'), true) ?? [];
    $mode         = trim($body['mode'] ?? 'single');
    $assessmentId = (int) ($body['assessment_id'] ?? 0);
}

// ── Common validation ─────────────────────────────────────────────────────────
if ($assessmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid assessment_id is required.']);
    exit;
}

// Verify the assessment belongs to a unit taught by this lecturer
$assessment = DB::row(
    "SELECT a.id, a.unit_id, a.name, a.max_score, u.lecturer_id, u.code AS unit_code
     FROM assessments a
     JOIN units u ON u.id = a.unit_id
     WHERE a.id = ?",
    [$assessmentId]
);

if (!$assessment) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Assessment not found.']);
    exit;
}

if ((int) $assessment['lecturer_id'] !== Auth::id()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'You are not authorised to upload marks for this assessment.',
    ]);
    exit;
}

$lecturerId = Auth::id();

// ─────────────────────────────────────────────────────────────────────────────
// MODE: single
// ─────────────────────────────────────────────────────────────────────────────
if ($mode === 'single') {
    $studentId = (int) ($body['student_id'] ?? 0);
    $score     = $body['score'] ?? null;

    if ($studentId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A valid student_id is required.']);
        exit;
    }

    if ($score === null || !is_numeric($score)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A numeric score is required.']);
        exit;
    }

    $result = MarksModel::saveMark($studentId, $assessmentId, (float) $score, $lecturerId);

    if (!$result['success']) {
        http_response_code(400);
    }

    Auth::audit('mark_saved', 'marks', $assessmentId, [
        'student_id' => $studentId,
        'score'      => (float) $score,
        'unit_code'  => $assessment['unit_code'],
    ]);

    echo json_encode($result);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// MODE: bulk CSV
// ─────────────────────────────────────────────────────────────────────────────
if ($mode === 'bulk') {
    if (empty($_FILES['csv']) || $_FILES['csv']['error'] === UPLOAD_ERR_NO_FILE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No CSV file was uploaded.']);
        exit;
    }

    $result = MarksModel::bulkUploadFromCsv($_FILES['csv'], $assessmentId, $lecturerId);

    Auth::audit('marks_bulk_uploaded', 'marks', $assessmentId, [
        'saved'     => $result['saved'],
        'skipped'   => $result['skipped'],
        'unit_code' => $assessment['unit_code'],
    ]);

    if (!$result['success'] && $result['saved'] === 0) {
        http_response_code(400);
    }

    echo json_encode($result);
    exit;
}

// ── Unknown mode ──────────────────────────────────────────────────────────────
http_response_code(400);
echo json_encode([
    'success' => false,
    'message' => "Unknown mode '{$mode}'. Use 'single' or 'bulk'.",
]);