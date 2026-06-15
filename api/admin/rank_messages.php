<?php
/**
 * EduTrack — Admin: AI-rank unranked parent messages
 *
 * Method:  POST
 * Access:  Admin only
 *
 * Response:
 *   { "success": true, "ranked": { "42": 3, "7": 1 }, "count": 2 }
 *   { "success": true, "ranked": {}, "count": 0 }  — nothing to rank
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/services/MessageRankingService.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');
Auth::requireRole('admin', true);
Auth::verifyCsrf(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$ranked = MessageRankingService::rankPending();

echo json_encode([
    'success' => true,
    'ranked'  => $ranked,
    'count'   => count($ranked),
]);
