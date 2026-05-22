<?php
/**
 * EduTrack — Publish / Unpublish Assessment Endpoint
 *
 * Toggles the is_published flag on an assessment.
 * When published = 1, students and parents can see their marks.
 * When published = 0, marks are hidden (lecturer-only view).
 *
 * Method:  POST
 * URL:     /api/marks/publish.php
 * Access:  Lecturer only
 *
 * Request body (JSON):
 *   assessment_id  int  required
 *
 * Success response (200):
 *   {
 *     "success":   true,
 *     "published": bool,
 *     "message":   "Assessment published. Students can now see their marks."
 *   }
 *
 * Error responses:
 *   400 — missing assessment_id
 *   403 — not your assessment
 *   405 — wrong HTTP method
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/MarksModel.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');

Auth::requireRole('lecturer', true);
Auth::verifyCsrf(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

$body         = json_decode(file_get_contents('php://input'), true) ?? [];
$assessmentId = (int) ($body['assessment_id'] ?? 0);

if ($assessmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid assessment_id is required.']);
    exit;
}

$result = MarksModel::togglePublish($assessmentId, Auth::id());

if (!$result['success']) {
    http_response_code(403);
}

Auth::audit(
    $result['published'] ? 'assessment_published' : 'assessment_unpublished',
    'assessments',
    $assessmentId
);

echo json_encode($result);