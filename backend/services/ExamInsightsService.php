<?php
/**
 * EduTrack — Exam Insights AI Service
 *
 * Analyses exam PDFs with lecturer annotations via LM Studio.
 * Produces structured suggestions that can be tracked over time.
 */

if (!defined('EDUTRACK_LOADED')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

require_once __DIR__ . '/TimetableVisionService.php';

class ExamInsightsService
{
    public static function extractPdfText(string $filePath): ?string
    {
        $ref = new ReflectionMethod('TimetableVisionService', 'phpPdfExtract');
        $ref->setAccessible(true);
        return $ref->invoke(null, $filePath);
    }

    public static function analyzeExam(int $uploadId): array
    {
        if (!defined('AI_ENABLED') || !AI_ENABLED
            || !defined('LM_STUDIO_URL') || LM_STUDIO_URL === '') {
            return ['success' => false, 'error' => 'AI is not configured.'];
        }

        $upload = DB::row("SELECT * FROM exam_uploads WHERE id = ?", [$uploadId]);
        if (!$upload) {
            return ['success' => false, 'error' => 'Upload not found.'];
        }

        DB::execute("UPDATE exam_uploads SET analysis_status = 'analyzing' WHERE id = ?", [$uploadId]);

        $unit = DB::row("SELECT code, name FROM units WHERE id = ?", [$upload['unit_id']]);
        $school = DB::row("SELECT setting_value FROM system_settings WHERE setting_key = 'school_name'")['setting_value'] ?? 'the institution';

        $history = self::fetchHistory($upload['unit_id'], $uploadId);
        $marksData = self::fetchMarksData($upload['unit_id'], $upload['academic_year'], (int)$upload['semester']);

        $system = self::buildAnalysisPrompt($upload, $unit, $school, $history, $marksData);

        $payload = [
            'model'       => LM_STUDIO_MODEL,
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => 'Analyze this exam and provide your suggestions in the JSON format specified.'],
            ],
            'temperature' => 0.4,
            'max_tokens'  => 1500,
            'stream'      => false,
        ];

        $response = self::httpPost(LM_STUDIO_URL, $payload);

        if (!$response['ok']) {
            DB::execute("UPDATE exam_uploads SET analysis_status = 'failed' WHERE id = ?", [$uploadId]);
            $decoded = json_decode($response['body'], true);
            $errMsg = $decoded['error']['message'] ?? $response['body'];
            error_log("[ExamInsights] AI error: {$errMsg}");
            return ['success' => false, 'error' => "AI error: {$errMsg}"];
        }

        $data    = json_decode($response['body'], true);
        $content = trim($data['choices'][0]['message']['content'] ?? '');

        $parsed = self::parseAiOutput($content);
        if (!$parsed) {
            DB::execute("UPDATE exam_uploads SET analysis_status = 'failed' WHERE id = ?", [$uploadId]);
            return ['success' => false, 'error' => 'Could not parse AI response. Please try again.'];
        }

        $insightId = (int)DB::insert(
            "INSERT INTO exam_insights (upload_id, ai_summary, ai_suggestions, ai_comparisons, model_used)
             VALUES (?, ?, ?, ?, ?)",
            [
                $uploadId,
                $parsed['summary'],
                json_encode($parsed['suggestions']),
                $parsed['comparisons'] ? json_encode($parsed['comparisons']) : null,
                LM_STUDIO_MODEL,
            ]
        );

        foreach ($parsed['suggestions'] as $s) {
            DB::insert(
                "INSERT INTO exam_suggestions (insight_id, suggestion_text, category) VALUES (?, ?, ?)",
                [$insightId, $s['text'], $s['category'] ?? 'other']
            );
        }

        DB::execute(
            "UPDATE exam_uploads SET analysis_status = 'completed', analyzed_at = NOW() WHERE id = ?",
            [$uploadId]
        );

        $suggestions = DB::rows("SELECT * FROM exam_suggestions WHERE insight_id = ?", [$insightId]);

        return [
            'success' => true,
            'insight' => [
                'id'          => $insightId,
                'summary'     => $parsed['summary'],
                'suggestions' => $suggestions,
                'comparisons' => $parsed['comparisons'],
                'future_ideas' => $parsed['future_ideas'] ?? null,
            ],
        ];
    }

    public static function compareExams(int $unitId, int $idA, int $idB): array
    {
        if (!defined('AI_ENABLED') || !AI_ENABLED) {
            return ['success' => false, 'error' => 'AI is not configured.'];
        }

        $a = DB::row(
            "SELECT eu.*, ei.ai_summary, ei.ai_suggestions
             FROM exam_uploads eu LEFT JOIN exam_insights ei ON ei.upload_id = eu.id
             WHERE eu.id = ? AND eu.unit_id = ?", [$idA, $unitId]
        );
        $b = DB::row(
            "SELECT eu.*, ei.ai_summary, ei.ai_suggestions
             FROM exam_uploads eu LEFT JOIN exam_insights ei ON ei.upload_id = eu.id
             WHERE eu.id = ? AND eu.unit_id = ?", [$idB, $unitId]
        );

        if (!$a || !$b) {
            return ['success' => false, 'error' => 'One or both uploads not found.'];
        }

        $unit = DB::row("SELECT code, name FROM units WHERE id = ?", [$unitId]);

        $executedA = DB::rows(
            "SELECT es.suggestion_text, es.follow_up_notes FROM exam_suggestions es
             JOIN exam_insights ei ON ei.id = es.insight_id
             WHERE ei.upload_id = ? AND es.status = 'executed'", [$idA]
        );

        $prompt = "You are an exam analysis assistant. Compare these two exams for {$unit['code']} {$unit['name']}.\n\n";
        $prompt .= "EXAM A: \"{$a['exam_title']}\" ({$a['uploaded_at']})\n";
        $prompt .= "  Avg score: " . ($a['avg_score'] ?? 'N/A') . "%\n";
        $prompt .= "  Failed topics: " . ($a['most_failed'] ?? 'N/A') . "\n";
        $prompt .= "  AI summary: " . ($a['ai_summary'] ?? 'N/A') . "\n";
        if (!empty($executedA)) {
            $prompt .= "  Executed suggestions:\n";
            foreach ($executedA as $s) $prompt .= "    - {$s['suggestion_text']}" . ($s['follow_up_notes'] ? " (outcome: {$s['follow_up_notes']})" : '') . "\n";
        }
        $prompt .= "\nEXAM B: \"{$b['exam_title']}\" ({$b['uploaded_at']})\n";
        $prompt .= "  Avg score: " . ($b['avg_score'] ?? 'N/A') . "%\n";
        $prompt .= "  Failed topics: " . ($b['most_failed'] ?? 'N/A') . "\n";
        $prompt .= "  AI summary: " . ($b['ai_summary'] ?? 'N/A') . "\n";
        $prompt .= "\nProvide a comparison: did scores improve? Did the executed suggestions help? What should change next? Reply in 2-3 paragraphs.";

        $payload = [
            'model'       => LM_STUDIO_MODEL,
            'messages'    => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.4,
            'max_tokens'  => 800,
            'stream'      => false,
        ];

        $response = self::httpPost(LM_STUDIO_URL, $payload);
        if (!$response['ok']) {
            return ['success' => false, 'error' => 'AI comparison failed.'];
        }

        $reply = trim(json_decode($response['body'], true)['choices'][0]['message']['content'] ?? '');
        return ['success' => true, 'comparison' => $reply];
    }

    // ── Prompt builders ──────────────────────────────────────────────────────

    private static function buildAnalysisPrompt(array $upload, ?array $unit, string $school, array $history, ?array $marks): string
    {
        $lines = [];
        $lines[] = "You are an exam analysis assistant for {$school}. Help the lecturer improve student outcomes.";
        $lines[] = "Unit: " . ($unit['code'] ?? '?') . " " . ($unit['name'] ?? '');
        $lines[] = "Exam: {$upload['exam_title']}";
        if ($upload['avg_score'] !== null) $lines[] = "Average score: {$upload['avg_score']}%";
        if ($upload['most_failed']) $lines[] = "Most failed questions/topics: {$upload['most_failed']}";
        if ($upload['observations']) $lines[] = "Lecturer observations: {$upload['observations']}";

        if ($upload['extracted_text']) {
            $text = substr($upload['extracted_text'], 0, 3000);
            $lines[] = "\nEXAM TEXT (extracted from PDF):\n{$text}";
        }

        if ($marks) {
            $lines[] = "\nACTUAL MARKS DATA:";
            $lines[] = "  Students: {$marks['count']}, Mean: {$marks['mean']}%, Median: {$marks['median']}%, Min: {$marks['min']}%, Max: {$marks['max']}%";
        }

        if (!empty($history)) {
            $lines[] = "\nHISTORICAL EXAMS (same unit):";
            foreach ($history as $h) {
                $lines[] = "- \"{$h['exam_title']}\" ({$h['uploaded_at']}): avg {$h['avg_score']}%, issues: " . ($h['most_failed'] ?? 'N/A');
                if ($h['ai_summary']) $lines[] = "  AI said: " . substr($h['ai_summary'], 0, 200);
                if (!empty($h['executed'])) {
                    $lines[] = "  Executed: " . implode('; ', array_column($h['executed'], 'suggestion_text'));
                }
            }
        }

        $lines[] = "\nReturn ONLY a valid JSON object with these fields:";
        $lines[] = '{';
        $lines[] = '  "summary": "2-3 sentence overall analysis",';
        $lines[] = '  "suggestions": [{"text": "actionable suggestion", "category": "teaching|assessment|content|student_support|other"}],';
        $lines[] = '  "comparisons": "comparison with previous exams or null if no history",';
        $lines[] = '  "future_ideas": "1-2 forward-looking implementation ideas"';
        $lines[] = '}';

        return implode("\n", $lines);
    }

    // ── Data fetchers ────────────────────────────────────────────────────────

    private static function fetchHistory(int $unitId, int $excludeId): array
    {
        $rows = DB::rows(
            "SELECT eu.id, eu.exam_title, eu.avg_score, eu.most_failed, eu.uploaded_at,
                    ei.ai_summary
             FROM exam_uploads eu
             LEFT JOIN exam_insights ei ON ei.upload_id = eu.id
             WHERE eu.unit_id = ? AND eu.id != ? AND eu.analysis_status = 'completed'
             ORDER BY eu.uploaded_at DESC LIMIT 5",
            [$unitId, $excludeId]
        );

        foreach ($rows as &$r) {
            $r['executed'] = DB::rows(
                "SELECT es.suggestion_text FROM exam_suggestions es
                 JOIN exam_insights ei ON ei.id = es.insight_id
                 WHERE ei.upload_id = ? AND es.status = 'executed'",
                [$r['id']]
            );
        }
        unset($r);

        return $rows;
    }

    private static function fetchMarksData(int $unitId, string $year, int $sem): ?array
    {
        $scores = DB::rows(
            "SELECT m.score, a.max_score
             FROM marks m
             JOIN assessments a ON a.id = m.assessment_id
             JOIN enrollments e ON e.unit_id = a.unit_id AND e.student_id = m.student_id
                                AND e.academic_year = ? AND e.semester = ?
             WHERE a.unit_id = ? AND a.is_published = 1 AND m.score IS NOT NULL",
            [$year, $sem, $unitId]
        );

        if (empty($scores)) return null;

        $pcts = array_map(fn($s) => round(($s['score'] / max($s['max_score'], 1)) * 100, 1), $scores);
        sort($pcts);
        $count = count($pcts);

        return [
            'count'  => $count,
            'mean'   => round(array_sum($pcts) / $count, 1),
            'median' => round($pcts[intdiv($count, 2)], 1),
            'min'    => round(min($pcts), 1),
            'max'    => round(max($pcts), 1),
        ];
    }

    private static function parseAiOutput(string $content): ?array
    {
        $content = preg_replace('/^```(?:json)?\s*/m', '', $content);
        $content = preg_replace('/```\s*$/m', '', $content);
        $content = trim($content);

        if (preg_match('/\{[\s\S]*\}/m', $content, $m)) {
            $content = $m[0];
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded) || empty($decoded['summary'])) {
            error_log('[ExamInsights] Failed to parse: ' . substr($content, 0, 300));
            return null;
        }

        $suggestions = [];
        foreach ($decoded['suggestions'] ?? [] as $s) {
            if (is_string($s)) {
                $suggestions[] = ['text' => $s, 'category' => 'other'];
            } elseif (is_array($s) && !empty($s['text'])) {
                $cat = $s['category'] ?? 'other';
                if (!in_array($cat, ['teaching', 'assessment', 'content', 'student_support', 'other'])) $cat = 'other';
                $suggestions[] = ['text' => $s['text'], 'category' => $cat];
            }
        }

        return [
            'summary'      => $decoded['summary'],
            'suggestions'  => $suggestions,
            'comparisons'  => $decoded['comparisons'] ?? null,
            'future_ideas' => $decoded['future_ideas'] ?? null,
        ];
    }

    // ── HTTP ─────────────────────────────────────────────────────────────────

    private static function httpPost(string $url, array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return ['ok' => false, 'status' => 0, 'body' => json_encode(['error' => ['message' => 'JSON encode failed']])];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . LM_STUDIO_API_KEY,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING       => '',
        ]);

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log('[ExamInsights] cURL error: ' . $error);
            return ['ok' => false, 'status' => 0, 'body' => json_encode(['error' => ['message' => $error]])];
        }

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => $body];
    }
}
