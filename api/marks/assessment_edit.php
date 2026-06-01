<?php
/**
 * EduTrack — Edit Assessment
 *
 * Allows a lecturer to update an existing assessment's details.
 * Ownership is enforced: the assessment must belong to a unit
 * taught by the requesting lecturer.
 *
 * Method:  POST
 * URL:     /api/marks/assessment_edit.php
 * Access:  Lecturer only
 *
 * Request body (JSON):
 *   assessment_id:   int
 *   name:            string
 *   type:            'cat'|'assignment'|'practical'|'project'|'final_exam'
 *   max_score:       float
 *   weight_percent:  float
 *   assessment_date: string|null  (YYYY-MM-DD)
 *
 * Success response (200):
 *   { "success": true, "message": string }
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
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body         = json_decode(file_get_contents('php://input'), true) ?? [];
$assessmentId = (int) ($body['assessment_id'] ?? 0);

if ($assessmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid assessment_id is required.']);
    exit;
}

$validTypes = ['cat', 'assignment', 'practical', 'project', 'final_exam'];
$type       = trim($body['type'] ?? '');

if (!in_array($type, $validTypes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid assessment type.']);
    exit;
}

$date = $body['assessment_date'] ?? null;
if ($date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Date must be in YYYY-MM-DD format.']);
    exit;
}

$result = MarksModel::updateAssessment($assessmentId, Auth::id(), [
    'name'            => trim($body['name'] ?? ''),
    'type'            => $type,
    'max_score'       => (float) ($body['max_score']      ?? 0),
    'weight_percent'  => (float) ($body['weight_percent'] ?? 0),
    'assessment_date' => $date ?: null,
]);

if (!$result['success']) {
    http_response_code(400);
    echo json_encode($result);
    exit;
}

Auth::audit('assessment_updated', 'assessments', $assessmentId, [
    'name' => trim($body['name'] ?? ''),
    'type' => $type,
]);

echo json_encode($result);
