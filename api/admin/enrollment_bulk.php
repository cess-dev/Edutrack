<?php
/**
 * EduTrack — Admin: Bulk Enroll Student in Course Units
 *
 * Enrolls a student in every active unit for a given course,
 * year of study, and semester. Skips units already enrolled in.
 *
 * Method:  POST
 * URL:     /api/admin/enrollment_bulk.php
 * Access:  Admin only
 *
 * Request: multipart/form-data with a CSV upload.
 *   course_id, year_of_study, academic_year, semester, csv_file
 *
 * The CSV must contain one student registration number per row.
 *
 * Success response (200):
 *   { "success": true, "enrolled": int, "skipped": int, "invalid": int, "message": string }
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

$courseId     = (int)($_POST['course_id']     ?? 0);
$year         = (int)($_POST['year_of_study'] ?? 0);
$academicYear = trim($_POST['academic_year']  ?? '');
$semester     = (int)($_POST['semester']      ?? 0);

if ($courseId <= 0 || $year <= 0 || empty($academicYear) || !in_array($semester,[1,2],true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Course, year, semester and CSV file are required.']);
    exit;
}

if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'CSV file upload failed.']);
    exit;
}

$csvTmpPath = $_FILES['csv_file']['tmp_name'];

if (!is_uploaded_file($csvTmpPath)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid CSV upload.']);
    exit;
}

// Fetch all active units for this course / year / semester
$targetUnits = DB::rows(
    "SELECT id FROM units
     WHERE course_id = ? AND year_of_study = ? AND semester = ? AND is_active = 1",
    [$courseId, $year, $semester]
);

if (empty($targetUnits)) {
    echo json_encode([
        'success'  => false,
        'message'  => "No active units found for Year {$year}, Semester {$semester} in this course.",
    ]);
    exit;
}

$enrolled = 0;
$skipped  = 0;
$invalid  = 0;
$rows     = 0;
$seen     = [];

if (($handle = fopen($csvTmpPath, 'r')) === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to read uploaded CSV.']);
    exit;
}

while (($line = fgetcsv($handle)) !== false) {
    $rows++;
    $regNumber = trim($line[0] ?? '');
    if ($regNumber === '') {
        continue;
    }

    $regNumber = strtoupper($regNumber);
    if (isset($seen[$regNumber])) {
        continue;
    }
    $seen[$regNumber] = true;

    $student = UserModel::findByRegNumber($regNumber);
    if (!$student || $student['role'] !== 'student') {
        $invalid++;
        continue;
    }

    foreach ($targetUnits as $unit) {
        $result = UserModel::enrollStudent(
            $student['id'],
            $unit['id'],
            $academicYear,
            $semester
        );
        if ($result['success']) {
            $enrolled++;
        } else {
            $skipped++;
        }
    }
}
fclose($handle);

Auth::audit('bulk_enrollment', 'enrollments', null, [
    'course_id'    => $courseId,
    'year'         => $year,
    'semester'     => $semester,
    'rows'         => $rows,
    'enrolled'     => $enrolled,
    'skipped'      => $skipped,
    'invalid'      => $invalid,
]);

echo json_encode([
    'success'  => true,
    'enrolled' => $enrolled,
    'skipped'  => $skipped,
    'invalid'  => $invalid,
    'message'  => "Imported {$rows} rows: {$enrolled} enrollments created, {$skipped} skipped, {$invalid} invalid.",
]);