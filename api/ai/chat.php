<?php
/**
 * EduTrack — Student AI Chat Endpoint
 *
 * POST /api/ai/chat.php
 * Student-only. Forwards the message + history to the local LM Studio server.
 *
 * Body (JSON):
 *   { "message": "...", "history": [{"role":"user"|"model","text":"..."}] }
 *
 * 200: { "success": true,  "reply": "..." }
 * 4xx: { "success": false, "message": "..." }
 * 503: LM Studio not running or AI disabled
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/services/GeminiService.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

Auth::requireRole('student', true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!GeminiService::isEnabled()) {
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

// ── Context ───────────────────────────────────────────────────────────────────
$studentId    = Auth::id();
$academicYear = DB::row("SELECT setting_value FROM system_settings WHERE setting_key='academic_year'")['setting_value'] ?? ACADEMIC_YEAR;
$semester     = (int)(DB::row("SELECT setting_value FROM system_settings WHERE setting_key='active_semester'")['setting_value'] ?? ACTIVE_SEMESTER);

// ── Call LM Studio ────────────────────────────────────────────────────────────
$result = GeminiService::chat($studentId, $academicYear, $semester, $message, $history);

if (!$result['success']) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => $result['error'] ?? 'AI request failed.']);
    exit;
}

echo json_encode(['success' => true, 'reply' => $result['reply']]);
