<?php
/**
 * EduTrack — Student Transcript PDF
 *
 * Generates and streams a student's academic transcript as a PDF.
 * Access rules:
 *   - Student can download their own transcript
 *   - Parent can download their linked child's transcript
 *   - Lecturer can download transcript for students in their units
 *   - Admin can download any student's transcript
 *
 * Method:  GET
 * URL:     /api/reports/transcript.php?student_id=4
 * Access:  Student (own) | Parent (linked) | Lecturer | Admin
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/helpers/PDFHelper.php';

$autoload = ROOT_PATH . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    echo 'PDF library not installed. Run: composer install';
    exit;
}
require_once $autoload;

Auth::startSession();
Auth::requireAnyRole(['student', 'parent', 'lecturer', 'admin']);

$role      = Auth::role();
$currentId = Auth::id();

// Resolve student_id
$studentId = (int)($_GET['student_id'] ?? 0);

if ($role === 'student') {
    // Students always get their own transcript
    $studentId = $currentId;
} elseif ($role === 'parent') {
    if ($studentId <= 0) {
        http_response_code(400);
        echo 'student_id is required.';
        exit;
    }
    $link = DB::row(
        "SELECT parent_id FROM parent_student_links
         WHERE parent_id = ? AND student_id = ?",
        [$currentId, $studentId]
    );
    if (!$link) {
        http_response_code(403);
        echo 'Access denied.';
        exit;
    }
} elseif ($role === 'lecturer') {
    if ($studentId <= 0) {
        http_response_code(400);
        echo 'student_id is required.';
        exit;
    }
    $teaches = DB::row(
        "SELECT e.id FROM enrollments e
         JOIN units u ON u.id = e.unit_id
         WHERE e.student_id = ? AND u.lecturer_id = ?
         LIMIT 1",
        [$studentId, $currentId]
    );
    if (!$teaches) {
        http_response_code(403);
        echo 'Access denied.';
        exit;
    }
} elseif ($role === 'admin') {
    if ($studentId <= 0) {
        http_response_code(400);
        echo 'student_id is required.';
        exit;
    }
}

try {
    $student  = DB::row(
        "SELECT reg_number FROM users WHERE id = ? AND role = 'student'",
        [$studentId]
    );

    if (!$student) {
        http_response_code(404);
        echo 'Student not found.';
        exit;
    }

    $filename = 'transcript_' .
        preg_replace('/[^a-zA-Z0-9]/', '', $student['reg_number']) . '_' .
        date('Ymd') . '.pdf';

    $pdf = PDFHelper::studentTranscript($studentId);
    PDFHelper::download($pdf, $filename);

} catch (Exception $e) {
    http_response_code(500);
    if (APP_ENV === 'development') {
        echo 'Transcript generation failed: ' . $e->getMessage();
    } else {
        echo 'Failed to generate transcript. Please try again.';
    }
}