<?php
/**
 * EduTrack — Parent: Send Message to School
 *
 * Method:  POST
 * Access:  Parent only
 * Body:    { subject, body, urgency }
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/services/MessageRankingService.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');
Auth::requireRole('parent', true);
Auth::verifyCsrf(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$subject = trim(substr($body['subject'] ?? '', 0, 200));
$message = trim(substr($body['body']    ?? '', 0, 5000));

if (empty($subject)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Subject cannot be empty.']);
    exit;
}

if (empty($message)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Message body cannot be empty.']);
    exit;
}

$messageId = DB::insert(
    "INSERT INTO parent_messages (parent_id, subject, body) VALUES (?, ?, ?)",
    [Auth::id(), $subject, $message]
);

Auth::audit('message_sent', 'parent_messages', $messageId, ['subject' => $subject]);

// Rank and auto-reply immediately — the parent waits while the AI processes.
// Any failure here is non-fatal; the message is already saved.
try {
    MessageRankingService::rankAndReply((int) $messageId);
} catch (Throwable $e) {
    error_log('[AutoReply] Failed for message ' . $messageId . ': ' . $e->getMessage());
}

echo json_encode(['success' => true, 'message' => 'Message sent successfully.']);
