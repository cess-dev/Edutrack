<?php
/**
 * EduTrack — Attendance History Endpoint
 *
 * Returns a student's full attendance log with pagination.
 * Accessible by the student (own record) and by a parent (linked children).
 *
 * Method:  GET
 * URL:     /api/attendance/history.php
 * Access:  Student (own data) | Parent (linked children only)
 *
 * Query parameters:
 *   student_id    int     required for parent role; ignored for student role
 *                         (students always get their own data)
 *   page          int     optional, default 1
 *   academic_year string  optional, default from system_settings
 *   semester      int     optional, default from system_settings
 *   view          string  'log' (paginated log) | 'summary' (per-unit totals)
 *                         default: 'summary'
 *
 * Success response — summary view (200):
 *   {
 *     "success": true,
 *     "view":    "summary",
 *     "data":    [ { unit_code, unit_name, total_sessions,
 *                    attended, absent, excused, attendance_percent } ]
 *   }
 *
 * Success response — log view (200):
 *   {
 *     "success": true,
 *     "view":    "log",
 *     "data": {
 *       "rows":  [ { status, method, scanned_at, started_at,
 *                    unit_code, unit_name, lecturer_name } ],
 *       "total": int,
 *       "pages": int,
 *       "page":  int
 *     }
 *   }
 */

define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/AttendanceModel.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

Auth::requireAnyRole(['student', 'parent'], true);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

// ── Resolve which student's data to return ────────────────────────────────────
$role      = Auth::role();
$currentId = Auth::id();

if ($role === 'student') {
    // Students always get their own data — ignore any student_id param
    $studentId = $currentId;

} else {
    // Parent — must supply a student_id that is linked to their account
    $studentId = (int) ($_GET['student_id'] ?? 0);

    if ($studentId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'student_id is required for parent access.']);
        exit;
    }

    // Verify the parent is actually linked to this student
    $link = DB::row(
        "SELECT parent_id FROM parent_student_links
         WHERE parent_id = ? AND student_id = ?",
        [$currentId, $studentId]
    );

    if (!$link) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You are not linked to this student.']);
        exit;
    }
}

// ── Read request parameters ───────────────────────────────────────────────────
$view = in_array($_GET['view'] ?? '', ['log', 'summary'], true)
    ? $_GET['view']
    : 'summary';

$page = max(1, (int) ($_GET['page'] ?? 1));

$academicYear = $_GET['academic_year'] ?? DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year'"
)['setting_value'] ?? ACADEMIC_YEAR;

$semester = (int) ($_GET['semester'] ?? DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'active_semester'"
)['setting_value'] ?? ACTIVE_SEMESTER);

// ── Fetch data ────────────────────────────────────────────────────────────────
if ($view === 'log') {
    $data = AttendanceModel::getStudentHistory($studentId, $page);
    echo json_encode([
        'success' => true,
        'view'    => 'log',
        'data'    => $data,
    ]);
} else {
    $data = AttendanceModel::getStudentSummary($studentId, $academicYear, $semester);
    echo json_encode([
        'success' => true,
        'view'    => 'summary',
        'data'    => $data,
    ]);
}