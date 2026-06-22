<?php
/**
 * EduTrack — Lecturer AI Teaching Assistant Service
 *
 * Powers the lecturer portal chat assistant via LM Studio / Qwen2.5.
 *
 * Four capability areas (per product spec):
 *   1. Workflow assistant  — "how do I" system questions answered from SYSTEM_GUIDE
 *   2. At-risk summaries   — plain-language briefing from live attendance + marks data
 *   3. Dispute triage      — counts and categorises pending disputes (NEVER recommends decisions)
 *   4. Report interpretation — explains attendance patterns and flags anomalies
 *
 * Behavioural constraint: the model must NEVER recommend approving or
 * rejecting a specific dispute. It presents data; the lecturer decides.
 */

if (!defined('EDUTRACK_LOADED')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

class LecturerAIService
{
    public static function chat(int $lecturerId, string $message, array $history = []): array
    {
        if (!defined('AI_ENABLED') || !AI_ENABLED
            || !defined('LM_STUDIO_URL')   || LM_STUDIO_URL   === ''
            || !defined('LM_STUDIO_MODEL') || LM_STUDIO_MODEL === '') {
            return ['success' => false, 'error' => 'AI assistant is not configured. Set LM_STUDIO_URL and LM_STUDIO_MODEL in .env.'];
        }

        $system   = self::buildSystemPrompt($lecturerId);
        $messages = self::buildMessages($system, $history, $message);

        $payload = [
            'model'       => LM_STUDIO_MODEL,
            'messages'    => $messages,
            'temperature' => 0.4,   // lower temp → more precise, less hallucination
            'max_tokens'  => 700,
            'stream'      => false,
        ];

        $response = self::httpPost(LM_STUDIO_URL, $payload);

        if (!$response['ok']) {
            $decoded = json_decode($response['body'], true);
            $errMsg  = $decoded['error']['message'] ?? $response['body'];
            $errCode = $decoded['error']['code']    ?? $response['status'];
            error_log("[LecturerAI] Error {$errCode}: {$errMsg}");

            $userMsg = (defined('APP_ENV') && APP_ENV === 'development')
                ? "AI error ({$errCode}): {$errMsg}"
                : 'The assistant is temporarily unavailable. Please try again.';

            return ['success' => false, 'error' => $userMsg];
        }

        $data  = json_decode($response['body'], true);
        $reply = trim($data['choices'][0]['message']['content'] ?? '');

        if ($reply === '') {
            error_log('[LecturerAI] Empty response: ' . $response['body']);
            return ['success' => false, 'error' => 'No response received. Please try again.'];
        }

        return ['success' => true, 'reply' => $reply];
    }

    // ── System prompt ─────────────────────────────────────────────────────────

    public static function buildSystemPrompt(int $lecturerId): string
    {
        $lecturer     = self::fetchLecturer($lecturerId);
        $academicYear = self::fetchSetting('academic_year', ACADEMIC_YEAR);
        $semester     = (int) self::fetchSetting('active_semester', ACTIVE_SEMESTER);
        $threshold    = (int) self::fetchSetting('attendance_threshold', ATTENDANCE_ALERT_THRESHOLD);
        $disputeHours = (int) self::fetchSetting('dispute_window_hours', DISPUTE_WINDOW_HOURS);
        $qrWindow     = (int) self::fetchSetting('attendance_window', ATTENDANCE_WINDOW_MINUTES);
        $school       = self::fetchSetting('school_name', defined('SCHOOL_NAME') ? SCHOOL_NAME : 'the institution');
        $phone1       = self::fetchSetting('school_phone_1', defined('SCHOOL_PHONE_1') ? SCHOOL_PHONE_1 : '');
        $phone2       = self::fetchSetting('school_phone_2', defined('SCHOOL_PHONE_2') ? SCHOOL_PHONE_2 : '');
        $schoolEmail  = self::fetchSetting('school_email',   defined('SCHOOL_EMAIL')   ? SCHOOL_EMAIL   : '');

        $units         = self::fetchUnits($lecturerId);
        $atRisk        = self::fetchAtRisk($lecturerId, $threshold, $academicYear, $semester);
        $disputes      = self::fetchDisputeSummary($lecturerId);
        $marksStatus   = self::fetchMarksStatus($lecturerId, $academicYear, $semester);
        $recentSessions = self::fetchRecentSessions($lecturerId);

        $lines = [];

        // ── Identity + rules ─────────────────────────────────────────────────
        $lines[] = "You are the EduTrack teaching assistant for {$school}. Helping: {$lecturer['full_name']}. {$academicYear} Sem{$semester}. Today " . date('d M Y') . ".";
        $lines[] = "RULES: Assist with data summaries and system how-to questions only. NEVER recommend approving or rejecting a specific dispute — present the data and let the lecturer decide. Keep responses concise unless detail is requested.";

        // ── Units ────────────────────────────────────────────────────────────
        if (!empty($units)) {
            $unitParts = array_map(
                fn($u) => "{$u['code']}:{$u['name']}({$u['enrolled']} enrolled)",
                $units
            );
            $lines[] = "UNITS[" . count($units) . "]: " . implode(' | ', $unitParts);
        } else {
            $lines[] = "UNITS: none assigned this semester.";
        }

        // ── At-risk students ─────────────────────────────────────────────────
        if (!empty($atRisk)) {
            $lines[] = "AT_RISK (attendance<{$threshold}%):";
            foreach ($atRisk as $r) {
                $worst = implode(', ', array_map(
                    fn($s) => "{$s['full_name']} " . round($s['attendance_percent'], 0) . "%",
                    $r['worst']
                ));
                $lines[] = "  {$r['unit_code']}: {$r['count']} student(s) at risk — worst: {$worst}";
            }
        } else {
            $lines[] = "AT_RISK: no students below {$threshold}% attendance threshold.";
        }

        // ── Dispute summary ──────────────────────────────────────────────────
        if ($disputes['total'] > 0) {
            $g = $disputes['groups'];
            $parts = [];
            if ($g['medical']     > 0) $parts[] = "medical/illness:{$g['medical']}";
            if ($g['transport']   > 0) $parts[] = "transport:{$g['transport']}";
            if ($g['personal']    > 0) $parts[] = "personal/other:{$g['personal']}";
            if ($g['unspecified'] > 0) $parts[] = "unspecified:{$g['unspecified']}";
            $summary = implode(' | ', $parts);
            $ageNote = $disputes['oldest_days'] > 0
                ? " oldest:{$disputes['oldest_days']}d ago"
                : " oldest:<1d ago";
            $lines[] = "DISPUTES_PENDING:{$disputes['total']} — {$summary}{$ageNote}";
        } else {
            $lines[] = "DISPUTES_PENDING: none.";
        }

        // ── Marks upload status ──────────────────────────────────────────────
        if (!empty($marksStatus)) {
            $lines[] = "MARKS_STATUS (uploaded/enrolled):";
            foreach ($marksStatus as $m) {
                $pub    = $m['is_published'] ? '✓pub' : 'draft';
                $lines[] = "  {$m['unit_code']} / {$m['assessment_name']}: {$m['uploaded']}/{$m['enrolled']} [{$pub}]";
            }
        }

        // ── Recent session attendance ────────────────────────────────────────
        if (!empty($recentSessions)) {
            $sessionParts = array_map(
                fn($s) => "{$s['session_date']} {$s['unit_code']} {$s['present']}/{$s['total']}(" . round($s['present'] / max($s['total'], 1) * 100) . "%)",
                $recentSessions
            );
            $lines[] = "RECENT_SESSIONS: " . implode(' | ', $sessionParts);
        }

        // ── School contact ───────────────────────────────────────────────────
        $contactParts = [];
        if (!empty($phone1)) $contactParts[] = $phone1;
        if (!empty($phone2)) $contactParts[] = $phone2;
        if (!empty($schoolEmail)) $contactParts[] = $schoolEmail;
        if (!empty($contactParts)) {
            $lines[] = "SCHOOL_CONTACT: " . implode(' | ', $contactParts);
        }

        // ── Embedded system guide ────────────────────────────────────────────
        $lines[] = "SYSTEM_GUIDE (use for 'how do I' questions):";
        $lines[] = "  upload-marks: Marks page > select unit > 'Upload Marks' button > CSV format: header row 'reg_number,score', one student per row; re-upload overwrites existing score for same student.";
        $lines[] = "  missing-marks: create a CSV with only the missing students and re-upload; existing scores are updated not duplicated.";
        $lines[] = "  csv-errors: reg_number must match exactly (case-insensitive); score must be ≤ max_score; no blank rows; UTF-8 encoding.";
        $lines[] = "  create-assessment: Marks > '+ New Assessment' > enter name, type, max score, weight%, optional date.";
        $lines[] = "  publish-marks: Marks > assessment row > click the Draft/Published toggle; students see marks immediately on publish.";
        $lines[] = "  start-session: Sessions > 'Start Session' > select unit > QR code displayed, valid {$qrWindow} minutes; students scan to mark present.";
        $lines[] = "  close-session: Sessions > 'Close Session'; absent students auto-marked; dispute window opens for {$disputeHours}h.";
        $lines[] = "  dispute-review: Disputes page > select a dispute > read reason > add a reviewer note > click Approve or Reject; student is notified.";
        $lines[] = "  dispute-window: {$disputeHours}h after session closes for students to submit disputes (absent students only).";
        $lines[] = "  marksheet: Marks page > 'View all' link on the class mark sheet card to see full grid; 'Export PDF' to download.";
        $lines[] = "  analytics: Analytics page shows attendance trend charts, grade distribution, and class average per unit.";

        return implode("\n", $lines);
    }

    // ── Data fetchers ─────────────────────────────────────────────────────────

    private static function fetchLecturer(int $id): array
    {
        return DB::row("SELECT full_name FROM users WHERE id = ?", [$id])
            ?? ['full_name' => 'Lecturer'];
    }

    private static function fetchUnits(int $lecturerId): array
    {
        return DB::rows(
            "SELECT u.id, u.code, u.name, c.name AS course_name,
                    COUNT(DISTINCT e.student_id) AS enrolled
             FROM units u
             JOIN courses c ON c.id = u.course_id
             LEFT JOIN enrollments e ON e.unit_id = u.id
             WHERE u.lecturer_id = ? AND u.is_active = 1
             GROUP BY u.id
             ORDER BY u.code",
            [$lecturerId]
        );
    }

    private static function fetchAtRisk(int $lecturerId, int $threshold, string $year, int $sem): array
    {
        $unitIds = DB::rows(
            "SELECT id, code FROM units WHERE lecturer_id = ? AND is_active = 1",
            [$lecturerId]
        );

        $result = [];
        foreach ($unitIds as $unit) {
            $count = (int)(DB::row(
                "SELECT COUNT(*) AS cnt FROM vw_attendance_summary
                 WHERE unit_id = ? AND academic_year = ? AND semester = ? AND attendance_percent < ?",
                [$unit['id'], $year, $sem, $threshold]
            )['cnt'] ?? 0);

            if ($count === 0) continue;

            // Worst 3 by lowest attendance
            $worst = DB::rows(
                "SELECT u.full_name, v.attendance_percent
                 FROM vw_attendance_summary v
                 JOIN users u ON u.id = v.student_id
                 WHERE v.unit_id = ? AND v.academic_year = ? AND v.semester = ?
                   AND v.attendance_percent < ?
                 ORDER BY v.attendance_percent ASC
                 LIMIT 3",
                [$unit['id'], $year, $sem, $threshold]
            );

            $result[] = [
                'unit_code' => $unit['code'],
                'count'     => $count,
                'worst'     => $worst,
            ];
        }

        return $result;
    }

    private static function fetchDisputeSummary(int $lecturerId): array
    {
        $disputes = DB::rows(
            "SELECT d.reason, d.created_at
             FROM disputes d
             JOIN attendance_sessions s ON s.id = d.session_id
             WHERE s.lecturer_id = ? AND d.status = 'pending'
             ORDER BY d.created_at ASC",
            [$lecturerId]
        );

        if (empty($disputes)) {
            return ['total' => 0, 'groups' => [], 'oldest_days' => 0];
        }

        $groups = ['medical' => 0, 'transport' => 0, 'personal' => 0, 'unspecified' => 0];
        foreach ($disputes as $d) {
            $r = strtolower(trim($d['reason'] ?? ''));
            if (empty($r) || $r === 'unspecified') {
                $groups['unspecified']++;
            } elseif (preg_match('/sick|ill|hospital|doctor|medical|fever|headache|nausea|injury|accident/', $r)) {
                $groups['medical']++;
            } elseif (preg_match('/transport|bus|matatu|car|traffic|fare|vehicle|breakdown/', $r)) {
                $groups['transport']++;
            } else {
                $groups['personal']++;
            }
        }

        $oldestTs = strtotime($disputes[0]['created_at']);
        return [
            'total'       => count($disputes),
            'groups'      => $groups,
            'oldest_days' => (int) floor((time() - $oldestTs) / 86400),
        ];
    }

    private static function fetchMarksStatus(int $lecturerId, string $year, int $sem): array
    {
        return DB::rows(
            "SELECT u.code AS unit_code, a.name AS assessment_name,
                    a.is_published,
                    COUNT(m.id) AS uploaded,
                    enr.enrolled
             FROM assessments a
             JOIN units u ON u.id = a.unit_id
             LEFT JOIN marks m ON m.assessment_id = a.id
             JOIN (
                 SELECT unit_id, COUNT(DISTINCT student_id) AS enrolled
                 FROM enrollments
                 WHERE academic_year = ? AND semester = ?
                 GROUP BY unit_id
             ) enr ON enr.unit_id = u.id
             WHERE u.lecturer_id = ?
             GROUP BY a.id, u.code, a.name, a.is_published, enr.enrolled
             ORDER BY u.code, a.id",
            [$year, $sem, $lecturerId]
        );
    }

    private static function fetchRecentSessions(int $lecturerId): array
    {
        return DB::rows(
            "SELECT u.code AS unit_code, DATE(s.started_at) AS session_date,
                    SUM(al.status = 'present') AS present,
                    COUNT(al.student_id) AS total
             FROM attendance_sessions s
             JOIN units u ON u.id = s.unit_id
             LEFT JOIN attendance_logs al ON al.session_id = s.id
             WHERE s.lecturer_id = ? AND s.is_active = 0
             GROUP BY s.id, u.code, s.started_at
             ORDER BY s.started_at DESC
             LIMIT 8",
            [$lecturerId]
        );
    }

    private static function fetchSetting(string $key, mixed $default): mixed
    {
        $row = DB::row("SELECT setting_value FROM system_settings WHERE setting_key = ?", [$key]);
        return ($row && $row['setting_value'] !== '') ? $row['setting_value'] : $default;
    }

    // ── Messages builder ──────────────────────────────────────────────────────

    private static function buildMessages(string $system, array $history, string $message): array
    {
        $messages = [['role' => 'system', 'content' => $system]];

        $maxTurns = defined('AI_MAX_HISTORY_TURNS') ? (int) AI_MAX_HISTORY_TURNS : 6;
        $history  = array_slice($history, -($maxTurns * 2));

        foreach ($history as $turn) {
            $messages[] = [
                'role'    => $turn['role'] === 'model' ? 'assistant' : 'user',
                'content' => $turn['text'],
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $message];
        return $messages;
    }

    // ── HTTP ──────────────────────────────────────────────────────────────────

    private static function httpPost(string $url, array $payload): array
    {
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($payloadJson === false) {
            $jsonError = json_last_error_msg();
            error_log("[LecturerAI] Payload JSON encode failed: {$jsonError}");
            return ['ok' => false, 'status' => 0, 'body' => json_encode(['error' => ['code' => 0, 'message' => "Payload encode failed: {$jsonError}"]])];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payloadJson,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . LM_STUDIO_API_KEY,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_ENCODING       => '',
        ]);

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log('[LecturerAI] cURL error: ' . $error);
            return ['ok' => false, 'status' => 0, 'body' => json_encode(['error' => ['code' => 0, 'message' => $error]])];
        }

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => $body];
    }
}
