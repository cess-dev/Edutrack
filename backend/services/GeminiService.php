<?php
/**
 * EduTrack — Local AI Service (LM Studio / Qwen2.5)
 *
 * Sends the student's live academic data as a compact system prompt
 * to a locally-running LM Studio server via its OpenAI-compatible API.
 *
 * Prompt is intentionally compact — local models have limited context windows.
 * Every token counts; we use single-line packed format for data sections.
 */

if (!defined('EDUTRACK_LOADED')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

class GeminiService
{
    public static function isEnabled(): bool
    {
        return defined('AI_ENABLED') && AI_ENABLED === true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public chat entry point
    // ─────────────────────────────────────────────────────────────────────────

    public static function chat(
        int    $studentId,
        string $academicYear,
        int    $semester,
        string $message,
        array  $history = []
    ): array {
        if (!self::isEnabled()) {
            return ['success' => false, 'error' => 'AI assistant is not enabled.'];
        }

        $system   = self::buildSystemPrompt($studentId, $academicYear, $semester);
        $messages = self::buildMessages($system, $history, $message);

        $payload = [
            'model'       => LM_STUDIO_MODEL,
            'messages'    => $messages,
            'temperature' => 0.7,
            'max_tokens'  => 512,
            'top_p'       => 0.95,
            'stream'      => false,
        ];

        $response = self::httpPost(LM_STUDIO_URL, $payload);

        if (!$response['ok']) {
            $decoded = json_decode($response['body'], true);
            $errMsg  = $decoded['error']['message'] ?? $response['body'];
            $errCode = $decoded['error']['code']    ?? $response['status'];
            error_log("[AI] LM Studio error {$errCode}: {$errMsg}");

            $userMsg = (defined('APP_ENV') && APP_ENV === 'development')
                ? "LM Studio error ({$errCode}): {$errMsg}"
                : 'The AI assistant is unavailable. Make sure LM Studio is running.';

            return ['success' => false, 'error' => $userMsg];
        }

        $data  = json_decode($response['body'], true);
        $reply = $data['choices'][0]['message']['content'] ?? null;

        if ($reply === null) {
            error_log('[AI] Empty response: ' . $response['body']);
            return ['success' => false, 'error' => 'No response received. Please try again.'];
        }

        return ['success' => true, 'reply' => trim($reply)];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // System prompt — packed format to minimise token usage
    // Target: under 800 tokens so small context windows still work
    // ─────────────────────────────────────────────────────────────────────────

    public static function buildSystemPrompt(int $studentId, string $academicYear, int $semester): string
    {
        $student      = self::fetchStudent($studentId);
        $threshold    = (int) self::fetchSetting('attendance_threshold', ATTENDANCE_ALERT_THRESHOLD);
        $disputeHours = (int) self::fetchSetting('dispute_window_hours',  DISPUTE_WINDOW_HOURS);
        $attendance   = self::fetchAttendanceSummary($studentId, $academicYear, $semester);
        $sessionLog   = self::fetchSessionLog($studentId, $academicYear, $semester);
        $marks        = self::fetchMarks($studentId, $academicYear, $semester);
        $gpa          = self::fetchGPA($studentId, $academicYear, $semester);
        $disputes     = self::fetchDisputes($studentId, $disputeHours);
        $school       = defined('SCHOOL_NAME') ? SCHOOL_NAME : 'the institution';
        $phone1       = self::fetchSetting('school_phone_1', defined('SCHOOL_PHONE_1') ? SCHOOL_PHONE_1 : '');
        $phone2       = self::fetchSetting('school_phone_2', defined('SCHOOL_PHONE_2') ? SCHOOL_PHONE_2 : '');
        $schoolEmail  = self::fetchSetting('school_email',   defined('SCHOOL_EMAIL')   ? SCHOOL_EMAIL   : '');

        $lines = [];

        // Identity + rules in one block
        $lines[] = "You are an academic assistant for {$school}. Student: {$student['full_name']} ({$student['reg_number']}), {$academicYear} Sem{$semester}, today " . date('d M Y') . ".";
        $lines[] = "Rules: use only data below; never promise dispute/appeal outcomes; show working for grade math; if student is distressed acknowledge it then give one actionable step; reply in plain conversational English.";
        $lines[] = "Scale: A>=70(4.0) B>=60(3.0) C>=50(2.0,pass) D>=40(1.0) E<40(0.0). Min pass=C. Attendance required={$threshold}%.";

        // School contact info — only emit lines that have a value
        $contactParts = [];
        if (!empty($phone1)) $contactParts[] = "Phone1={$phone1}";
        if (!empty($phone2)) $contactParts[] = "Phone2={$phone2}";
        if (!empty($schoolEmail)) $contactParts[] = "Email={$schoolEmail}";
        if (!empty($contactParts)) {
            $lines[] = "SCHOOL_CONTACT: " . implode(' ', $contactParts) . ". Refer the student here for anything requiring direct staff assistance.";
        }

        // GPA — single line
        if ($gpa) {
            $ul = implode(', ', array_map(
                fn($u) => "{$u['unit_code']}:{$u['weighted_total']}%={$u['grade']}",
                $gpa['units']
            ));
            $lines[] = "GPA={$gpa['gpa']}/4.0({$gpa['count']} units): {$ul}";
        } else {
            $lines[] = "GPA: no graded units yet.";
        }

        // Attendance — one line per unit
        foreach ($attendance as $a) {
            $flag    = (float)$a['attendance_percent'] < $threshold ? '[LOW]' : '';
            $lines[] = "ATT {$a['unit_code']}: {$a['attended']}/{$a['total_sessions']}={$a['attendance_percent']}% {$flag}";
        }

        // Session log — last 15 only, ultra-compact
        $log = array_slice($sessionLog, 0, 15);
        foreach ($log as $s) {
            $m = ['qr_scan' => 'QR', 'manual' => 'M', 'auto_absent' => 'A'][$s['method']] ?? '?';
            $lines[] = "SES {$s['date']} {$s['unit_code']} " . strtoupper(substr($s['status'], 0, 3)) . "/{$m}";
        }

        // Marks — one line per assessment
        $currentUnit = null;
        foreach ($marks as $m) {
            if ($m['unit_code'] !== $currentUnit) {
                $currentUnit = $m['unit_code'];
            }
            if ($m['score'] !== null) {
                $pct    = round(($m['score'] / $m['max_score']) * 100, 1);
                $contrib = round(($m['score'] / $m['max_score']) * $m['weight'], 1);
                $lines[] = "MRK {$m['unit_code']} {$m['name']}[w={$m['weight']}%]: {$m['score']}/{$m['max_score']}={$pct}% contrib={$contrib}%";
            } else {
                $lines[] = "MRK {$m['unit_code']} {$m['name']}[w={$m['weight']}%]: pending";
            }
        }

        // Disputes
        $lines[] = "DISPUTE window={$disputeHours}h after close, absent-only, no guaranteed outcome.";
        foreach ($disputes['eligible'] as $d) {
            $lines[] = "DISPUTE eligible: {$d['unit_code']} {$d['date']} ~{$d['hours_left']}h left";
        }
        foreach ($disputes['pending'] as $d) {
            $lines[] = "DISPUTE pending: {$d['unit_code']} {$d['date']}";
        }

        return implode("\n", $lines);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Build OpenAI-format messages
    // ─────────────────────────────────────────────────────────────────────────

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

    // ─────────────────────────────────────────────────────────────────────────
    // Data fetchers
    // ─────────────────────────────────────────────────────────────────────────

    private static function fetchStudent(int $id): array
    {
        return DB::row("SELECT full_name, reg_number FROM users WHERE id = ?", [$id])
            ?? ['full_name' => 'Student', 'reg_number' => 'N/A'];
    }

    private static function fetchAttendanceSummary(int $studentId, string $year, int $sem): array
    {
        return DB::rows(
            "SELECT unit_code, unit_name, total_sessions, attended, absent, excused, attendance_percent
             FROM vw_attendance_summary
             WHERE student_id = ? AND academic_year = ? AND semester = ?
             ORDER BY unit_code",
            [$studentId, $year, $sem]
        );
    }

    private static function fetchSessionLog(int $studentId, string $year, int $sem): array
    {
        return DB::rows(
            "SELECT DATE(s.started_at) AS date, u.code AS unit_code,
                    al.status, al.method, lec.full_name AS lecturer
             FROM attendance_logs al
             JOIN attendance_sessions s ON s.id  = al.session_id
             JOIN units u               ON u.id  = s.unit_id
             JOIN users lec             ON lec.id = s.lecturer_id
             WHERE al.student_id = ? AND s.academic_year = ? AND s.semester = ?
             ORDER BY s.started_at DESC
             LIMIT 15",
            [$studentId, $year, $sem]
        );
    }

    private static function fetchMarks(int $studentId, string $year, int $sem): array
    {
        return DB::rows(
            "SELECT u.code AS unit_code, u.name AS unit_name,
                    a.name, a.type, a.max_score, a.weight_percent AS weight,
                    a.assessment_date, m.score
             FROM assessments a
             JOIN units u ON u.id = a.unit_id
             JOIN enrollments e ON e.unit_id = u.id
                               AND e.student_id = ?
                               AND e.academic_year = ?
                               AND e.semester = ?
             LEFT JOIN marks m ON m.assessment_id = a.id AND m.student_id = ?
             WHERE a.is_published = 1
             ORDER BY u.code, a.assessment_date",
            [$studentId, $year, $sem, $studentId]
        );
    }

    private static function fetchGPA(int $studentId, string $year, int $sem): ?array
    {
        $rows = DB::rows(
            "SELECT unit_code, unit_name, weighted_total
             FROM vw_unit_grades
             WHERE student_id = ? AND unit_id IN (
                 SELECT unit_id FROM enrollments
                 WHERE student_id = ? AND academic_year = ? AND semester = ?
             )",
            [$studentId, $studentId, $year, $sem]
        );

        if (empty($rows)) return null;

        $boundaries = [
            ['min' => 70, 'grade' => 'A', 'points' => 4.0],
            ['min' => 60, 'grade' => 'B', 'points' => 3.0],
            ['min' => 50, 'grade' => 'C', 'points' => 2.0],
            ['min' => 40, 'grade' => 'D', 'points' => 1.0],
            ['min' =>  0, 'grade' => 'E', 'points' => 0.0],
        ];

        $total = 0.0;
        foreach ($rows as &$r) {
            foreach ($boundaries as $b) {
                if ((float)$r['weighted_total'] >= $b['min']) {
                    $r['grade']  = $b['grade'];
                    $r['points'] = $b['points'];
                    break;
                }
            }
            $total += $r['points'];
        }
        unset($r);

        return ['gpa' => round($total / count($rows), 2), 'count' => count($rows), 'units' => $rows];
    }

    private static function fetchDisputes(int $studentId, int $windowHours): array
    {
        $eligible = DB::rows(
            "SELECT DATE(s.started_at) AS date, u.code AS unit_code,
                    TIMESTAMPDIFF(HOUR, NOW(), DATE_ADD(s.closed_at, INTERVAL ? HOUR)) AS hours_left
             FROM attendance_logs al
             JOIN attendance_sessions s ON s.id = al.session_id
             JOIN units u               ON u.id = s.unit_id
             LEFT JOIN disputes d ON d.session_id = s.id AND d.student_id = al.student_id
             WHERE al.student_id = ? AND al.status = 'absent'
               AND s.closed_at IS NOT NULL
               AND DATE_ADD(s.closed_at, INTERVAL ? HOUR) > NOW()
               AND d.id IS NULL
             ORDER BY s.started_at DESC",
            [$windowHours, $studentId, $windowHours]
        );

        $pending = DB::rows(
            "SELECT DATE(s.started_at) AS date, u.code AS unit_code, d.status
             FROM disputes d
             JOIN attendance_sessions s ON s.id = d.session_id
             JOIN units u               ON u.id = s.unit_id
             WHERE d.student_id = ? AND d.status = 'pending'
             ORDER BY d.created_at DESC",
            [$studentId]
        );

        return ['eligible' => $eligible, 'pending' => $pending];
    }

    private static function fetchSetting(string $key, mixed $default): mixed
    {
        $row = DB::row("SELECT setting_value FROM system_settings WHERE setting_key = ?", [$key]);
        return $row ? $row['setting_value'] : $default;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HTTP
    // ─────────────────────────────────────────────────────────────────────────

    private static function httpPost(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer lm-studio',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING       => '',      // '' = accept any encoding, decompress automatically
        ]);

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log('[AI] cURL error: ' . $error);
            return ['ok' => false, 'status' => 0, 'body' => json_encode(['error' => ['code' => 0, 'message' => $error]])];
        }

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => $body];
    }
}
