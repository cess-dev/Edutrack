<?php
/**
 * EduTrack — Get Timetable Schedule
 *
 * Returns the confirmed class schedule for a lecturer (or a student's schedule
 * for the parent portal — identified via student_id param).
 *
 * Method:  GET
 * Params:
 *   lecturer_id  (optional, admin only) — get another lecturer's schedule
 *   student_id   (optional, parent)     — get a student's schedule via enrolled units
 *
 * Access: lecturer, parent, admin
 *
 * Success (200):
 *   { "success": true, "schedule": [...], "timetable": {...} }
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

Auth::startSession();
Auth::requireRole('lecturer', 'parent', 'admin');

header('Content-Type: application/json');

$user = Auth::user();
$role = $user['role'];

// ── Lecturer: own schedule ────────────────────────────────────────────────────

if ($role === 'lecturer') {
    $lecturerId = $user['id'];

    $tt = DB::row(
        "SELECT id, academic_year, semester, original_filename, confirmed_at, extraction_status
         FROM timetables
         WHERE lecturer_id = ? AND extraction_status = 'confirmed'
         ORDER BY confirmed_at DESC
         LIMIT 1",
        [$lecturerId]
    );

    if (!$tt) {
        echo json_encode(['success' => true, 'schedule' => [], 'timetable' => null]);
        exit;
    }

    $schedule = DB::rows(
        "SELECT cs.id, cs.unit_code, cs.unit_name, cs.day_of_week,
                cs.start_time, cs.end_time, cs.room, u.name AS matched_unit_name
         FROM class_schedules cs
         LEFT JOIN units u ON u.id = cs.unit_id
         WHERE cs.timetable_id = ?
         ORDER BY cs.day_of_week, cs.start_time",
        [$tt['id']]
    );

    echo json_encode(['success' => true, 'schedule' => $schedule, 'timetable' => $tt]);
    exit;
}

// ── Parent: student schedule (via enrolled unit lecturers' timetables) ────────

if ($role === 'parent') {
    $studentId = (int)($_GET['student_id'] ?? 0);

    if ($studentId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'student_id required.']);
        exit;
    }

    // Verify link
    $linked = DB::row(
        "SELECT 1 FROM parent_student_links WHERE parent_id = ? AND student_id = ?",
        [$user['id'], $studentId]
    );
    if (!$linked) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied.']);
        exit;
    }

    $academicYear = DB::row("SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year'")['setting_value'] ?? ACADEMIC_YEAR;
    $semester     = (int)(DB::row("SELECT setting_value FROM system_settings WHERE setting_key = 'active_semester'")['setting_value'] ?? ACTIVE_SEMESTER);

    // Get confirmed schedule rows for all units the student is enrolled in
    $schedule = DB::rows(
        "SELECT cs.unit_code, cs.unit_name, cs.day_of_week,
                cs.start_time, cs.end_time, cs.room,
                u2.full_name AS lecturer_name
         FROM enrollments e
         JOIN units u ON u.id = e.unit_id
         JOIN users u2 ON u2.id = u.lecturer_id
         JOIN timetables t ON t.lecturer_id = u.lecturer_id
                           AND t.academic_year = e.academic_year
                           AND t.semester = e.semester
                           AND t.extraction_status = 'confirmed'
         JOIN class_schedules cs ON cs.timetable_id = t.id
                                 AND cs.unit_code = u.code
         WHERE e.student_id = ?
           AND e.academic_year = ?
           AND e.semester = ?
         GROUP BY cs.id
         ORDER BY cs.day_of_week, cs.start_time",
        [$studentId, $academicYear, $semester]
    );

    echo json_encode(['success' => true, 'schedule' => $schedule]);
    exit;
}

// ── Admin: any lecturer ───────────────────────────────────────────────────────

if ($role === 'admin') {
    $lecturerId = (int)($_GET['lecturer_id'] ?? 0);
    if ($lecturerId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'lecturer_id required.']);
        exit;
    }

    $tt = DB::row(
        "SELECT id, academic_year, semester, original_filename, confirmed_at
         FROM timetables
         WHERE lecturer_id = ? AND extraction_status = 'confirmed'
         ORDER BY confirmed_at DESC LIMIT 1",
        [$lecturerId]
    );

    if (!$tt) {
        echo json_encode(['success' => true, 'schedule' => [], 'timetable' => null]);
        exit;
    }

    $schedule = DB::rows(
        "SELECT cs.unit_code, cs.unit_name, cs.day_of_week,
                cs.start_time, cs.end_time, cs.room
         FROM class_schedules cs
         WHERE cs.timetable_id = ?
         ORDER BY cs.day_of_week, cs.start_time",
        [$tt['id']]
    );

    echo json_encode(['success' => true, 'schedule' => $schedule, 'timetable' => $tt]);
    exit;
}
