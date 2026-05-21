<?php
/**
 * EduTrack — Admin: Update Unit
 *
 * Method:  POST
 * URL:     /api/admin/unit_update.php
 * Access:  Admin only
 *
 * Request body (JSON):
 *   unit_id, code, name, semester, year_of_study,
 *   credit_hours, lecturer_id (optional), is_active
 */

define('EDUTRACK_LOADED', true);
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

$body       = json_decode(file_get_contents('php://input'), true) ?? [];
$unitId     = (int)($body['unit_id']      ?? 0);
$code       = strtoupper(trim($body['code'] ?? ''));
$name       = trim($body['name']           ?? '');
$semester   = (int)($body['semester']      ?? 1);
$year       = (int)($body['year_of_study'] ?? 1);
$credits    = (int)($body['credit_hours']  ?? 3);
$lecturerId = !empty($body['lecturer_id']) ? (int)$body['lecturer_id'] : null;
$isActive   = (int)filter_var($body['is_active'] ?? 1, FILTER_VALIDATE_BOOLEAN);

if ($unitId <= 0 || empty($code) || empty($name)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'unit_id, code and name are required.']);
    exit;
}

// Check code uniqueness excluding this unit
$conflict = DB::row(
    "SELECT id FROM units WHERE code = ? AND id != ?",
    [$code, $unitId]
);
if ($conflict) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => "Unit code '{$code}' is already used."]);
    exit;
}

DB::execute(
    "UPDATE units
     SET code = ?, name = ?, semester = ?, year_of_study = ?,
         credit_hours = ?, lecturer_id = ?, is_active = ?
     WHERE id = ?",
    [$code, $name, $semester, $year, $credits, $lecturerId, $isActive, $unitId]
);

Auth::audit('unit_updated', 'units', $unitId, [
    'lecturer_id' => $lecturerId,
]);

echo json_encode(['success' => true, 'message' => 'Unit updated.']);