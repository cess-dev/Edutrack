<?php
/**
 * EduTrack — Parent AI Chat Endpoint
 *
 * POST /api/ai/parent_chat.php
 * Parent-only. Handles conversational responses AND real actions
 * (absence reports, incident escalations) via function calling.
 *
 * Body (JSON):
 *   { "message": "...", "history": [{"role":"user"|"model","text":"..."}] }
 *
 * 200: { "success": true, "reply": "...", "actions"?: [...] }
 * 4xx: { "success": false, "message": "..." }
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/services/ParentAIService.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

Auth::requireRole('parent', true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!defined('AI_ENABLED') || !AI_ENABLED) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'AI assistant is not enabled.']);
    exit;
}

// ── Parse ─────────────────────────────────────────────────────────────────────
$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$message = trim($body['message'] ?? '');
$history = is_array($body['history'] ?? null) ? $body['history'] : [];

if (empty($message)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Message cannot be empty.']);
    exit;
}

if (strlen($message) > 2000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Message too long (max 2000 characters).']);
    exit;
}

// Sanitise history
$history = array_values(array_filter(
    array_map(fn($t) => [
        'role' => in_array($t['role'] ?? '', ['user', 'model'], true) ? $t['role'] : 'user',
        'text' => substr(trim($t['text'] ?? ''), 0, 4000),
    ], $history),
    fn($t) => $t['text'] !== ''
));

// ── Call AI ───────────────────────────────────────────────────────────────────
$parentId = Auth::id();
$result   = ParentAIService::chat($parentId, $message, $history);

if (!$result['success']) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => $result['error'] ?? 'AI request failed.']);
    exit;
}

echo json_encode([
    'success' => true,
    'reply'   => $result['reply'],
    'actions' => $result['actions'] ?? [],
]);
