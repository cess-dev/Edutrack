<?php
/**
 * EduTrack — Admin: Student Live Search
 *
 * Returns students matching a name or reg number query.
 * Used by the link-parent modal's typeahead search.
 *
 * Method:  GET
 * URL:     /api/admin/students_search.php?q=SEARCH_TERM
 * Access:  Admin only
 *
 * Success response (200):
 *   {
 *     "success":  true,
 *     "students": [ { id, reg_number, full_name }, ... ]
 *   }
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/UserModel.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

Auth::requireRole('admin', true);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$q = trim($_GET['q'] ?? '');

if (strlen($q) < 2) {
    echo json_encode(['success' => true, 'students' => []]);
    exit;
}

$students = UserModel::getStudentOptions($q);

echo json_encode([
    'success'  => true,
    'students' => $students,
]);