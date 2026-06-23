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

$body          = json_decode(file_get_contents('php://input'), true) ?? [];
$suggestionId  = (int)($body['suggestion_id'] ?? 0);
$action        = $body['action'] ?? '';
$followUp      = trim($body['follow_up_notes'] ?? '');

if ($suggestionId <= 0 || !in_array($action, ['execute', 'dismiss'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'suggestion_id and action (execute|dismiss) are required.']);
    exit;
}

$row = DB::row(
    "SELECT es.id FROM exam_suggestions es
     JOIN exam_insights ei ON ei.id = es.insight_id
     JOIN exam_uploads eu ON eu.id = ei.upload_id
     WHERE es.id = ? AND eu.lecturer_id = ?",
    [$suggestionId, Auth::id()]
);

if (!$row) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Suggestion not found or access denied.']);
    exit;
}

$status    = $action === 'execute' ? 'executed' : 'dismissed';
$execAt    = $action === 'execute' ? date('Y-m-d H:i:s') : null;

DB::execute(
    "UPDATE exam_suggestions SET status = ?, follow_up_notes = ?, executed_at = ? WHERE id = ?",
    [$status, $followUp ?: null, $execAt, $suggestionId]
);

echo json_encode(['success' => true, 'status' => $status]);
