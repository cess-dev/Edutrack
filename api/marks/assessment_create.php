<?php
/**
 * EduTrack — Create Assessment
 *
 * Allows a lecturer to define a new graded component
 * (CAT, exam, assignment, etc.) for one of their units.
 *
 * Method:  POST
 * URL:     /api/marks/assessment_create.php
 * Access:  Lecturer only
 *
 * Request body (JSON):
 *   unit_id:         int
 *   name:            string
 *   type:            'cat'|'assignment'|'practical'|'project'|'final_exam'
 *   max_score:       float
 *   weight_percent:  float
 *   assessment_date: string|null  (YYYY-MM-DD)
 *
 * Success response (201):
 *   { "success": true, "id": int, "message": string }
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

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$unitId = (int)($body['unit_id'] ?? 0);

if ($unitId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid unit_id is required.']);
    exit;
}

// Verify this unit belongs to the requesting lecturer
$unit = DB::row(
    "SELECT id FROM units WHERE id = ? AND lecturer_id = ?",
    [$unitId, Auth::id()]
);

if (!$unit) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unit not found or access denied.']);
    exit;
}

// Validate type
$validTypes = ['cat', 'assignment', 'practical', 'project', 'final_exam'];
$type       = trim($body['type'] ?? '');

if (!in_array($type, $validTypes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid assessment type.']);
    exit;
}

// Validate date format if provided
$date = $body['assessment_date'] ?? null;
if ($date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Date must be in YYYY-MM-DD format.']);
    exit;
}

$result = MarksModel::createAssessment([
    'unit_id'         => $unitId,
    'name'            => trim($body['name'] ?? ''),
    'type'            => $type,
    'max_score'       => (float)($body['max_score']      ?? 0),
    'weight_percent'  => (float)($body['weight_percent'] ?? 0),
    'assessment_date' => $date ?: null,
    'created_by'      => Auth::id(),
]);

if (!$result['success']) {
    http_response_code(400);
    echo json_encode($result);
    exit;
}

Auth::audit('assessment_created', 'assessments', $result['id'], [
    'unit_id' => $unitId,
    'name'    => trim($body['name'] ?? ''),
    'type'    => $type,
]);

http_response_code(201);
echo json_encode($result);