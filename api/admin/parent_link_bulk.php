<?php
/**
 * EduTrack — Admin: Bulk Parent-Student Link via CSV
 *
 * Method:  POST  (multipart/form-data)
 * URL:     /api/admin/parent_link_bulk.php
 * Access:  Admin only
 *
 * A one-time or infrequent operation — parent-student relationships don't
 * change per semester, so this is separate from the enrollment CSV.
 *
 * CSV columns (header row optional — detected if col-0 looks like a label):
 *   parent_reg_number, student_reg_number, relationship
 *
 * Notes:
 *   - relationship column is optional (defaults to "Parent")
 *   - One parent can appear on multiple rows (links to multiple children)
 *   - One student can appear on multiple rows (multiple parents)
 *   - Already-linked pairs are counted as "skipped" (idempotent)
 *   - Unknown reg numbers or wrong roles are reported as errors
 *
 * Response (200):
 *   {
 *     "success": true,
 *     "linked":  int,
 *     "skipped": int,
 *     "errors":  [ { "row": int, "parent": str, "student": str, "reason": str } ],
 *     "message": string
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

// ── Validate upload ───────────────────────────────────────────────────────────
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
    echo json_encode(['success' => false, 'message' => 'Unable to read uploaded CSV.']);
    exit;
}

// ── Process rows ──────────────────────────────────────────────────────────────
$linked  = 0;
$skipped = 0;
$errors  = [];
$seen    = [];   // de-dup key: "parentReg|studentReg"
$rowNum  = 0;

// Simple reg-number lookup cache to avoid redundant DB hits
$userCache = [];

$lookupUser = function(string $reg) use (&$userCache): ?array {
    if (!isset($userCache[$reg])) {
        $userCache[$reg] = UserModel::findByRegNumber($reg) ?: null;
    }
    return $userCache[$reg];
};

while (($cols = fgetcsv($handle)) !== false) {
    $rowNum++;

    // Skip blank rows
    if (count($cols) === 0 || (count($cols) === 1 && trim($cols[0]) === '')) {
        continue;
    }

    // Detect and skip header row
    $col0Lower = strtolower(trim($cols[0] ?? ''));
    if ($rowNum === 1 && in_array($col0Lower, [
        'parent_reg_number', 'parent', 'parent_reg', 'parent_id', 'parent_number'
    ], true)) {
        continue;
    }

    $parentReg  = strtoupper(trim($cols[0] ?? ''));
    $studentReg = strtoupper(trim($cols[1] ?? ''));
    $rel        = trim($cols[2] ?? '') ?: 'Parent';

    // Both columns must be present
    if ($parentReg === '' || $studentReg === '') {
        $errors[] = [
            'row'     => $rowNum,
            'parent'  => $parentReg ?: '(blank)',
            'student' => $studentReg ?: '(blank)',
            'reason'  => 'Both parent_reg_number and student_reg_number are required.',
        ];
        continue;
    }

    // De-duplicate within the same CSV upload
    $dupKey = "{$parentReg}|{$studentReg}";
    if (isset($seen[$dupKey])) {
        continue;
    }
    $seen[$dupKey] = true;

    // Look up parent
    $parent = $lookupUser($parentReg);
    if (!$parent) {
        $errors[] = [
            'row'     => $rowNum,
            'parent'  => $parentReg,
            'student' => $studentReg,
            'reason'  => "Parent reg number '{$parentReg}' not found.",
        ];
        continue;
    }
    if ($parent['role'] !== 'parent') {
        $errors[] = [
            'row'     => $rowNum,
            'parent'  => $parentReg,
            'student' => $studentReg,
            'reason'  => "'{$parentReg}' has role '{$parent['role']}', not 'parent'.",
        ];
        continue;
    }

    // Look up student
    $student = $lookupUser($studentReg);
    if (!$student) {
        $errors[] = [
            'row'     => $rowNum,
            'parent'  => $parentReg,
            'student' => $studentReg,
            'reason'  => "Student reg number '{$studentReg}' not found.",
        ];
        continue;
    }
    if ($student['role'] !== 'student') {
        $errors[] = [
            'row'     => $rowNum,
            'parent'  => $parentReg,
            'student' => $studentReg,
            'reason'  => "'{$studentReg}' has role '{$student['role']}', not 'student'.",
        ];
        continue;
    }

    // Attempt the link (UserModel handles duplicate detection internally)
    $result = UserModel::linkParentToStudent($parent['id'], $student['id'], $rel);

    if ($result['success']) {
        $linked++;
    } else {
        // linkParentToStudent returns false when already linked — count as skipped
        $skipped++;
    }
}
fclose($handle);

Auth::audit('bulk_parent_link', 'parent_student_links', null, [
    'linked'  => $linked,
    'skipped' => $skipped,
    'errors'  => count($errors),
]);

$parts = [];
if ($linked  > 0) $parts[] = "{$linked} linked";
if ($skipped > 0) $parts[] = "{$skipped} already linked (skipped)";
if (count($errors) > 0) $parts[] = count($errors) . " error(s)";

echo json_encode([
    'success' => true,
    'linked'  => $linked,
    'skipped' => $skipped,
    'errors'  => $errors,
    'message' => implode(', ', $parts) ?: 'No rows processed.',
]);
