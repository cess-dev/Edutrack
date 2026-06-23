<?php
defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

Auth::startSession();
Auth::requireRole('lecturer');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$uploadId  = (int)($body['upload_id'] ?? 0);
$avgScore  = isset($body['avg_score']) ? (float)$body['avg_score'] : null;
$failed    = trim($body['most_failed'] ?? '');
$obs       = trim($body['observations'] ?? '');

if ($uploadId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'upload_id is required.']);
    exit;
}

$upload = DB::row(
    "SELECT id FROM exam_uploads WHERE id = ? AND lecturer_id = ?",
    [$uploadId, Auth::id()]
);
if (!$upload) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Upload not found or access denied.']);
    exit;
}

DB::execute(
    "UPDATE exam_uploads SET avg_score = ?, most_failed = ?, observations = ? WHERE id = ?",
    [$avgScore, $failed ?: null, $obs ?: null, $uploadId]
);

echo json_encode(['success' => true]);
