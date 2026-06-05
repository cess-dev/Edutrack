<?php
/**
 * EduTrack — Parent AI Service
 *
 * Powers the parent portal chat assistant via LM Studio / Qwen2.5.
 *
 * Action approach: structured output parsing (not function calling).
 * The model embeds ACTION: markers in its response when it needs to
 * write a record. PHP strips the markers, executes the DB actions,
 * and returns clean text to the parent.
 *
 * This is more reliable with local models than the OpenAI tool-calling
 * API, which LM Studio does not always pass through correctly.
 *
 * Marker format (model writes these on their own line):
 *   ACTION:report_absence:STUDENT_ID:YYYY-MM-DD:reason text
 *   ACTION:log_incident:TYPE:description text
 */

if (!defined('EDUTRACK_LOADED')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

class ParentAIService
{
    public static function chat(
        int    $parentId,
        string $message,
        array  $history = []
    ): array {
        if (!defined('AI_ENABLED') || !AI_ENABLED) {
            return ['success' => false, 'error' => 'AI assistant is not enabled.'];
        }

        $system   = self::buildSystemPrompt($parentId);
        $messages = self::buildMessages($system, $history, $message);

        $payload = [
            'model'       => LM_STUDIO_MODEL,
            'messages'    => $messages,
            'temperature' => 0.6,
            'max_tokens'  => 600,
            'stream'      => false,
        ];

        $response = self::httpPost(LM_STUDIO_URL, $payload);

        if (!$response['ok']) {
            $decoded = json_decode($response['body'], true);
            $errMsg  = $decoded['error']['message'] ?? $response['body'];
            $errCode = $decoded['error']['code']    ?? $response['status'];
            error_log("[ParentAI] Error {$errCode}: {$errMsg}");

            $userMsg = (defined('APP_ENV') && APP_ENV === 'development')
                ? "AI error ({$errCode}): {$errMsg}"
                : 'The assistant is temporarily unavailable. Please try again.';

            return ['success' => false, 'error' => $userMsg];
        }

        $data  = json_decode($response['body'], true);
        $raw   = $data['choices'][0]['message']['content'] ?? null;

        if ($raw === null) {
            error_log('[ParentAI] Empty response: ' . $response['body']);
            return ['success' => false, 'error' => 'No response received. Please try again.'];
        }

        // Parse and execute any ACTION markers embedded in the response
        [$cleanReply, $actions] = self::parseAndExecute(trim($raw), $parentId);

        return ['success' => true, 'reply' => $cleanReply, 'actions' => $actions];
    }

    // ── Action parsing ────────────────────────────────────────────────────────
    // Scans the model's reply for ACTION: lines, executes them, removes them

    private static function parseAndExecute(string $raw, int $parentId): array
    {
        $lines   = explode("\n", $raw);
        $clean   = [];
        $actions = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, 'ACTION:report_absence:')) {
                $parts = explode(':', $trimmed, 5);
                // ACTION : report_absence : student_id : date : reason
                $studentId = (int) ($parts[2] ?? 0);
                $date      = trim($parts[3] ?? date('Y-m-d'));
                $reason    = trim($parts[4] ?? 'unspecified');

                $result    = self::executeReportAbsence($parentId, $studentId, $date, $reason);
                $actions[] = $result;
                // Do not add this line to clean output

            } elseif (str_starts_with($trimmed, 'ACTION:log_incident:')) {
                $parts = explode(':', $trimmed, 4);
                // ACTION : log_incident : type : description
                $type        = trim($parts[2] ?? 'general_concern');
                $description = trim($parts[3] ?? '');

                $result    = self::executeLogIncident($parentId, $type, $description);
                $actions[] = $result;

            } else {
                $clean[] = $line;
            }
        }

        return [trim(implode("\n", $clean)), $actions];
    }

    // ── DB actions ────────────────────────────────────────────────────────────

    private static function executeReportAbsence(
        int    $parentId,
        int    $studentId,
        string $date,
        string $reason
    ): array {
        $link = DB::row(
            "SELECT u.full_name FROM parent_student_links psl
             JOIN users u ON u.id = psl.student_id
             WHERE psl.parent_id = ? AND psl.student_id = ?",
            [$parentId, $studentId]
        );

        if (!$link) {
            return ['success' => false, 'message' => 'Child not linked to this account.'];
        }

        $existing = DB::row(
            "SELECT id FROM parent_absence_reports WHERE student_id = ? AND report_date = ?",
            [$studentId, $date]
        );

        if ($existing) {
            return [
                'success'        => true,
                'already_logged' => true,
                'student_name'   => $link['full_name'],
                'date'           => $date,
            ];
        }

        DB::insert(
            "INSERT INTO parent_absence_reports (parent_id, student_id, report_date, reason)
             VALUES (?, ?, ?, ?)",
            [$parentId, $studentId, $date, $reason]
        );

        $sessions = DB::rows(
            "SELECT u.code AS unit_code, TIME_FORMAT(s.started_at,'%H:%i') AS time
             FROM attendance_sessions s
             JOIN units u  ON u.id = s.unit_id
             JOIN enrollments e ON e.unit_id = u.id AND e.student_id = ?
             WHERE DATE(s.started_at) = ?
             ORDER BY s.started_at",
            [$studentId, $date]
        );

        $sessionList = empty($sessions)
            ? 'no sessions found today'
            : implode(', ', array_map(fn($s) => "{$s['unit_code']} at {$s['time']}", $sessions));

        return [
            'success'      => true,
            'student_name' => $link['full_name'],
            'date'         => $date,
            'reason'       => $reason,
            'sessions'     => $sessionList,
        ];
    }

    private static function executeLogIncident(
        int    $parentId,
        string $type,
        string $description
    ): array {
        $validTypes = ['missing_child','medical_emergency','bullying','staff_complaint','general_concern'];
        if (!in_array($type, $validTypes, true)) $type = 'general_concern';

        DB::insert(
            "INSERT INTO parent_ai_incidents (parent_id, type, description) VALUES (?, ?, ?)",
            [$parentId, $type, $description]
        );

        $urgency = in_array($type, ['missing_child','medical_emergency'], true)
            ? 'URGENT — flagged for immediate admin review'
            : 'flagged for admin review';

        return ['success' => true, 'type' => $type, 'urgency' => $urgency];
    }

    // ── System prompt ─────────────────────────────────────────────────────────

    public static function buildSystemPrompt(int $parentId): string
    {
        $parent     = self::fetchParent($parentId);
        $children   = self::fetchChildren($parentId);
        $knowledge  = self::fetchKnowledge();
        $school     = defined('SCHOOL_NAME') ? SCHOOL_NAME : 'the school';
        $today      = date('Y-m-d');
        $todayLabel = date('d M Y, l');
        $phone1     = self::fetchContact('school_phone_1', defined('SCHOOL_PHONE_1') ? SCHOOL_PHONE_1 : '');
        $phone2     = self::fetchContact('school_phone_2', defined('SCHOOL_PHONE_2') ? SCHOOL_PHONE_2 : '');
        $schoolEmail = self::fetchContact('school_email',  defined('SCHOOL_EMAIL')   ? SCHOOL_EMAIL   : '');

        $lines = [];

        $lines[] = "You are the parent support assistant for {$school}. You are speaking with {$parent['full_name']}.";
        $lines[] = "Today: {$todayLabel}.";
        $lines[] = '';
        $lines[] = "TONE: Stay calm and warm. Acknowledge feelings before giving information. Never dismiss concerns. Never argue.";
        $lines[] = "LANGUAGE: If the parent writes in Kiswahili, respond in Kiswahili. Otherwise respond in English.";
        $lines[] = '';
        // Build contact string for use in rules
        $contactParts = [];
        if (!empty($phone1)) $contactParts[] = $phone1;
        if (!empty($phone2)) $contactParts[] = $phone2;
        if (!empty($schoolEmail)) $contactParts[] = $schoolEmail;
        $contactLine = !empty($contactParts)
            ? implode(' or ', $contactParts)
            : 'the school office';

        $lines[] = "RULES:";
        $lines[] = "1. CRITICAL: If a specific fact (date, amount, deadline) is NOT in SCHOOL INFO below, say exactly: \"I don't have that information on record. Please contact the school office directly.\" Never guess or use placeholders.";
        $lines[] = "2. Do NOT answer about specific marks, grades, or teacher decisions.";
        $lines[] = "3. Do NOT promise callbacks or appointments on behalf of staff.";
        $lines[] = "4. Do NOT handle fees or payments.";
        $lines[] = "5. End every response with: \"Need to speak to someone directly? Contact us: {$contactLine}.\"";
        $lines[] = '';
        if (!empty($contactParts)) {
            $lines[] = "SCHOOL_CONTACT: " . implode(' | ', $contactParts);
        }
        $lines[] = '';
        $lines[] = "ACTIONS — when you need to record something, include ONE of these lines BEFORE your response text:";
        $lines[] = "  To report absence: ACTION:report_absence:STUDENT_ID:{$today}:reason";
        $lines[] = "  To escalate concern: ACTION:log_incident:TYPE:description";
        $lines[] = "  Valid incident types: missing_child, medical_emergency, bullying, staff_complaint, general_concern";
        $lines[] = "  Use escalation (log_incident) for: missing child, bullying, medical emergency, staff complaint, or any message with 'I can\\'t find', 'hasn\\'t arrived', 'I am very worried'.";
        $lines[] = "  Only include one ACTION line per response. Do not include the ACTION line in the text you show the parent.";
        $lines[] = '';

        // Children
        if (empty($children)) {
            $lines[] = "CHILDREN: No linked children found.";
        } else {
            foreach ($children as $c) {
                $lines[] = "CHILD: {$c['full_name']} ID={$c['student_id']} Reg={$c['reg_number']} ({$c['relationship']})";
                foreach ($c['attendance'] as $a) {
                    $flag    = (float)$a['attendance_percent'] < 75 ? '[LOW]' : '';
                    $lines[] = "  ATT {$a['unit_code']}: {$a['attended']}/{$a['total_sessions']}={$a['attendance_percent']}% {$flag}";
                }
                foreach ($c['recent_absences'] as $ab) {
                    $lines[] = "  ABSENT {$ab['date']} {$ab['unit_code']}";
                }
                if (!empty($c['today_sessions'])) {
                    $s = implode(', ', array_map(fn($s) => "{$s['unit_code']}@{$s['time']}", $c['today_sessions']));
                    $lines[] = "  TODAY_SESSIONS: {$s}";
                }
                if ($c['reported_today']) {
                    $lines[] = "  NOTE: absence already reported today.";
                }
            }
        }
        $lines[] = '';

        // School knowledge (always open the block; contact info is always injected first)
        $lines[] = "SCHOOL INFO (answer ONLY from this list):";
        if (!empty($contactParts)) {
            $contactAnswer = implode(', ', $contactParts);
            $lines[] = "  Q: What is the school contact / phone number / email? A: {$contactAnswer}";
            $lines[] = "  Q: How can I reach the school? A: Contact us at {$contactAnswer}";
            $lines[] = "  Q: Share school contacts. A: {$contactAnswer}";
        }
        foreach ($knowledge as $k) {
            $lines[] = "  Q: {$k['question']} A: {$k['answer']}";
        }

        return implode("\n", $lines);
    }

    // ── Data fetchers ─────────────────────────────────────────────────────────

    private static function fetchParent(int $parentId): array
    {
        return DB::row("SELECT full_name FROM users WHERE id = ?", [$parentId])
            ?? ['full_name' => 'Parent'];
    }

    private static function fetchChildren(int $parentId): array
    {
        $links = DB::rows(
            "SELECT psl.student_id, psl.relationship, u.full_name, u.reg_number
             FROM parent_student_links psl
             JOIN users u ON u.id = psl.student_id
             WHERE psl.parent_id = ?
             ORDER BY u.full_name",
            [$parentId]
        );

        $today        = date('Y-m-d');
        $academicYear = DB::row("SELECT setting_value FROM system_settings WHERE setting_key='academic_year'")['setting_value'] ?? ACADEMIC_YEAR;
        $semester     = (int)(DB::row("SELECT setting_value FROM system_settings WHERE setting_key='active_semester'")['setting_value'] ?? ACTIVE_SEMESTER);

        $enriched = [];
        foreach ($links as $link) {
            $sid = (int) $link['student_id'];

            $attendance = DB::rows(
                "SELECT unit_code, attended, total_sessions, attendance_percent
                 FROM vw_attendance_summary
                 WHERE student_id=? AND academic_year=? AND semester=?
                 ORDER BY unit_code",
                [$sid, $academicYear, $semester]
            );

            $recentAbsences = DB::rows(
                "SELECT DATE(s.started_at) AS date, u.code AS unit_code
                 FROM attendance_logs al
                 JOIN attendance_sessions s ON s.id = al.session_id
                 JOIN units u               ON u.id = s.unit_id
                 WHERE al.student_id=? AND al.status='absent'
                 ORDER BY s.started_at DESC LIMIT 5",
                [$sid]
            );

            $todaySessions = DB::rows(
                "SELECT u.code AS unit_code, TIME_FORMAT(s.started_at,'%H:%i') AS time
                 FROM attendance_sessions s
                 JOIN units u ON u.id = s.unit_id
                 JOIN enrollments e ON e.unit_id=u.id AND e.student_id=?
                 WHERE DATE(s.started_at)=? AND s.is_active IN (0,1)
                 ORDER BY s.started_at",
                [$sid, $today]
            );

            $reportedToday = (bool) DB::row(
                "SELECT id FROM parent_absence_reports WHERE student_id=? AND report_date=?",
                [$sid, $today]
            );

            $enriched[] = array_merge($link, [
                'attendance'      => $attendance,
                'recent_absences' => $recentAbsences,
                'today_sessions'  => $todaySessions,
                'reported_today'  => $reportedToday,
            ]);
        }

        return $enriched;
    }

    private static function fetchKnowledge(): array
    {
        return DB::rows(
            "SELECT question, answer FROM school_knowledge WHERE is_active=1 ORDER BY category, id"
        );
    }

    private static function fetchContact(string $key, string $default): string
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
            CURLOPT_ENCODING       => '',
        ]);

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log('[ParentAI] cURL: ' . $error);
            return ['ok' => false, 'status' => 0, 'body' => json_encode(['error' => ['message' => $error]])];
        }

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => $body];
    }
}
