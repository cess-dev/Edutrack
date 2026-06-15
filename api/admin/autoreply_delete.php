<?php
/**
 * EduTrack — Admin: Delete autoreply template
 * Method: POST  Body: { id: int }
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
    echo json_encode(['success' => false, 'message' => 'Invalid id.']);
    exit;
}

DB::execute("DELETE FROM autoreply_templates WHERE id = ?", [$id]);
Auth::audit('autoreply_deleted', 'autoreply_templates', $id, []);

echo json_encode(['success' => true]);
