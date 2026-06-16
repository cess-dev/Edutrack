<?php
/**
 * EduTrack — Timetable Vision Extraction Service
 *
 * Sends a timetable image to a multimodal LM Studio model (e.g. Qwen2-VL)
 * and parses the response into a structured array of class slots.
 *
 * For text-based PDFs, pdftotext is attempted first so the text model can
 * parse it without needing a vision model at all.
 *
 * Supported inputs:
 *   - Images (JPEG, PNG, GIF, WEBP) → base64-encoded, sent to vision model
 *   - PDFs with embedded text       → pdftotext extraction → text sent to LM Studio
 *   - Scanned PDFs (no text layer)  → converted to base64 image fallback (if gs available)
 */

if (!defined('EDUTRACK_LOADED')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

class TimetableVisionService
{
    public static function isEnabled(): bool
    {
        return defined('VISION_ENABLED')    && VISION_ENABLED    === true
            && defined('VISION_MODEL_URL')  && VISION_MODEL_URL  !== ''
            && defined('VISION_MODEL_NAME') && VISION_MODEL_NAME !== '';
    }

    /**
     * Extract class schedule from an uploaded file.
     *
     * @param  string $filePath  Absolute path to the uploaded file
     * @param  string $mimeType  MIME type of the file
     * @return array  ['success' => bool, 'slots' => [...], 'error' => string]
     */
    public static function extract(string $filePath, string $mimeType): array
    {
        if (!self::isEnabled()) {
            return ['success' => false, 'error' => 'Vision extraction is not enabled.'];
        }

        if (!file_exists($filePath)) {
            return ['success' => false, 'error' => 'Uploaded file not found.'];
        }

        if ($mimeType === 'application/pdf') {
            return self::extractFromPdf($filePath);
        }

        // Image types: send directly to vision model
        if (in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            return self::extractFromImage($filePath, $mimeType);
        }

        if (in_array($mimeType, ['text/csv', 'text/plain', 'application/vnd.ms-excel'], true)) {
            return self::extractFromCsv($filePath);
        }

        return ['success' => false, 'error' => 'Unsupported file type for extraction.'];
    }

    // ── Image extraction ──────────────────────────────────────────────────────

    private static function extractFromImage(string $filePath, string $mimeType): array
    {
        $base64 = base64_encode(file_get_contents($filePath));
        $dataUrl = "data:{$mimeType};base64,{$base64}";

        $prompt = self::buildExtractionPrompt();

        $payload = [
            'model'       => VISION_MODEL_NAME,
            'messages'    => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'text',      'text'      => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                ],
            ]],
            'temperature' => 0.1,
            'max_tokens'  => 2000,
            'stream'      => false,
        ];

        $response = self::httpPost(VISION_MODEL_URL, $payload);

        if (!$response['ok']) {
            $decoded = json_decode($response['body'], true);
            $errMsg  = $decoded['error']['message'] ?? $response['body'];
            error_log("[TimetableVision] Vision API error: {$errMsg}");
            return ['success' => false, 'error' => "Vision model error: {$errMsg}"];
        }

        $data    = json_decode($response['body'], true);
        $content = trim($data['choices'][0]['message']['content'] ?? '');

        return self::parseModelOutput($content);
    }

    // ── PDF extraction ────────────────────────────────────────────────────────

    private static function extractFromPdf(string $filePath): array
    {
        // Try pdftotext (requires poppler-utils installed on the system)
        $text = self::pdfToText($filePath);

        if ($text !== null && strlen(trim($text)) > 30) {
            return self::extractFromText($text);
        }

        // No text layer found — inform user
        return [
            'success' => false,
            'error'   => 'This PDF appears to be a scanned image. Please convert it to JPG/PNG and re-upload, or use a text-based PDF.',
        ];
    }

    private static function pdfToText(string $filePath): ?string
    {
        // pdftotext is part of poppler-utils; available on most Linux servers
        // and can be installed on Windows (https://poppler.freedesktop.org)
        $escaped = escapeshellarg($filePath);
        $output  = [];
        $code    = 0;

        exec("pdftotext -layout {$escaped} - 2>/dev/null", $output, $code);

        if ($code !== 0 || empty($output)) {
            return null;
        }

        return implode("\n", $output);
    }

    // ── Text-based extraction (for PDF text or plain text fallback) ───────────

    private static function extractFromText(string $text): array
    {
        $prompt = self::buildExtractionPrompt() . "\n\nTIMETABLE TEXT:\n" . $text;

        $payload = [
            'model'       => LM_STUDIO_MODEL,
            'messages'    => [[
                'role'    => 'user',
                'content' => $prompt,
            ]],
            'temperature' => 0.1,
            'max_tokens'  => 2000,
            'stream'      => false,
        ];

        $response = self::httpPost(LM_STUDIO_URL, $payload);

        if (!$response['ok']) {
            $decoded = json_decode($response['body'], true);
            $errMsg  = $decoded['error']['message'] ?? $response['body'];
            error_log("[TimetableVision] Text extraction error: {$errMsg}");
            return ['success' => false, 'error' => "AI error: {$errMsg}"];
        }

        $data    = json_decode($response['body'], true);
        $content = trim($data['choices'][0]['message']['content'] ?? '');

        return self::parseModelOutput($content);
    }

    // ── CSV direct parse (no AI needed) ──────────────────────────────────────

    private static function extractFromCsv(string $filePath): array
    {
        $rows  = [];
        $days  = [
            'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
            'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7,
            'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4,
            'fri' => 5, 'sat' => 6, 'sun' => 7,
        ];

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return ['success' => false, 'error' => 'Could not open CSV file.'];
        }

        $header = null;
        while (($line = fgetcsv($handle)) !== false) {
            if ($header === null) {
                $header = array_map(fn($h) => strtolower(trim($h)), $line);
                continue;
            }

            if (count($line) < count($header)) continue;
            $row = array_combine($header, $line);

            $dayRaw = strtolower(trim($row['day'] ?? $row['day_of_week'] ?? ''));
            $dayNum = $days[$dayRaw] ?? (is_numeric($dayRaw) ? (int)$dayRaw : null);

            if (!$dayNum) continue;

            $rows[] = [
                'day_of_week' => $dayNum,
                'unit_code'   => strtoupper(trim($row['unit_code'] ?? $row['code'] ?? '')),
                'unit_name'   => trim($row['unit_name'] ?? $row['name'] ?? null) ?: null,
                'start_time'  => self::normaliseTime($row['start_time'] ?? $row['start'] ?? ''),
                'end_time'    => self::normaliseTime($row['end_time']   ?? $row['end']   ?? ''),
                'room'        => trim($row['room'] ?? $row['venue'] ?? '') ?: null,
            ];
        }
        fclose($handle);

        if (empty($rows)) {
            return ['success' => false, 'error' => 'CSV parsed but no valid rows found. Expected columns: day, unit_code, start_time, end_time, room.'];
        }

        return ['success' => true, 'slots' => $rows];
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

    private static function buildExtractionPrompt(): string
    {
        return <<<'PROMPT'
Extract every class/lecture from this timetable. Return ONLY a valid JSON array — no explanation, no markdown, no code fences.

Each object in the array must have exactly these fields:
- "day_of_week": integer (1=Monday, 2=Tuesday, 3=Wednesday, 4=Thursday, 5=Friday, 6=Saturday, 7=Sunday)
- "unit_code": string (the subject/unit/course code, e.g. "CS101")
- "unit_name": string or null (full subject name if visible)
- "start_time": string in 24-hour HH:MM format (e.g. "08:00", "14:30")
- "end_time": string in 24-hour HH:MM format
- "room": string or null (room/venue/lab if visible)

Example output:
[{"day_of_week":1,"unit_code":"CS101","unit_name":"Introduction to Programming","start_time":"08:00","end_time":"10:00","room":"Lab 1"},{"day_of_week":3,"unit_code":"MATH201","unit_name":null,"start_time":"11:00","end_time":"13:00","room":"Room 4"}]
PROMPT;
    }

    private static function parseModelOutput(string $content): array
    {
        // Strip markdown code fences if the model added them
        $content = preg_replace('/^```(?:json)?\s*/m', '', $content);
        $content = preg_replace('/```\s*$/m', '', $content);
        $content = trim($content);

        // Extract the first JSON array found
        if (preg_match('/\[[\s\S]*\]/m', $content, $matches)) {
            $content = $matches[0];
        }

        $decoded = json_decode($content, true);

        if (!is_array($decoded) || empty($decoded)) {
            error_log("[TimetableVision] Could not parse model output: " . substr($content, 0, 300));
            return ['success' => false, 'error' => 'The AI could not extract a timetable from this file. Please try a clearer image or use CSV upload.'];
        }

        $slots = [];
        foreach ($decoded as $item) {
            $day = (int)($item['day_of_week'] ?? 0);
            if ($day < 1 || $day > 7) continue;

            $start = self::normaliseTime($item['start_time'] ?? '');
            $end   = self::normaliseTime($item['end_time']   ?? '');
            if (!$start || !$end) continue;

            $slots[] = [
                'day_of_week' => $day,
                'unit_code'   => strtoupper(trim($item['unit_code'] ?? 'UNKNOWN')),
                'unit_name'   => !empty($item['unit_name']) ? trim($item['unit_name']) : null,
                'start_time'  => $start,
                'end_time'    => $end,
                'room'        => !empty($item['room']) ? trim($item['room']) : null,
            ];
        }

        if (empty($slots)) {
            return ['success' => false, 'error' => 'No valid class slots were found in the extracted data.'];
        }

        // Sort by day then start time
        usort($slots, fn($a, $b) => $a['day_of_week'] <=> $b['day_of_week']
            ?: strcmp($a['start_time'], $b['start_time']));

        return ['success' => true, 'slots' => $slots];
    }

    private static function normaliseTime(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') return null;

        // Already HH:MM
        if (preg_match('/^\d{1,2}:\d{2}$/', $raw)) {
            [$h, $m] = explode(':', $raw);
            return sprintf('%02d:%02d', (int)$h, (int)$m);
        }

        // 12-hour with am/pm
        if (preg_match('/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)$/i', $raw, $m)) {
            $h   = (int)$m[1];
            $min = (int)($m[2] ?? 0);
            $pm  = strtolower($m[3]) === 'pm';
            if ($pm && $h !== 12) $h += 12;
            if (!$pm && $h === 12) $h = 0;
            return sprintf('%02d:%02d', $h, $min);
        }

        return null;
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
            error_log('[TimetableVision] cURL error: ' . $error);
            return ['ok' => false, 'status' => 0, 'body' => json_encode(['error' => ['message' => $error]])];
        }

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => $body];
    }
}
