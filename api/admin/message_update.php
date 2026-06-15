<?php
/**
 * EduTrack — Admin: Mark Parent Message as Read
 *
 * Method:  POST
 * Access:  Admin only
 * Body:    { id: int }
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

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$id   = (int)($body['id'] ?? 0);

if ($id < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid message id.']);
    exit;
}

$msg = DB::row("SELECT id, status FROM parent_messages WHERE id = ?", [$id]);
if (!$msg) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Message not found.']);
    exit;
}

if ($msg['status'] === 'unread') {
    DB::execute(
        "UPDATE parent_messages SET status = 'read', read_by = ?, read_at = NOW() WHERE id = ?",
        [Auth::id(), $id]
    );
    Auth::audit('message_read', 'parent_messages', $id, []);
}

echo json_encode(['success' => true]);
