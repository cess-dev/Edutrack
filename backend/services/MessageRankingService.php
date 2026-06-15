<?php
/**
 * EduTrack — Message Ranking Service
 *
 * Uses LM Studio to assign urgency scores (1–5) to unranked parent messages.
 * Called from the admin messages page when unranked messages exist.
 *
 * Urgency scale:
 *   1 — Critical      (missing child, death, crime report, safety emergency)
 *   2 — Illness       (student is sick, injury, medical concern)
 *   3 — Misconduct    (reporting lecturer or staff misconduct)
 *   4 — General query (open dates, fee amounts, schedules, admissions)
 *   5 — No reply needed (thank-you messages, service ratings, compliments)
 *
 * Output format expected from the model (one line per message):
 *   ID:42=3
 *   ID:7=1
 * Any line that does not match is skipped; those messages keep urgency=NULL
 * and will be re-attempted on the next page load.
 */

if (!defined('EDUTRACK_LOADED')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

class MessageRankingService
{
    private const BATCH_SIZE = 12; // messages per LLM call

    /**
     * Rank all messages with urgency = NULL.
     * Returns an array of [id => urgency] pairs that were updated.
     */
    public static function rankPending(): array
    {
        if (!defined('AI_ENABLED') || !AI_ENABLED) {
            return [];
        }

        $unranked = DB::rows(
            "SELECT m.id, m.subject, m.body, u.full_name AS parent_name
             FROM parent_messages m
             JOIN users u ON u.id = m.parent_id
             WHERE m.urgency IS NULL
             ORDER BY m.created_at ASC",
            []
        );

        if (empty($unranked)) {
            return [];
        }

        // Load active autoreply templates once for all batches
        $templates = DB::rows(
            "SELECT id, title, description, reply_body
             FROM autoreply_templates
             WHERE is_active = 1
             ORDER BY id ASC",
            []
        );

        $updated = [];

        foreach (array_chunk($unranked, self::BATCH_SIZE) as $batch) {
            $results = self::rankBatch($batch, $templates);

            foreach ($results as $id => $result) {
                DB::execute(
                    "UPDATE parent_messages SET urgency = ? WHERE id = ? AND urgency IS NULL",
                    [$result['urgency'], $id]
                );
                $updated[$id] = $result['urgency'];

                // Insert autoreply if the AI matched a template and no reply exists yet
                if (!empty($result['template_id'])) {
                    $tpl = self::findTemplate($templates, $result['template_id']);
                    if ($tpl) {
                        $alreadyReplied = DB::row(
                            "SELECT id FROM parent_message_replies WHERE message_id = ?",
                            [$id]
                        );
                        if (!$alreadyReplied) {
                            DB::insert(
                                "INSERT INTO parent_message_replies (message_id, template_id, reply_body)
                                 VALUES (?, ?, ?)",
                                [$id, $tpl['id'], $tpl['reply_body']]
                            );
                            // Mark the message as read since it's been auto-handled
                            DB::execute(
                                "UPDATE parent_messages SET status = 'read', read_at = NOW()
                                 WHERE id = ? AND status = 'unread'",
                                [$id]
                            );
                        }
                    }
                }
            }
        }

        return $updated;
    }

    /**
     * Rank a single just-submitted message and send an autoreply if matched.
     * Called synchronously from the send_message API so the reply is ready
     * before the parent's page reloads.
     */
    public static function rankAndReply(int $messageId): void
    {
        if (!defined('AI_ENABLED') || !AI_ENABLED) return;

        $message = DB::row(
            "SELECT m.id, m.subject, m.body, u.full_name AS parent_name
             FROM parent_messages m
             JOIN users u ON u.id = m.parent_id
             WHERE m.id = ? AND m.urgency IS NULL",
            [$messageId]
        );

        if (!$message) return;

        $templates = DB::rows(
            "SELECT id, title, description, reply_body
             FROM autoreply_templates WHERE is_active = 1 ORDER BY id ASC",
            []
        );

        // ── Step 1: LLM ranking ───────────────────────────────────────────────
        $results    = self::rankBatch([$message], $templates);
        $result     = $results[$messageId] ?? null;
        $urgency    = $result['urgency']     ?? null;
        $templateId = $result['template_id'] ?? null;

        error_log("[MessageRanking] msg={$messageId} urgency={$urgency} llm_template={$templateId}");

        // ── Step 2: keyword fallback if LLM didn't attach a template ─────────
        // Runs when: LLM succeeded with score 4 but no REPLY, OR LLM failed entirely.
        if ($templateId === null && !empty($templates)) {
            $templateId = self::keywordMatch($message, $templates);
            if ($templateId !== null) {
                error_log("[MessageRanking] msg={$messageId} keyword_match={$templateId}");
                // If the LLM didn't score at all, treat a keyword match as a general query
                if ($urgency === null) {
                    $urgency = 4;
                }
            }
        }

        // ── Step 3: persist urgency ───────────────────────────────────────────
        if ($urgency !== null) {
            DB::execute(
                "UPDATE parent_messages SET urgency = ? WHERE id = ?",
                [$urgency, $messageId]
            );
        }

        // ── Step 4: insert autoreply if a template was matched ─────────────────
        if ($templateId !== null) {
            $tpl = self::findTemplate($templates, $templateId);
            if ($tpl) {
                DB::insert(
                    "INSERT INTO parent_message_replies (message_id, template_id, reply_body)
                     VALUES (?, ?, ?)",
                    [$messageId, $tpl['id'], $tpl['reply_body']]
                );
                DB::execute(
                    "UPDATE parent_messages SET status = 'read', read_at = NOW()
                     WHERE id = ? AND status = 'unread'",
                    [$messageId]
                );
            }
        }
    }

    /**
     * Simple word-overlap fallback when the LLM doesn't attach a REPLY marker.
     * Tokenises the message subject+body and each template's title+description,
     * then returns the template ID with the highest word overlap (min 1 match).
     */
    private static function keywordMatch(array $message, array $templates): ?int
    {
        $stop = ['this','that','with','from','they','their','there','when','what',
                 'where','which','about','have','will','been','does','school',
                 'want','know','just','also','more','some','your','please','hello',
                 'dear','good','morning','afternoon','would','like','need','help'];

        $msgText  = strtolower($message['subject'] . ' ' . $message['body']);
        $msgWords = array_diff(
            array_filter(array_unique(preg_split('/\W+/', $msgText)), fn($w) => strlen($w) > 3),
            $stop
        );

        $bestCount = 0;
        $bestId    = null;

        foreach ($templates as $t) {
            $tplText  = strtolower($t['title'] . ' ' . $t['description']);
            $tplWords = array_diff(
                array_filter(array_unique(preg_split('/\W+/', $tplText)), fn($w) => strlen($w) > 3),
                $stop
            );

            $overlap = count(array_intersect($msgWords, $tplWords));
            if ($overlap > $bestCount) {
                $bestCount = $overlap;
                $bestId    = (int) $t['id'];
            }
        }

        return ($bestCount >= 1) ? $bestId : null;
    }

    private static function findTemplate(array $templates, int $id): ?array
    {
        foreach ($templates as $t) {
            if ((int)$t['id'] === $id) return $t;
        }
        return null;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Ask the LLM to rank one batch of messages and optionally match autoreply templates.
     * Returns [id => ['urgency' => int, 'template_id' => int|null]]
     */
    private static function rankBatch(array $messages, array $templates): array
    {
        $templateSection = '';
        if (!empty($templates)) {
            $tLines = [];
            foreach ($templates as $t) {
                $tLines[] = "T{$t['id']}: {$t['title']} — {$t['description']}";
            }
            $templateSection = "\n\nAUTOREPLY TEMPLATES — check every score-4 message against these topics. "
                . "If the message is asking about any of them, you MUST append \" REPLY:T<id>\" on the same line. "
                . "Do not add REPLY to scores 1, 2, 3, or 5.\n"
                . implode("\n", $tLines);
        }

        $system = "You are a school administration triage assistant. "
            . "Your only job is to assign an urgency score to parent messages.\n\n"
            . "Urgency scale — use exactly these categories:\n"
            . "1 = Critical       (missing child, death in the family, crime report, safety emergency, child not arrived home, threat to life)\n"
            . "2 = Illness        (student is sick, injured, hospital visit, medical condition, health concern)\n"
            . "3 = Misconduct     (reporting a lecturer or staff member, inappropriate behaviour, abuse, unfair treatment by staff)\n"
            . "4 = General query  (any informational question the school can answer: open days, fee amounts, school schedules, "
            .                     "admission dates, academic calendar, academic trips, excursions, school events, uniforms, "
            .                     "transport, timetables, holidays, registration, results, clubs, facilities — if in doubt use 4)\n"
            . "5 = No reply needed (thank-you messages, rating school services, compliments, general praise, no question asked)\n\n"
            . "Rules:\n"
            . "- Scores 1, 2, and 3 are for serious issues only — do not over-use them.\n"
            . "- Use score 4 for ANY general question about school operations, events, or information.\n"
            . "- Score 5 only when the message clearly requires no action from the school."
            . $templateSection . "\n\n"
            . "Reply with ONLY lines in this exact format — one per message, nothing else:\n"
            . "ID:<number>=<score>\n"
            . "or with autoreply:\n"
            . "ID:<number>=<score> REPLY:T<template_id>\n\n"
            . "Example output:\n"
            . "ID:3=2\n"
            . "ID:7=4 REPLY:T1\n"
            . "ID:12=1";

        $msgLines = [];
        foreach ($messages as $m) {
            $subject    = self::truncate($m['subject'], 120);
            $body       = self::truncate($m['body'],    300);
            $msgLines[] = "ID:{$m['id']} From: {$m['parent_name']} | Subject: \"{$subject}\" | Message: \"{$body}\"";
        }

        $userPrompt = "Rank each of these " . count($messages) . " messages:\n\n" . implode("\n", $msgLines);

        $payload = [
            'model'       => LM_STUDIO_MODEL,
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $userPrompt],
            ],
            'temperature' => 0.1,
            'max_tokens'  => count($messages) * 20 + 30,
            'stream'      => false,
        ];

        $response = self::httpPost(LM_STUDIO_URL, $payload);

        if (!$response['ok']) {
            error_log('[MessageRanking] LLM error: ' . $response['body']);
            return [];
        }

        $data    = json_decode($response['body'], true);
        $rawText = trim($data['choices'][0]['message']['content'] ?? '');

        return self::parseRankings($rawText, $messages);
    }

    /**
     * Parse lines like "ID:42=3" or "ID:42=4 REPLY:T2".
     * Returns [id => ['urgency' => int, 'template_id' => int|null]]
     * Only accepts IDs that were actually in the batch (prevents hallucinations).
     */
    private static function parseRankings(string $raw, array $batch): array
    {
        $validIds = array_column($batch, 'id');
        $result   = [];

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if (preg_match('/^ID:(\d+)=([1-5])(?:\s+REPLY:T(\d+))?$/', $line, $m)) {
                $id         = (int) $m[1];
                $urgency    = (int) $m[2];
                $templateId = isset($m[3]) ? (int) $m[3] : null;

                if (in_array($id, $validIds, true)) {
                    // Only allow autoreplies on score 4 (general queries).
                    // Scores 1–3 need human attention; score 5 needs no reply.
                    $result[$id] = [
                        'urgency'     => $urgency,
                        'template_id' => ($urgency === 4 && $templateId) ? $templateId : null,
                    ];
                }
            }
        }

        return $result;
    }

    private static function truncate(string $text, int $max): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text));
        return mb_strlen($text) > $max
            ? mb_substr($text, 0, $max - 1) . '…'
            : $text;
    }

    private static function httpPost(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer lm-studio',
            ],
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'status' => 0, 'body' => $error];
        }

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => $body];
    }
}
