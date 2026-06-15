<?php
/**
 * EduTrack — Admin: Create / Update / Toggle autoreply template
 *
 * Method: POST
 * Body (create/edit): { id?: int, title, description, reply_body }
 * Body (toggle):      { id: int, is_active: 0|1 }
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
$id   = isset($body['id']) && $body['id'] !== null ? (int)$body['id'] : null;

// ── Toggle-only update (is_active) ───────────────────────────────────────────
if ($id && array_key_exists('is_active', $body) && !isset($body['title'])) {
    $active = (int)(bool)$body['is_active'];
    DB::execute(
        "UPDATE autoreply_templates SET is_active = ? WHERE id = ?",
        [$active, $id]
    );
    echo json_encode(['success' => true]);
    exit;
}

// ── Create / full update ──────────────────────────────────────────────────────
$title       = trim(substr($body['title']       ?? '', 0, 150));
$description = trim(substr($body['description'] ?? '', 0, 300));
$replyBody   = trim(substr($body['reply_body']  ?? '', 0, 2000));

if (empty($title) || empty($description) || empty($replyBody)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit;
}

if ($id) {
    $existing = DB::row("SELECT id FROM autoreply_templates WHERE id = ?", [$id]);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Template not found.']);
        exit;
    }
    DB::execute(
        "UPDATE autoreply_templates
         SET title = ?, description = ?, reply_body = ?
         WHERE id = ?",
        [$title, $description, $replyBody, $id]
    );
    Auth::audit('autoreply_updated', 'autoreply_templates', $id, []);
} else {
    $newId = DB::insert(
        "INSERT INTO autoreply_templates (title, description, reply_body, created_by)
         VALUES (?, ?, ?, ?)",
        [$title, $description, $replyBody, Auth::id()]
    );
    Auth::audit('autoreply_created', 'autoreply_templates', $newId, []);
}

echo json_encode(['success' => true]);
