<?php
defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/services/ExamInsightsService.php';

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

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$uploadId = (int)($body['upload_id'] ?? 0);

$upload = DB::row(
    "SELECT id, avg_score, analysis_status FROM exam_uploads WHERE id = ? AND lecturer_id = ?",
    [$uploadId, Auth::id()]
);

if (!$upload) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Upload not found or access denied.']);
    exit;
}

if ($upload['analysis_status'] === 'analyzing') {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'Analysis is already in progress.']);
    exit;
}

if ($upload['avg_score'] === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please save your notes (at least the average score) before analyzing.']);
    exit;
}

try {
    $result = ExamInsightsService::analyzeExam($uploadId);
} catch (Throwable $e) {
    error_log('[ExamInsights] Exception: ' . $e->getMessage());
    DB::execute("UPDATE exam_uploads SET analysis_status = 'failed' WHERE id = ?", [$uploadId]);
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Analysis failed: ' . $e->getMessage()]);
    exit;
}

if (!$result['success']) {
    http_response_code(503);
}

echo json_encode($result);
