<?php
/**
 * EduTrack — Lecturer AI Chat Endpoint
 *
 * Method:  POST
 * URL:     /api/ai/lecturer_chat.php
 * Access:  Lecturer only
 *
 * Request body (JSON):
 *   message:  string  (required, max 2000 chars)
 *   history:  array   (optional, prior turns [{role, text}])
 *
 * Success response (200):
 *   { "success": true, "reply": "..." }
 *
 * Error response (4xx/503):
 *   { "success": false, "message": "..." }
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/services/LecturerAIService.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');
Auth::requireRole('lecturer', true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$message = trim(substr($body['message'] ?? '', 0, 2000));

if ($message === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Message cannot be empty.']);
    exit;
}

// Sanitise history — keep only role + text, cap each entry
$rawHistory = is_array($body['history'] ?? null) ? $body['history'] : [];
$history    = [];
foreach ($rawHistory as $turn) {
    $role = $turn['role'] ?? '';
    $text = trim(substr($turn['text'] ?? '', 0, 4000));
    if (in_array($role, ['user', 'model'], true) && $text !== '') {
        $history[] = ['role' => $role, 'text' => $text];
    }
}

$lecturerId = Auth::id();
$result     = LecturerAIService::chat($lecturerId, $message, $history);

if (!$result['success']) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => $result['error']]);
    exit;
}

echo json_encode(['success' => true, 'reply' => $result['reply']]);
