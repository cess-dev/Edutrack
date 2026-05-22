<?php
/**
 * EduTrack — Marks View Endpoint
 *
 * Returns grade data appropriate to the caller's role:
 *
 *   Student  → own published marks grouped by unit, with weighted totals
 *   Parent   → linked child's published marks (must supply student_id)
 *   Lecturer → full marks sheet for a unit (published + unpublished)
 *              OR transcript summary for a specific student
 *
 * Method:  GET
 * URL:     /api/marks/view.php
 * Access:  Student | Parent | Lecturer
 *
 * Query parameters (all roles):
 *   academic_year   string  optional, default from system_settings
 *   semester        int     optional, default from system_settings
 *
 * Additional params for parent:
 *   student_id      int     required
 *
 * Additional params for lecturer:
 *   view            string  'unit_sheet' | 'transcript' | 'assessments'
 *   unit_id         int     required for unit_sheet and assessments views
 *   student_id      int     required for transcript view
 *
 * ── Student / Parent response (200) ──────────────────────────────────────────
 *   {
 *     "success": true,
 *     "role":    "student"|"parent",
 *     "data":    [
 *       {
 *         "unit_id", "unit_code", "unit_name",
 *         "assessments":    [ { assessment_id, name, type, max_score,
 *                               weight_percent, score, weighted_score } ],
 *         "weighted_total": float,
 *         "grade":          string|null,
 *         "remark":         string|null
 *       }
 *     ]
 *   }
 *
 * ── Lecturer unit_sheet response (200) ───────────────────────────────────────
 *   {
 *     "success":     true,
 *     "view":        "unit_sheet",
 *     "assessments": [ { id, name, type, max_score, weight_percent, is_published } ],
 *     "students":    [ { id, reg_number, full_name, scores: {assessment_id: score},
 *                        weighted_total, grade } ]
 *   }
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/MarksModel.php';

Auth::startSession();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

Auth::requireAnyRole(['student', 'parent', 'lecturer'], true);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

// ── Common parameters ─────────────────────────────────────────────────────────
$role = Auth::role();

$academicYear = $_GET['academic_year'] ?? DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year'"
)['setting_value'] ?? ACADEMIC_YEAR;

$semester = (int) ($_GET['semester'] ?? DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'active_semester'"
)['setting_value'] ?? ACTIVE_SEMESTER);

// ─────────────────────────────────────────────────────────────────────────────
// STUDENT — own published marks
// ─────────────────────────────────────────────────────────────────────────────
if ($role === 'student') {
    $data = MarksModel::getStudentMarks(Auth::id(), $academicYear, $semester);

    echo json_encode([
        'success' => true,
        'role'    => 'student',
        'data'    => $data,
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// PARENT — linked child's published marks
// ─────────────────────────────────────────────────────────────────────────────
if ($role === 'parent') {
    $studentId = (int) ($_GET['student_id'] ?? 0);

    if ($studentId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'student_id is required.']);
        exit;
    }

    // Verify parent is linked to this student
    $link = DB::row(
        "SELECT parent_id FROM parent_student_links
         WHERE parent_id = ? AND student_id = ?",
        [Auth::id(), $studentId]
    );

    if (!$link) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You are not linked to this student.']);
        exit;
    }

    // Fetch student name for context
    $student = DB::row(
        "SELECT full_name, reg_number FROM users WHERE id = ?",
        [$studentId]
    );

    $data = MarksModel::getStudentMarks($studentId, $academicYear, $semester);

    echo json_encode([
        'success' => true,
        'role'    => 'parent',
        'student' => $student,
        'data'    => $data,
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// LECTURER — full marks sheet, assessments list, or student transcript
// ─────────────────────────────────────────────────────────────────────────────
if ($role === 'lecturer') {
    $view      = $_GET['view']       ?? 'assessments';
    $unitId    = (int) ($_GET['unit_id']    ?? 0);
    $studentId = (int) ($_GET['student_id'] ?? 0);
    $lecturerId = Auth::id();

    // ── View: assessments — list all assessments for a unit ──────────────────
    if ($view === 'assessments') {
        if ($unitId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'unit_id is required for assessments view.']);
            exit;
        }

        // Verify lecturer owns this unit
        $unit = DB::row(
            "SELECT id, code, name FROM units WHERE id = ? AND lecturer_id = ?",
            [$unitId, $lecturerId]
        );

        if (!$unit) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unit not found or access denied.']);
            exit;
        }

        $assessments = MarksModel::getUnitAssessments($unitId, false);

        echo json_encode([
            'success'     => true,
            'view'        => 'assessments',
            'unit'        => $unit,
            'assessments' => $assessments,
        ]);
        exit;
    }

    // ── View: unit_sheet — full class marks grid ─────────────────────────────
    if ($view === 'unit_sheet') {
        if ($unitId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'unit_id is required for unit_sheet view.']);
            exit;
        }

        $unit = DB::row(
            "SELECT id, code, name FROM units WHERE id = ? AND lecturer_id = ?",
            [$unitId, $lecturerId]
        );

        if (!$unit) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unit not found or access denied.']);
            exit;
        }

        $sheet = MarksModel::getUnitMarksSheet($unitId, $academicYear, $semester);

        echo json_encode([
            'success'     => true,
            'view'        => 'unit_sheet',
            'unit'        => $unit,
            'assessments' => $sheet['assessments'],
            'students'    => $sheet['students'],
        ]);
        exit;
    }

    // ── View: transcript — one student's grade summary ───────────────────────
    if ($view === 'transcript') {
        if ($studentId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'student_id is required for transcript view.']);
            exit;
        }

        // Verify this lecturer teaches at least one unit the student is enrolled in
        $teaches = DB::row(
            "SELECT e.id
             FROM enrollments e
             JOIN units u ON u.id = e.unit_id
             WHERE e.student_id = ? AND u.lecturer_id = ?
             LIMIT 1",
            [$studentId, $lecturerId]
        );

        if (!$teaches) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'You do not teach any unit this student is enrolled in.',
            ]);
            exit;
        }

        $student    = DB::row(
            "SELECT full_name, reg_number FROM users WHERE id = ?",
            [$studentId]
        );
        $transcript = MarksModel::getStudentTranscript($studentId);

        echo json_encode([
            'success'    => true,
            'view'       => 'transcript',
            'student'    => $student,
            'transcript' => $transcript,
        ]);
        exit;
    }

    // ── Unknown view ─────────────────────────────────────────────────────────
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => "Unknown view '{$view}'. Use 'assessments', 'unit_sheet', or 'transcript'.",
    ]);
    exit;
}