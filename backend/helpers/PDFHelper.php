<?php
/**
 * EduTrack — PDF Helper
 *
 * Wraps mPDF to generate styled PDF documents.
 * All PDFs use the school name and academic year from system_settings —
 * nothing is hardcoded in the output.
 *
 * Covers:
 *   - Class attendance report   (lecturer → session register)
 *   - Unit attendance summary   (lecturer → all students in a unit)
 *   - Student transcript        (student/parent → grades + GPA)
 *   - Marks sheet               (lecturer → class marks grid)
 *
 * Usage:
 *   $pdf  = PDFHelper::attendanceReport($sessionId);
 *   $path = PDFHelper::save($pdf, 'attendance_report.pdf');
 *   PDFHelper::download($pdf, 'attendance_report.pdf');
 *
 * Requires: composer require mpdf/mpdf
 */

if (!defined('EDUTRACK_LOADED')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

class PDFHelper
{
    // ── mPDF default config ───────────────────────────────────────────────────

    private static function defaultConfig(): array
    {
        return [
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'orientation'   => 'P',
            'margin_left'   => 15,
            'margin_right'  => 15,
            'margin_top'    => 20,
            'margin_bottom' => 15,
            'margin_header' => 8,
            'margin_footer' => 8,
        ];
    }

    // ── Shared CSS injected into every PDF ────────────────────────────────────

    private static function baseStyles(): string
    {
        return '
        body        { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9pt; color: #1E2733; }
        h1          { font-size: 14pt; color: #1A3C5E; margin-bottom: 4px; }
        h2          { font-size: 11pt; color: #1A3C5E; margin-bottom: 3px; }
        h3          { font-size: 10pt; color: #0F7B6C; margin-bottom: 2px; }
        .meta       { font-size: 8pt; color: #5A6472; margin-bottom: 12px; }
        .divider    { border-top: 1.5px solid #0F7B6C; margin: 8px 0; }
        table       { width: 100%; border-collapse: collapse; font-size: 8pt; }
        th          { background: #1A3C5E; color: #ffffff; padding: 5px 7px;
                      text-align: left; font-weight: bold; }
        td          { padding: 4px 7px; border-bottom: 1px solid #E8ECF1; }
        tr:nth-child(even) td { background: #F4F6F9; }
        .present    { color: #0F7B6C; font-weight: bold; }
        .absent     { color: #D85A30; font-weight: bold; }
        .excused    { color: #C47B12; font-weight: bold; }
        .grade-A    { color: #0F7B6C; font-weight: bold; }
        .grade-B    { color: #0F7B6C; }
        .grade-C    { color: #C47B12; }
        .grade-D    { color: #D85A30; }
        .grade-E    { color: #D85A30; font-weight: bold; }
        .footer-note{ font-size: 7pt; color: #8A95A3; text-align: center; margin-top: 10px; }
        .badge      { padding: 2px 6px; border-radius: 3px; font-size: 7.5pt; }
        .badge-info { background: #E6F4F2; color: #0F7B6C; }
        ';
    }

    // ── Header and footer HTML ────────────────────────────────────────────────

    private static function pageHeader(string $schoolName, string $title): string
    {
        return "
        <table style='width:100%; border-bottom:2px solid #1A3C5E; padding-bottom:5px; margin-bottom:5px;'>
          <tr>
            <td style='width:70%'>
              <strong style='font-size:11pt; color:#1A3C5E;'>{$schoolName}</strong><br>
              <span style='font-size:8pt; color:#5A6472;'>" . htmlspecialchars(APP_NAME) . " — Student Monitoring System</span>
            </td>
            <td style='text-align:right; font-size:8pt; color:#5A6472;'>
              " . htmlspecialchars($title) . "<br>
              Generated: " . date('d M Y, H:i') . "
            </td>
          </tr>
        </table>";
    }

    private static function pageFooter(): string
    {
        return "<div style='text-align:center; font-size:7pt; color:#8A95A3;'>
                  Page {PAGENO} of {nbpg} &nbsp;·&nbsp; " .
                  htmlspecialchars(APP_NAME) . " &nbsp;·&nbsp; Confidential
                </div>";
    }

    // ── Create mPDF instance ──────────────────────────────────────────────────

    private static function make(string $title, bool $landscape = false): \Mpdf\Mpdf
    {
        $config             = self::defaultConfig();
        $config['format']   = $landscape ? 'A4-L' : 'A4';

        $mpdf = new \Mpdf\Mpdf($config);
        $mpdf->WriteHTML('<style>' . self::baseStyles() . '</style>');
        $mpdf->SetTitle($title);
        $mpdf->SetAuthor(APP_NAME);
        $mpdf->SetCreator(APP_NAME);
        $mpdf->shrink_tables_to_fit = 1;

        return $mpdf;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC: Attendance Report (per session — class register)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Generate a PDF class register for a single attendance session.
     *
     * @param  int $sessionId
     * @return \Mpdf\Mpdf
     */
    public static function attendanceReport(int $sessionId): \Mpdf\Mpdf
    {
        // Fetch session details
        $session = DB::row(
            "SELECT s.id, s.started_at, s.closed_at, s.note,
                    s.academic_year, s.semester,
                    u.code AS unit_code, u.name AS unit_name,
                    c.name AS course_name,
                    lec.full_name AS lecturer_name
             FROM attendance_sessions s
             JOIN units u   ON u.id = s.unit_id
             JOIN courses c ON c.id = u.course_id
             JOIN users lec ON lec.id = s.lecturer_id
             WHERE s.id = ?",
            [$sessionId]
        );

        if (!$session) {
            throw new RuntimeException("Session {$sessionId} not found.");
        }

        // Fetch register rows
        $rows = DB::rows(
            "SELECT stu.reg_number, stu.full_name,
                    COALESCE(al.status, 'absent')      AS status,
                    COALESCE(al.method, 'auto_absent') AS method,
                    al.scanned_at
             FROM enrollments e
             JOIN users stu ON stu.id = e.student_id
             LEFT JOIN attendance_logs al
                 ON al.session_id = ? AND al.student_id = e.student_id
             WHERE e.unit_id = (SELECT unit_id FROM attendance_sessions WHERE id = ?)
               AND e.academic_year = ?
               AND e.semester      = ?
             ORDER BY stu.full_name ASC",
            [$sessionId, $sessionId, $session['academic_year'], $session['semester']]
        );

        $schoolName  = self::getSchoolName();
        $presentCount = count(array_filter($rows, fn($r) => $r['status'] === 'present'));
        $totalCount   = count($rows);

        $title = "Attendance Register — {$session['unit_code']}";
        $mpdf  = self::make($title);

        ob_start();
        ?>
        <?= self::pageHeader($schoolName, 'Attendance Register') ?>

        <h1><?= htmlspecialchars($session['unit_code']) ?> — <?= htmlspecialchars($session['unit_name']) ?></h1>
        <div class="meta">
          Course: <?= htmlspecialchars($session['course_name']) ?> &nbsp;·&nbsp;
          Lecturer: <?= htmlspecialchars($session['lecturer_name']) ?> &nbsp;·&nbsp;
          Date: <?= date('D d M Y, H:i', strtotime($session['started_at'])) ?> &nbsp;·&nbsp;
          <?= htmlspecialchars($session['academic_year']) ?> Sem <?= $session['semester'] ?>
          <?php if ($session['note']): ?>
            &nbsp;·&nbsp; Note: <?= htmlspecialchars($session['note']) ?>
          <?php endif; ?>
        </div>

        <div style="margin-bottom:8px;font-size:9pt;">
          <strong>Present: <?= $presentCount ?></strong> /
          Total: <?= $totalCount ?>
          <?php if ($totalCount > 0): ?>
            &nbsp;·&nbsp;
            <?= round(($presentCount / $totalCount) * 100, 1) ?>% attendance
          <?php endif; ?>
        </div>

        <div class="divider"></div>

        <table>
          <thead>
            <tr>
              <th style="width:5%">#</th>
              <th style="width:18%">Reg. Number</th>
              <th style="width:42%">Student Name</th>
              <th style="width:13%">Status</th>
              <th style="width:13%">Method</th>
              <th style="width:9%">Time</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $i => $row): ?>
              <tr>
                <td><?= $i + 1 ?></td>
                <td style="font-family:monospace"><?= htmlspecialchars($row['reg_number']) ?></td>
                <td><?= htmlspecialchars($row['full_name']) ?></td>
                <td class="<?= $row['status'] ?>">
                  <?= ucfirst($row['status']) ?>
                </td>
                <td style="color:#8A95A3;font-size:7.5pt">
                  <?= str_replace('_',' ', ucfirst($row['method'])) ?>
                </td>
                <td style="font-family:monospace;font-size:7.5pt">
                  <?= $row['scanned_at']
                      ? date('H:i:s', strtotime($row['scanned_at']))
                      : '—' ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div class="footer-note">
          Generated by <?= htmlspecialchars(APP_NAME) ?> on <?= date('d M Y, H:i') ?>
          &nbsp;·&nbsp; Confidential — for authorised staff only
        </div>
        <?php
        $html = ob_get_clean();
        $mpdf->WriteHTML($html);

        return $mpdf;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC: Student Transcript
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Generate a student transcript PDF showing all published grades and GPA.
     *
     * @param  int $studentId
     * @return \Mpdf\Mpdf
     */
    public static function studentTranscript(int $studentId): \Mpdf\Mpdf
    {
        $student = DB::row(
            "SELECT full_name, reg_number FROM users WHERE id = ? AND role = 'student'",
            [$studentId]
        );

        if (!$student) {
            throw new RuntimeException("Student {$studentId} not found.");
        }

        $academicYear = self::getSetting('academic_year', ACADEMIC_YEAR);
        $semester     = self::getSetting('active_semester', (string) ACTIVE_SEMESTER);
        $schoolName   = self::getSchoolName();

        // Fetch grade data from the view
        $grades = DB::rows(
            "SELECT unit_code, unit_name, weighted_total,
                    assessments_submitted, assessments_total
             FROM vw_unit_grades
             WHERE student_id = ?
             ORDER BY unit_name ASC",
            [$studentId]
        );

        // Grade boundaries (must match MarksModel)
        $boundaries = [
            ['min' => 70, 'grade' => 'A', 'points' => 4.0, 'remark' => 'Distinction'],
            ['min' => 60, 'grade' => 'B', 'points' => 3.0, 'remark' => 'Credit'],
            ['min' => 50, 'grade' => 'C', 'points' => 2.0, 'remark' => 'Pass'],
            ['min' => 40, 'grade' => 'D', 'points' => 1.0, 'remark' => 'Marginal Fail'],
            ['min' =>  0, 'grade' => 'E', 'points' => 0.0, 'remark' => 'Fail'],
        ];

        $getGrade = function (float $total) use ($boundaries): array {
            foreach ($boundaries as $b) {
                if ($total >= $b['min']) return $b;
            }
            return end($boundaries);
        };

        $totalPoints = 0.0;
        $unitCount   = 0;

        $title = "Academic Transcript — {$student['reg_number']}";
        $mpdf  = self::make($title);

        ob_start();
        ?>
        <?= self::pageHeader($schoolName, 'Academic Transcript') ?>

        <h1>Academic Transcript</h1>
        <div class="meta">
          Student: <strong><?= htmlspecialchars($student['full_name']) ?></strong>
          &nbsp;·&nbsp;
          Reg: <span style="font-family:monospace"><?= htmlspecialchars($student['reg_number']) ?></span>
          &nbsp;·&nbsp;
          <?= htmlspecialchars($academicYear) ?> · Semester <?= htmlspecialchars($semester) ?>
        </div>

        <div class="divider"></div>

        <?php if (empty($grades)): ?>
          <p style="color:#8A95A3;font-style:italic">No grades published yet.</p>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th style="width:12%">Code</th>
                <th style="width:40%">Unit Name</th>
                <th style="width:14%;text-align:center">Total (/100)</th>
                <th style="width:8%;text-align:center">Grade</th>
                <th style="width:8%;text-align:center">Points</th>
                <th style="width:18%">Remark</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($grades as $g):
                $gradeInfo    = $getGrade((float)$g['weighted_total']);
                $totalPoints += $gradeInfo['points'];
                $unitCount++;
              ?>
                <tr>
                  <td style="font-family:monospace;font-size:8pt">
                    <?= htmlspecialchars($g['unit_code']) ?>
                  </td>
                  <td><?= htmlspecialchars($g['unit_name']) ?></td>
                  <td style="text-align:center;font-weight:bold">
                    <?= number_format((float)$g['weighted_total'], 2) ?>
                  </td>
                  <td style="text-align:center"
                      class="grade-<?= $gradeInfo['grade'] ?>">
                    <strong><?= $gradeInfo['grade'] ?></strong>
                  </td>
                  <td style="text-align:center">
                    <?= number_format($gradeInfo['points'], 1) ?>
                  </td>
                  <td><?= $gradeInfo['remark'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <?php if ($unitCount > 0):
            $gpa = round($totalPoints / $unitCount, 2);
          ?>
            <div style="margin-top:10px;padding:8px 12px;
                        background:#F4F6F9;border-left:4px solid #1A3C5E;
                        font-size:9pt">
              <strong>GPA: <?= $gpa ?> / 4.00</strong>
              &nbsp;·&nbsp; Units completed: <?= $unitCount ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>

        <div class="footer-note">
          This transcript was generated by <?= htmlspecialchars(APP_NAME) ?>
          on <?= date('d M Y, H:i') ?>.
          For official records, request a certified copy from the registrar.
        </div>
        <?php
        $html = ob_get_clean();
        $mpdf->WriteHTML($html);

        return $mpdf;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC: Unit Attendance Summary
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Generate a summary PDF of attendance for all students in a unit.
     *
     * @param  int    $unitId
     * @param  string $academicYear
     * @param  int    $semester
     * @return \Mpdf\Mpdf
     */
    public static function unitAttendanceSummary(
        int    $unitId,
        string $academicYear,
        int    $semester
    ): \Mpdf\Mpdf {
        $unit = DB::row(
            "SELECT u.code, u.name, c.name AS course_name, lec.full_name AS lecturer_name
             FROM units u
             JOIN courses c ON c.id = u.course_id
             LEFT JOIN users lec ON lec.id = u.lecturer_id
             WHERE u.id = ?",
            [$unitId]
        );

        if (!$unit) {
            throw new RuntimeException("Unit {$unitId} not found.");
        }

        $threshold  = (int)self::getSetting('attendance_threshold', (string)ATTENDANCE_ALERT_THRESHOLD);
        $schoolName = self::getSchoolName();

        $rows = DB::rows(
            "SELECT student_name, student_id,
                    total_sessions, attended, absent, excused,
                    attendance_percent
             FROM vw_attendance_summary
             WHERE unit_id       = ?
               AND academic_year = ?
               AND semester      = ?
             ORDER BY attendance_percent ASC",
            [$unitId, $academicYear, $semester]
        );

        $title = "Attendance Summary — {$unit['code']}";
        $mpdf  = self::make($title);

        ob_start();
        ?>
        <?= self::pageHeader($schoolName, 'Attendance Summary') ?>

        <h1><?= htmlspecialchars($unit['code']) ?> — <?= htmlspecialchars($unit['name']) ?></h1>
        <div class="meta">
          Course: <?= htmlspecialchars($unit['course_name']) ?>
          &nbsp;·&nbsp; Lecturer: <?= htmlspecialchars($unit['lecturer_name'] ?? 'Unassigned') ?>
          &nbsp;·&nbsp; <?= htmlspecialchars($academicYear) ?> Sem <?= $semester ?>
          &nbsp;·&nbsp; Alert threshold: <?= $threshold ?>%
        </div>
        <div class="divider"></div>

        <table>
          <thead>
            <tr>
              <th style="width:5%">#</th>
              <th style="width:40%">Student Name</th>
              <th style="width:10%;text-align:center">Sessions</th>
              <th style="width:10%;text-align:center">Attended</th>
              <th style="width:10%;text-align:center">Absent</th>
              <th style="width:10%;text-align:center">Excused</th>
              <th style="width:15%;text-align:center">Attendance %</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $i => $r):
              $pct   = (float)$r['attendance_percent'];
              $color = $pct < $threshold ? '#D85A30' : '#0F7B6C';
            ?>
              <tr>
                <td><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($r['student_name']) ?></td>
                <td style="text-align:center"><?= $r['total_sessions'] ?></td>
                <td style="text-align:center;color:#0F7B6C;font-weight:bold">
                  <?= $r['attended'] ?>
                </td>
                <td style="text-align:center;color:#D85A30">
                  <?= $r['absent'] ?>
                </td>
                <td style="text-align:center;color:#C47B12">
                  <?= $r['excused'] ?>
                </td>
                <td style="text-align:center;font-weight:bold;color:<?= $color ?>">
                  <?= $pct ?>%
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div class="footer-note">
          Generated by <?= htmlspecialchars(APP_NAME) ?> on <?= date('d M Y, H:i') ?>
          &nbsp;·&nbsp; Students below <?= $threshold ?>% highlighted in red
        </div>
        <?php
        $html = ob_get_clean();
        $mpdf->WriteHTML($html);

        return $mpdf;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC: Class Marks Sheet
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Generate a PDF marks sheet for a unit with all students and assessments.
     *
     * @param  int    $unitId
     * @param  string $academicYear
     * @param  int    $semester
     * @return \Mpdf\Mpdf
     */
    public static function marksSheet(int $unitId, string $academicYear, int $semester): \Mpdf\Mpdf
    {
        if (!class_exists('MarksModel')) {
            require_once __DIR__ . '/../models/MarksModel.php';
        }

        $unit = DB::row(
            "SELECT u.code, u.name, c.name AS course_name, lec.full_name AS lecturer_name
             FROM units u
             JOIN courses c ON c.id = u.course_id
             LEFT JOIN users lec ON lec.id = u.lecturer_id
             WHERE u.id = ?",
            [$unitId]
        );

        if (!$unit) {
            throw new RuntimeException("Unit {$unitId} not found.");
        }

        $data = MarksModel::getUnitMarksSheet($unitId, $academicYear, $semester);
        $assessments = $data['assessments'];
        $students    = $data['students'];

        $schoolName = self::getSchoolName();
        $title      = "Marks Sheet — {$unit['code']}";
        $mpdf       = self::make($title, true);

        ob_start();
        ?>
        <?= self::pageHeader($schoolName, 'Class Marks Sheet') ?>

        <h1>Class Marks Sheet</h1>
        <div class="meta">
          Course: <?= htmlspecialchars($unit['course_name']) ?>
          &nbsp;·&nbsp; Lecturer: <?= htmlspecialchars($unit['lecturer_name'] ?? 'Unassigned') ?>
          &nbsp;·&nbsp; <?= htmlspecialchars($academicYear) ?> Sem <?= htmlspecialchars($semester) ?>
        </div>

        <div class="divider"></div>

        <?php if (empty($students) || empty($assessments)): ?>
          <p style="color:#8A95A3;font-style:italic">
            No marks data available for this unit yet.
          </p>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th style="width:8%">Reg. No.</th>
                <th style="width:20%">Student Name</th>
                <?php foreach ($assessments as $a): ?>
                  <th style="text-align:center;">
                    <?= htmlspecialchars($a['name']) ?><br>
                    <span style="font-size:7pt;color:#5A6472">/<?= $a['max_score'] ?></span>
                  </th>
                <?php endforeach; ?>
                <th style="width:10%;text-align:center">Total</th>
                <th style="width:8%;text-align:center">Grade</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($students as $student): ?>
                <tr>
                  <td style="font-family:monospace;"><?= htmlspecialchars($student['reg_number']) ?></td>
                  <td><?= htmlspecialchars($student['full_name']) ?></td>
                  <?php foreach ($assessments as $a):
                    $score = $student['scores'][$a['id']] ?? null;
                  ?>
                    <td style="text-align:center">
                      <?= $score !== null ? htmlspecialchars($score) : '—' ?>
                    </td>
                  <?php endforeach; ?>
                  <td style="text-align:center;font-weight:bold">
                    <?= htmlspecialchars(number_format($student['weighted_total'], 2)) ?>
                  </td>
                  <td style="text-align:center"
                      class="grade-<?= htmlspecialchars(strtolower($student['grade'] ?? 'e')) ?>">
                    <?= $student['grade'] ? htmlspecialchars($student['grade']) : '—' ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>

        <div class="footer-note">
          Generated by <?= htmlspecialchars(APP_NAME) ?> on <?= date('d M Y, H:i') ?>
          &nbsp;·&nbsp; Confidential — for authorised staff only
        </div>
        <?php
        $html = ob_get_clean();
        $mpdf->WriteHTML($html);

        return $mpdf;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OUTPUT HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Save a PDF to the exports directory and return the path.
     *
     * @param  \Mpdf\Mpdf $mpdf
     * @param  string     $filename  e.g. 'report_BCS101.pdf'
     * @return string     Full path to the saved file
     */
    public static function save(\Mpdf\Mpdf $mpdf, string $filename): string
    {
        if (!is_dir(EXPORTS_PATH)) {
            mkdir(EXPORTS_PATH, 0750, true);
        }
        // Sanitise filename — keep only safe characters
        $safe = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $filename);
        $path = EXPORTS_PATH . '/' . $safe;
        $mpdf->Output($path, \Mpdf\Output\Destination::FILE);
        return $path;
    }

    /**
     * Stream a PDF directly to the browser as a download.
     *
     * @param  \Mpdf\Mpdf $mpdf
     * @param  string     $filename
     */
    public static function download(\Mpdf\Mpdf $mpdf, string $filename): void
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $filename);
        $mpdf->Output($safe, \Mpdf\Output\Destination::DOWNLOAD);
        exit;
    }

    /**
     * Stream a PDF inline (opens in browser PDF viewer).
     *
     * @param  \Mpdf\Mpdf $mpdf
     * @param  string     $filename
     */
    public static function inline(\Mpdf\Mpdf $mpdf, string $filename): void
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $filename);
        $mpdf->Output($safe, \Mpdf\Output\Destination::INLINE);
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private static function getSchoolName(): string
    {
        return self::getSetting('school_name', SCHOOL_NAME);
    }

    private static function getSetting(string $key, string $default): string
    {
        $row = DB::row(
            "SELECT setting_value FROM system_settings WHERE setting_key = ?",
            [$key]
        );
        return $row['setting_value'] ?? $default;
    }

    /** Prevent instantiation */
    private function __construct() {}
}