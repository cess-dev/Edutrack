<?php
/**
 * EduTrack — Class Attendance Report PDF
 *
 * Generates and streams a PDF attendance report.
 * Supports two modes via query parameters:
 *
 *   ?session_id=123          → Single-session class register
 *   ?unit_id=5               → Full unit attendance summary (all sessions)
 *
 * Method:  GET
 * URL:     /api/reports/class_report.php
 * Access:  Lecturer or Admin
 */

define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/helpers/PDFHelper.php';

// Load Composer autoloader for mPDF
$autoload = ROOT_PATH . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    echo 'PDF library not installed. Run: composer install';
    exit;
}
require_once $autoload;

Auth::startSession();
Auth::requireAnyRole(['lecturer', 'admin']);

$sessionId = (int)($_GET['session_id'] ?? 0);
$unitId    = (int)($_GET['unit_id']    ?? 0);

$academicYear = DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year'"
)['setting_value'] ?? ACADEMIC_YEAR;

$semester = (int)(DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'active_semester'"
)['setting_value'] ?? ACTIVE_SEMESTER);

try {
    if ($sessionId > 0) {
        // Verify access: lecturer must own the session (admin bypasses)
        if (Auth::role() === 'lecturer') {
            $check = DB::row(
                "SELECT id FROM attendance_sessions
                 WHERE id = ? AND lecturer_id = ?",
                [$sessionId, Auth::id()]
            );
            if (!$check) {
                http_response_code(403);
                echo 'Access denied.';
                exit;
            }
        }

        $session = DB::row(
            "SELECT unit_code, started_at FROM (
               SELECT u.code AS unit_code, s.started_at
               FROM attendance_sessions s
               JOIN units u ON u.id = s.unit_id
               WHERE s.id = ?
             ) sub",
            [$sessionId]
        );

        $filename = 'attendance_' .
            preg_replace('/[^a-zA-Z0-9]/', '', $session['unit_code'] ?? 'unit') . '_' .
            date('Ymd_His', strtotime($session['started_at'] ?? 'now')) . '.pdf';

        $pdf = PDFHelper::attendanceReport($sessionId);
        PDFHelper::download($pdf, $filename);

    } elseif ($unitId > 0) {
        // Verify access: lecturer must teach the unit
        if (Auth::role() === 'lecturer') {
            $check = DB::row(
                "SELECT id FROM units WHERE id = ? AND lecturer_id = ?",
                [$unitId, Auth::id()]
            );
            if (!$check) {
                http_response_code(403);
                echo 'Access denied.';
                exit;
            }
        }

        $unit = DB::row("SELECT code FROM units WHERE id = ?", [$unitId]);
        $filename = 'attendance_summary_' .
            preg_replace('/[^a-zA-Z0-9]/', '', $unit['code'] ?? 'unit') . '_' .
            date('Ymd') . '.pdf';

        $pdf = PDFHelper::unitAttendanceSummary($unitId, $academicYear, $semester);
        PDFHelper::download($pdf, $filename);

    } else {
        http_response_code(400);
        echo 'Provide session_id or unit_id as a query parameter.';
    }

} catch (Exception $e) {
    http_response_code(500);
    if (APP_ENV === 'development') {
        echo 'PDF generation failed: ' . $e->getMessage();
    } else {
        echo 'Failed to generate report. Please try again.';
    }
}