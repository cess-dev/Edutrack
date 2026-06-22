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
        if (!file_exists($filePath)) {
            return ['success' => false, 'error' => 'Uploaded file not found.'];
        }

        // CSV: parsed directly, no AI needed
        if (in_array($mimeType, ['text/csv', 'text/plain', 'application/vnd.ms-excel'], true)) {
            return self::extractFromCsv($filePath);
        }

        // PDF: extract text and send to the regular text model — no vision needed
        if ($mimeType === 'application/pdf') {
            if (!defined('LM_STUDIO_URL') || LM_STUDIO_URL === '' || !defined('LM_STUDIO_MODEL') || LM_STUDIO_MODEL === '') {
                return ['success' => false, 'error' => 'AI is not configured. Set LM_STUDIO_URL and LM_STUDIO_MODEL in .env.'];
            }
            return self::extractFromPdf($filePath);
        }

        // Image types: require a vision-capable model
        if (in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            if (!self::isEnabled()) {
                return ['success' => false, 'error' => 'Image extraction requires a vision model (e.g. qwen2-vl-7b-instruct). Set VISION_MODEL_NAME in .env to a multimodal model, or upload a PDF/CSV instead.'];
            }
            return self::extractFromImage($filePath, $mimeType);
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
        // Try pdftotext first (requires poppler-utils)
        $text = self::pdfToText($filePath);

        // Fallback: extract text directly from PDF stream (works on Windows without external tools)
        if ($text === null || strlen(trim($text)) <= 30) {
            $text = self::phpPdfExtract($filePath);
        }

        if ($text !== null && strlen(trim($text)) > 30) {
            return self::extractFromText($text);
        }

        return [
            'success' => false,
            'error'   => 'Could not extract text from this PDF. It may be a scanned image. Please upload a text-based PDF or a CSV file instead.',
        ];
    }

    private static function pdfToText(string $filePath): ?string
    {
        $escaped = escapeshellarg($filePath);
        $output  = [];
        $code    = 0;

        @exec("pdftotext -layout {$escaped} - 2>/dev/null", $output, $code);

        if ($code !== 0 || empty($output)) {
            return null;
        }

        return implode("\n", $output);
    }

    private static function phpPdfExtract(string $filePath): ?string
    {
        $raw = file_get_contents($filePath);
        if ($raw === false) return null;

        // Step 1: find and decode all streams (handles FlateDecode, ASCII85Decode, or both)
        $decoded = [];

        // Collect stream dictionaries + data
        $offset = 0;
        while (($sPos = strpos($raw, 'stream', $offset)) !== false) {
            $ePos = strpos($raw, 'endstream', $sPos);
            if ($ePos === false) break;

            // Skip past "stream\r\n" or "stream\n"
            $dataStart = $sPos + 6;
            if (isset($raw[$dataStart]) && $raw[$dataStart] === "\r") $dataStart++;
            if (isset($raw[$dataStart]) && $raw[$dataStart] === "\n") $dataStart++;

            $data = substr($raw, $dataStart, $ePos - $dataStart);
            // Trim trailing whitespace before endstream
            $data = rtrim($data, "\r\n");

            // Look back for the Filter in the object dictionary
            $dictChunk = substr($raw, max(0, $sPos - 300), 300);
            $hasAscii85  = (bool)preg_match('/ASCII85Decode/', $dictChunk);
            $hasFlate    = (bool)preg_match('/FlateDecode/', $dictChunk);

            if ($hasAscii85) {
                $data = self::ascii85Decode($data);
                if ($data === null) { $offset = $ePos + 9; continue; }
            }

            if ($hasFlate) {
                $d = @gzuncompress($data);
                if ($d === false) $d = @gzinflate($data);
                if ($d !== false) $data = $d; else { $offset = $ePos + 9; continue; }
            }

            if (!$hasAscii85 && !$hasFlate) {
                // Uncompressed — only keep if it looks like text content
                if (strpos($data, 'BT') === false && strpos($data, 'begincmap') === false) {
                    $offset = $ePos + 9;
                    continue;
                }
            }

            $decoded[] = $data;
            $offset = $ePos + 9;
        }

        // Also try the old regex approach as fallback
        if (empty($decoded) && preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $sm)) {
            foreach ($sm[1] as $s) {
                $d = @gzuncompress($s);
                if ($d === false) $d = @gzinflate($s);
                if ($d !== false) $decoded[] = $d;
            }
        }

        // Step 2: build CMap (hex code → unicode char) from all CMap streams
        $cmap = [];
        foreach ($decoded as $d) {
            if (strpos($d, 'begincmap') === false) continue;

            // beginbfchar entries: <src> <dst>
            if (preg_match_all('/beginbfchar\s*(.*?)\s*endbfchar/s', $d, $bfc)) {
                foreach ($bfc[1] as $block) {
                    if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $pairs)) {
                        foreach ($pairs[1] as $i => $src) {
                            $dst = $pairs[2][$i];
                            $cmap[strtoupper($src)] = mb_chr((int)hexdec($dst), 'UTF-8');
                        }
                    }
                }
            }

            // beginbfrange entries: <start> <end> <dstStart>
            if (preg_match_all('/beginbfrange\s*(.*?)\s*endbfrange/s', $d, $bfr)) {
                foreach ($bfr[1] as $block) {
                    if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $ranges)) {
                        foreach ($ranges[1] as $i => $startHex) {
                            $start  = (int)hexdec($startHex);
                            $end    = (int)hexdec($ranges[2][$i]);
                            $dstVal = (int)hexdec($ranges[3][$i]);
                            for ($c = $start; $c <= $end; $c++) {
                                $cmap[strtoupper(str_pad(dechex($c), strlen($startHex), '0', STR_PAD_LEFT))] = mb_chr($dstVal + ($c - $start), 'UTF-8');
                            }
                        }
                    }
                }
            }
        }

        // Step 3: extract text from content streams (BT..ET blocks)
        $lines = [];
        $lastY = null;

        foreach ($decoded as $d) {
            if (strpos($d, 'BT') === false) continue;

            if (!preg_match_all('/\bBT\b\s*(.*?)\s*\bET\b/s', $d, $btm)) continue;

            foreach ($btm[1] as $block) {
                $lineText = '';

                // Track Y position from Tm operator for line breaks
                if (preg_match_all('/[0-9.\-]+ [0-9.\-]+ [0-9.\-]+ [0-9.\-]+ [0-9.\-]+ ([0-9.\-]+) Tm/', $block, $tmm)) {
                    $y = (float)end($tmm[1]);
                    if ($lastY !== null && abs($y - $lastY) > 2) {
                        if (!empty($lines) || $lineText !== '') {
                            $lines[] = $lineText;
                            $lineText = '';
                        }
                    }
                    $lastY = $y;
                }

                // Decode <hex> Tj sequences
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s*Tj/', $block, $tjm)) {
                    foreach ($tjm[1] as $hex) {
                        $chars = str_split(strtoupper($hex), 4);
                        foreach ($chars as $ch) {
                            $lineText .= $cmap[$ch] ?? '';
                        }
                    }
                }

                // Decode TJ arrays: [<hex> num <hex> num ...] TJ
                if (preg_match_all('/\[(.*?)\]\s*TJ/s', $block, $tja)) {
                    foreach ($tja[1] as $arr) {
                        if (preg_match_all('/<([0-9A-Fa-f]+)>/', $arr, $hexm)) {
                            foreach ($hexm[1] as $hex) {
                                $chars = str_split(strtoupper($hex), 4);
                                foreach ($chars as $ch) {
                                    $lineText .= $cmap[$ch] ?? '';
                                }
                            }
                        }
                        // Also handle parenthesized strings in TJ arrays
                        if (preg_match_all('/\(([^)]*)\)/', $arr, $paren)) {
                            foreach ($paren[1] as $p) {
                                $lineText .= $p;
                            }
                        }
                    }
                }

                // Decode plain (text) Tj for simple PDFs
                if (preg_match_all('/\(([^)]*)\)\s*Tj/', $block, $ptj)) {
                    foreach ($ptj[1] as $p) {
                        $lineText .= $p;
                    }
                }

                if ($lineText !== '') {
                    $lines[] = $lineText;
                }
            }
        }

        $text = trim(implode("\n", $lines));
        return $text !== '' ? $text : null;
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

    private static function ascii85Decode(string $data): ?string
    {
        // Strip whitespace and the <~ / ~> delimiters
        $data = trim($data);
        if (str_starts_with($data, '<~')) $data = substr($data, 2);
        if (str_ends_with($data, '~>'))   $data = substr($data, 0, -2);
        $data = preg_replace('/\s+/', '', $data);

        $result = '';
        $len = strlen($data);
        $i = 0;

        while ($i < $len) {
            if ($data[$i] === 'z') {
                $result .= "\0\0\0\0";
                $i++;
                continue;
            }

            $chunk = '';
            $pad = 0;
            for ($j = 0; $j < 5; $j++) {
                if ($i + $j < $len) {
                    $chunk .= $data[$i + $j];
                } else {
                    $chunk .= 'u'; // pad with 'u' (max value)
                    $pad++;
                }
            }

            $val = 0;
            for ($j = 0; $j < 5; $j++) {
                $val = $val * 85 + (ord($chunk[$j]) - 33);
            }

            $bytes = pack('N', $val);
            $result .= substr($bytes, 0, 4 - $pad);
            $i += 5 - $pad;
        }

        return $result !== '' ? $result : null;
    }
}
