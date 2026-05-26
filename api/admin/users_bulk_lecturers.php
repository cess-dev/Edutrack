<?php
/**
 * EduTrack — Admin: Bulk Create Lecturer Accounts
 *
 * Method:  POST  (multipart/form-data)
 * URL:     /api/admin/users_bulk_lecturers.php
 * Access:  Admin only
 *
 * CSV columns (header row optional):
 *   full_name, email, phone
 *
 * - Reg numbers (LEC001…) are auto-generated.
 * - Default password: Lecturer@1
 * - must_change_password is set to 1 for every new account.
 *
 * Response (200):
 *   {
 *     "success":  true,
 *     "created":  int,
 *     "skipped":  int,
 *     "errors":   [ { row, name, reason } ],
 *     "accounts": [ { name, reg_number, email, phone, temp_pass } ],
 *     "message":  string
 *   }
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/UserModel.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');
Auth::requireRole('admin', true);
Auth::verifyCsrf(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'CSV file upload failed or missing.']);
    exit;
}

$csvPath = $_FILES['csv_file']['tmp_name'];
if (!is_uploaded_file($csvPath)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid file upload.']);
    exit;
}

$handle = fopen($csvPath, 'r');
if ($handle === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to read CSV file.']);
    exit;
}

$rows   = [];
$rowNum = 0;

while (($cols = fgetcsv($handle)) !== false) {
    $rowNum++;
    if (count($cols) === 0 || (count($cols) === 1 && trim($cols[0]) === '')) continue;

    // Detect header
    $col0 = strtolower(trim($cols[0] ?? ''));
    if ($rowNum === 1 && in_array($col0, ['full_name','name','lecturer','full name'], true)) continue;

    $rows[] = [
        'full_name' => trim($cols[0] ?? ''),
        'email'     => trim($cols[1] ?? ''),
        'phone'     => trim($cols[2] ?? ''),
        '_row'      => $rowNum,
    ];
}
fclose($handle);

if (empty($rows)) {
    echo json_encode(['success' => false, 'message' => 'No data rows found in CSV.']);
    exit;
}

$result = UserModel::bulkCreateLecturers($rows, Auth::id(), 'Lecturer@1');

Auth::audit('bulk_create_lecturers', 'users', null, [
    'created' => $result['created'],
    'skipped' => $result['skipped'],
    'errors'  => count($result['errors']),
]);

$parts = [];
if ($result['created'] > 0) $parts[] = "{$result['created']} account(s) created";
if ($result['skipped']  > 0) $parts[] = "{$result['skipped']} skipped";
if (count($result['errors']) > 0) $parts[] = count($result['errors']) . " error(s)";

echo json_encode([
    'success'  => true,
    'created'  => $result['created'],
    'skipped'  => $result['skipped'],
    'errors'   => $result['errors'],
    'accounts' => $result['accounts'],
    'message'  => implode(', ', $parts) ?: 'No rows processed.',
]);
