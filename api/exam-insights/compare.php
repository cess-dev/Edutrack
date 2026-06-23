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

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$unitId  = (int)($body['unit_id'] ?? 0);
$idA     = (int)($body['upload_id_a'] ?? 0);
$idB     = (int)($body['upload_id_b'] ?? 0);

if ($unitId <= 0 || $idA <= 0 || $idB <= 0 || $idA === $idB) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'unit_id and two different upload IDs are required.']);
    exit;
}

$unit = DB::row("SELECT id FROM units WHERE id = ? AND lecturer_id = ? AND is_active = 1", [$unitId, Auth::id()]);
if (!$unit) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unit not found or access denied.']);
    exit;
}

try {
    $result = ExamInsightsService::compareExams($unitId, $idA, $idB);
} catch (Throwable $e) {
    error_log('[ExamInsights] Compare exception: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Comparison failed: ' . $e->getMessage()]);
    exit;
}

echo json_encode($result);
