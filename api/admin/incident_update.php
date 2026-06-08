<?php
/**
 * EduTrack — Admin: Update Parent AI Incident Status
 *
 * Method:  POST
 * Access:  Admin only
 * Body:    { id: int, status: "reviewed"|"closed" }
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');
Auth::requireRole('admin', true);
Auth::verifyCsrf(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$id     = (int)($body['id'] ?? 0);
$status = $body['status'] ?? '';

if ($id < 1 || !in_array($status, ['reviewed', 'closed'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid id or status.']);
    exit;
}

$incident = DB::row("SELECT id, status FROM parent_ai_incidents WHERE id = ?", [$id]);
if (!$incident) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Incident not found.']);
    exit;
}

DB::execute(
    "UPDATE parent_ai_incidents
     SET status = ?, reviewed_by = ?, reviewed_at = NOW()
     WHERE id = ?",
    [$status, Auth::id(), $id]
);

Auth::audit('incident_updated', 'parent_ai_incidents', $id, ['status' => $status]);

echo json_encode(['success' => true, 'message' => "Incident marked as {$status}."]);
