<?php
/**
 * EduTrack — Class Marks Sheet PDF
 *
 * Generates and streams a PDF of the unit marks sheet.
 *
 * URL: /api/reports/marks_sheet.php?unit_id=UNIT_ID
 * Access: Lecturer or Admin
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

// Load Composer autoloader for mPDF before using PDF generation.
$autoload = ROOT_PATH . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    echo 'PDF library not installed. Run: composer install';
    exit;
}
require_once $autoload;

require_once __DIR__ . '/../../backend/helpers/PDFHelper.php';
require_once __DIR__ . '/../../backend/models/MarksModel.php';

Auth::startSession();
Auth::requireAnyRole(['lecturer', 'admin']);

$unitId = (int)($_GET['unit_id'] ?? 0);

if ($unitId <= 0) {
    http_response_code(400);
    echo 'Provide a valid unit_id.';
    exit;
}

$academicYear = DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year'"
)['setting_value'] ?? ACADEMIC_YEAR;

$semester = (int)(DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'active_semester'"
)['setting_value'] ?? ACTIVE_SEMESTER);

try {
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
    $filename = 'marks_sheet_' .
        preg_replace('/[^a-zA-Z0-9]/', '', $unit['code'] ?? 'unit') . '_' .
        date('Ymd_His') . '.pdf';

    $pdf = PDFHelper::marksSheet($unitId, $academicYear, $semester);
    PDFHelper::download($pdf, $filename);

} catch (Exception $e) {
    http_response_code(500);
    if (APP_ENV === 'development') {
        echo 'PDF generation failed: ' . $e->getMessage();
    } else {
        echo 'Failed to generate report. Please try again.';
    }
}
