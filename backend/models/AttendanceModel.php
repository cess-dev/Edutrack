<?php
/**
 * EduTrack — Attendance Model
 *
 * All database reads and writes related to attendance live here.
 * API endpoints and controllers call these methods — no SQL ever
 * appears outside of model files.
 *
 * Covers:
 *   - Fetching attendance history (student, lecturer, parent views)
 *   - Recording a scan (present) or manual override
 *   - Summary and analytics queries
 *   - Dispute submission and review
 *   - At-risk student detection
 */

if (!defined('EDUTRACK_LOADED')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

class AttendanceModel
{
    // ─────────────────────────────────────────────────────────────────────────
    // Recording attendance
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Record a student as present after a successful QR scan.
     * Called immediately after QRHelper::validateScan() returns valid = true.
     *
     * Uses INSERT IGNORE as a last-resort guard against race conditions
     * (the UNIQUE constraint on session_id + student_id handles duplicates).
     *
     * @param  int    $sessionId
     * @param  int    $studentId
     * @return bool   True if the row was inserted, false if it already existed
     */
    public static function recordScan(int $sessionId, int $studentId): bool
    {
        $affected = DB::execute(
            "INSERT IGNORE INTO attendance_logs
                (session_id, student_id, status, method, scanned_at)
             VALUES (?, ?, 'present', 'qr_scan', NOW())",
            [$sessionId, $studentId]
        );

        return $affected > 0;
    }

    /**
     * Manually mark a student present or absent for a session.
     * Used by lecturers to correct or override a scan record.
     *
     * If a log row already exists it is updated; if not a new row is inserted.
     * The method is set to 'manual' and marked_by records the lecturer's ID.
     *
     * @param  int    $sessionId
     * @param  int    $studentId
     * @param  string $status      'present' | 'absent' | 'excused'
     * @param  int    $markedBy    Lecturer's user ID
     * @return bool
     */
    public static function manualMark(
        int    $sessionId,
        int    $studentId,
        string $status,
        int    $markedBy
    ): bool {
        $validStatuses = ['present', 'absent', 'excused'];
        if (!in_array($status, $validStatuses, true)) {
            return false;
        }

        $affected = DB::execute(
            "INSERT INTO attendance_logs
                (session_id, student_id, status, method, scanned_at, marked_by)
             VALUES (?, ?, ?, 'manual', NOW(), ?)
             ON DUPLICATE KEY UPDATE
                status     = VALUES(status),
                method     = 'manual',
                scanned_at = NOW(),
                marked_by  = VALUES(marked_by)",
            [$sessionId, $studentId, $status, $markedBy]
        );

        return $affected > 0;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // History — student view
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Full attendance log for a specific student across all their units.
     * Ordered most-recent first. Supports pagination.
     *
     * Returns each session with: date, unit name, lecturer name,
     * status (present/absent/excused), and how they were marked.
     *
     * @param  int $studentId
     * @param  int $page
     * @param  int $perPage
     * @return array { rows: array, total: int, pages: int }
     */
    public static function getStudentHistory(
        int $studentId,
        int $page    = 1,
        int $perPage = ROWS_PER_PAGE
    ): array {
        $offset = ($page - 1) * $perPage;

        $total = DB::row(
            "SELECT COUNT(*) AS cnt
             FROM attendance_logs al
             JOIN attendance_sessions s ON s.id = al.session_id
             WHERE al.student_id = ?",
            [$studentId]
        )['cnt'] ?? 0;

        $rows = DB::rows(
            "SELECT
                al.status,
                al.method,
                al.scanned_at,
                s.started_at,
                s.note          AS session_note,
                u.code          AS unit_code,
                u.name          AS unit_name,
                lec.full_name   AS lecturer_name
             FROM attendance_logs al
             JOIN attendance_sessions s ON s.id  = al.session_id
             JOIN units u               ON u.id  = s.unit_id
             JOIN users lec             ON lec.id = s.lecturer_id
             WHERE al.student_id = ?
             ORDER BY s.started_at DESC
             LIMIT ? OFFSET ?",
            [$studentId, $perPage, $offset]
        );

        return [
            'rows'  => $rows,
            'total' => (int) $total,
            'pages' => (int) ceil($total / $perPage),
            'page'  => $page,
        ];
    }

    /**
     * Attendance summary per unit for a student — used on dashboards.
     * Reads from the vw_attendance_summary view.
     *
     * @param  int    $studentId
     * @param  string $academicYear
     * @param  int    $semester
     * @return array  One row per enrolled unit
     */
    public static function getStudentSummary(
        int    $studentId,
        string $academicYear,
        int    $semester
    ): array {
        return DB::rows(
            "SELECT
                unit_code,
                unit_name,
                total_sessions,
                attended,
                absent,
                excused,
                attendance_percent
             FROM vw_attendance_summary
             WHERE student_id   = ?
               AND academic_year = ?
               AND semester      = ?
             ORDER BY unit_name ASC",
            [$studentId, $academicYear, $semester]
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // History — lecturer view
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * All attendance sessions created by a lecturer, with scan counts.
     * Used on the lecturer's session history page.
     *
     * @param  int $lecturerId
     * @param  int $page
     * @param  int $perPage
     * @return array { rows: array, total: int, pages: int }
     */
    public static function getLecturerSessions(
        int $lecturerId,
        int $page    = 1,
        int $perPage = ROWS_PER_PAGE
    ): array {
        $offset = ($page - 1) * $perPage;

        $total = DB::row(
            "SELECT COUNT(*) AS cnt
             FROM attendance_sessions
             WHERE lecturer_id = ?",
            [$lecturerId]
        )['cnt'] ?? 0;

        $rows = DB::rows(
            "SELECT
                s.id,
                s.started_at,
                s.closed_at,
                s.expires_at,
                s.is_active,
                s.note,
                u.code          AS unit_code,
                u.name          AS unit_name,
                COUNT(CASE WHEN al.status = 'present' THEN 1 END)  AS present_count,
                COUNT(CASE WHEN al.status = 'absent'  THEN 1 END)  AS absent_count,
                COUNT(al.id)                                        AS total_logged
             FROM attendance_sessions s
             JOIN units u ON u.id = s.unit_id
             LEFT JOIN attendance_logs al ON al.session_id = s.id
             WHERE s.lecturer_id = ?
             GROUP BY s.id, s.started_at, s.closed_at, s.expires_at,
                      s.is_active, s.note, u.code, u.name
             ORDER BY s.started_at DESC
             LIMIT ? OFFSET ?",
            [$lecturerId, $perPage, $offset]
        );

        return [
            'rows'  => $rows,
            'total' => (int) $total,
            'pages' => (int) ceil($total / $perPage),
            'page'  => $page,
        ];
    }

    /**
     * Class attendance register for a specific session.
     * Returns every enrolled student with their status for that session.
     * Used by lecturers to see the full register in one view.
     *
     * @param  int $sessionId
     * @return array
     */
    public static function getSessionRegister(int $sessionId): array
    {
        // Get the unit and academic context from the session
        $session = DB::row(
            "SELECT unit_id, academic_year, semester
             FROM attendance_sessions WHERE id = ?",
            [$sessionId]
        );

        if (!$session) {
            return [];
        }

        return DB::rows(
            "SELECT
                u.id            AS student_id,
                u.reg_number,
                u.full_name,
                COALESCE(al.status, 'absent')  AS status,
                COALESCE(al.method, 'auto_absent') AS method,
                al.scanned_at
             FROM enrollments e
             JOIN users u ON u.id = e.student_id
             LEFT JOIN attendance_logs al
                 ON al.session_id = ? AND al.student_id = e.student_id
             WHERE e.unit_id      = ?
               AND e.academic_year = ?
               AND e.semester      = ?
             ORDER BY u.full_name ASC",
            [$sessionId, $session['unit_id'], $session['academic_year'], $session['semester']]
        );
    }

    /**
     * Attendance summary for all students in a unit — lecturer analytics view.
     * Returns each student's attendance percentage for the unit.
     *
     * @param  int    $unitId
     * @param  string $academicYear
     * @param  int    $semester
     * @return array
     */
    public static function getUnitAttendanceSummary(
        int    $unitId,
        string $academicYear,
        int    $semester
    ): array {
        return DB::rows(
            "SELECT
                student_id,
                student_name,
                total_sessions,
                attended,
                absent,
                excused,
                attendance_percent
             FROM vw_attendance_summary
             WHERE unit_id       = ?
               AND academic_year = ?
               AND semester      = ?
             ORDER BY attendance_percent ASC",
            [$unitId, $academicYear, $semester]
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // History — parent view
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Attendance summary for all students linked to a parent account.
     * Combines vw_attendance_summary with parent_student_links.
     *
     * @param  int    $parentId
     * @param  string $academicYear
     * @param  int    $semester
     * @return array  Keyed by student_id for easy grouping in the view
     */
    public static function getParentSummary(
        int    $parentId,
        string $academicYear,
        int    $semester
    ): array {
        return DB::rows(
            "SELECT
                psl.student_id,
                psl.relationship,
                stu.full_name   AS student_name,
                stu.reg_number  AS student_reg,
                vas.unit_code,
                vas.unit_name,
                vas.total_sessions,
                vas.attended,
                vas.absent,
                vas.attendance_percent
             FROM parent_student_links psl
             JOIN users stu ON stu.id = psl.student_id
             LEFT JOIN vw_attendance_summary vas
                 ON vas.student_id    = psl.student_id
                 AND vas.academic_year = ?
                 AND vas.semester      = ?
             WHERE psl.parent_id = ?
             ORDER BY stu.full_name ASC, vas.unit_name ASC",
            [$academicYear, $semester, $parentId]
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Analytics
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return students whose attendance is below the alert threshold.
     * Used by the admin panel and lecturer analytics page.
     *
     * @param  int    $unitId        0 = all units for this lecturer
     * @param  int    $lecturerId    0 = school-wide (admin use)
     * @param  string $academicYear
     * @param  int    $semester
     * @return array
     */
    public static function getAtRiskStudents(
        int    $unitId,
        int    $lecturerId,
        string $academicYear,
        int    $semester
    ): array {
        $threshold = (int) DB::row(
            "SELECT setting_value FROM system_settings
             WHERE setting_key = 'attendance_threshold'"
        )['setting_value'] ?? ATTENDANCE_ALERT_THRESHOLD;

        $params = [$academicYear, $semester, $threshold];
        $filter = '';

        if ($unitId > 0) {
            $filter  .= ' AND vas.unit_id = ?';
            $params[] = $unitId;
        }

        if ($lecturerId > 0) {
            $filter  .= ' AND un.lecturer_id = ?';
            $params[] = $lecturerId;
        }

        return DB::rows(
            "SELECT
                vas.student_id,
                vas.student_name,
                vas.unit_code,
                vas.unit_name,
                vas.attendance_percent,
                vas.total_sessions,
                vas.attended,
                vas.absent,
                u.phone,
                u.email
             FROM vw_attendance_summary vas
             JOIN units un ON un.id = vas.unit_id
             JOIN users u  ON u.id  = vas.student_id
             WHERE vas.academic_year     = ?
               AND vas.semester          = ?
               AND vas.attendance_percent < ?
               AND vas.total_sessions    > 0
               {$filter}
             ORDER BY vas.attendance_percent ASC",
            $params
        );
    }

    /**
     * Attendance trend for a unit over time — used for Chart.js line graphs.
     * Returns one data point per session: date and percentage of class present.
     *
     * @param  int $unitId
     * @param  string $academicYear
     * @param  int $semester
     * @return array [ { session_date, present_count, total_enrolled, percent } ]
     */
    public static function getUnitTrend(
        int    $unitId,
        string $academicYear,
        int    $semester
    ): array {
        return DB::rows(
            "SELECT
                DATE(s.started_at)                                        AS session_date,
                s.id                                                      AS session_id,
                COUNT(CASE WHEN al.status = 'present' THEN 1 END)         AS present_count,
                COUNT(al.id)                                              AS total_logged,
                ROUND(
                    COUNT(CASE WHEN al.status = 'present' THEN 1 END)
                    / NULLIF(COUNT(al.id), 0) * 100, 1
                )                                                         AS percent
             FROM attendance_sessions s
             LEFT JOIN attendance_logs al ON al.session_id = s.id
             WHERE s.unit_id      = ?
               AND s.academic_year = ?
               AND s.semester      = ?
               AND s.is_active     = 0
             GROUP BY s.id, session_date
             ORDER BY s.started_at ASC",
            [$unitId, $academicYear, $semester]
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Disputes
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Submit an attendance dispute.
     * Checks the dispute window has not closed before inserting.
     *
     * @param  int    $studentId
     * @param  int    $sessionId
     * @param  string $reason
     * @return array { success: bool, message: string }
     */
    public static function submitDispute(
        int    $studentId,
        int    $sessionId,
        string $reason
    ): array {
        // Verify the student was actually marked absent for this session
        $log = DB::row(
            "SELECT status FROM attendance_logs
             WHERE session_id = ? AND student_id = ?",
            [$sessionId, $studentId]
        );

        if (!$log) {
            return ['success' => false, 'message' => 'No attendance record found for this session.'];
        }

        if ($log['status'] === 'present') {
            return ['success' => false, 'message' => 'You are already marked present for this session.'];
        }

        // Check dispute window has not expired
        $session = DB::row(
            "SELECT closed_at FROM attendance_sessions WHERE id = ?",
            [$sessionId]
        );

        $windowHours = (int) (DB::row(
            "SELECT setting_value FROM system_settings WHERE setting_key = 'dispute_window_hours'"
        )['setting_value'] ?? DISPUTE_WINDOW_HOURS);

        if ($session && $session['closed_at']) {
            $deadline = strtotime($session['closed_at']) + ($windowHours * 3600);
            if (time() > $deadline) {
                return [
                    'success' => false,
                    'message' => "The dispute window for this session has closed ({$windowHours} hours after session end).",
                ];
            }
        }

        // Check for an existing dispute
        $existing = DB::row(
            "SELECT id, status FROM disputes
             WHERE student_id = ? AND session_id = ?",
            [$studentId, $sessionId]
        );

        if ($existing) {
            return [
                'success' => false,
                'message' => 'You have already submitted a dispute for this session. Status: ' . $existing['status'],
            ];
        }

        DB::insert(
            "INSERT INTO disputes (student_id, session_id, reason)
             VALUES (?, ?, ?)",
            [$studentId, $sessionId, trim($reason)]
        );

        return ['success' => true, 'message' => 'Dispute submitted. Your lecturer will review it shortly.'];
    }

    /**
     * Get all disputes for sessions taught by a specific lecturer.
     * Used on the lecturer's dispute review page.
     *
     * @param  int    $lecturerId
     * @param  string $status      'pending' | 'approved' | 'rejected' | 'all'
     * @return array
     */
    public static function getLecturerDisputes(int $lecturerId, string $status = 'pending'): array
    {
        $params    = [$lecturerId];
        $statusSql = '';

        if ($status !== 'all') {
            $statusSql = ' AND d.status = ?';
            $params[]  = $status;
        }

        return DB::rows(
            "SELECT
                d.id,
                d.reason,
                d.status,
                d.reviewer_note,
                d.reviewed_at,
                d.created_at,
                stu.full_name   AS student_name,
                stu.reg_number  AS student_reg,
                u.code          AS unit_code,
                u.name          AS unit_name,
                s.started_at    AS session_date
             FROM disputes d
             JOIN users stu              ON stu.id = d.student_id
             JOIN attendance_sessions s  ON s.id   = d.session_id
             JOIN units u                ON u.id   = s.unit_id
             WHERE s.lecturer_id = ?
               {$statusSql}
             ORDER BY d.created_at DESC",
            $params
        );
    }

    /**
     * Approve or reject a dispute.
     * If approved, the student's attendance_log row is updated to 'excused'.
     *
     * @param  int    $disputeId
     * @param  int    $reviewerId   Lecturer's user ID
     * @param  string $decision     'approved' | 'rejected'
     * @param  string $note         Reviewer's note
     * @return array { success: bool, message: string }
     */
    public static function reviewDispute(
        int    $disputeId,
        int    $reviewerId,
        string $decision,
        string $note
    ): array {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            return ['success' => false, 'message' => 'Invalid decision value.'];
        }

        $dispute = DB::row(
            "SELECT d.id, d.student_id, d.session_id, d.status, s.lecturer_id
             FROM disputes d
             JOIN attendance_sessions s ON s.id = d.session_id
             WHERE d.id = ?",
            [$disputeId]
        );

        if (!$dispute) {
            return ['success' => false, 'message' => 'Dispute not found.'];
        }

        if ($dispute['lecturer_id'] !== $reviewerId) {
            return ['success' => false, 'message' => 'You are not authorised to review this dispute.'];
        }

        if ($dispute['status'] !== 'pending') {
            return ['success' => false, 'message' => 'This dispute has already been reviewed.'];
        }

        DB::beginTransaction();

        try {
            // Update dispute record
            DB::execute(
                "UPDATE disputes
                 SET status        = ?,
                     reviewer_id   = ?,
                     reviewer_note = ?,
                     reviewed_at   = NOW()
                 WHERE id = ?",
                [$decision, $reviewerId, trim($note), $disputeId]
            );

            // If approved, update the attendance log to 'excused'
            if ($decision === 'approved') {
                DB::execute(
                    "UPDATE attendance_logs
                     SET status    = 'excused',
                         method    = 'manual',
                         marked_by = ?
                     WHERE session_id = ? AND student_id = ?",
                    [$reviewerId, $dispute['session_id'], $dispute['student_id']]
                );
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Dispute ' . $decision . ' successfully.',
            ];

        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }
}