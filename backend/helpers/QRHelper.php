<?php
/**
 * EduTrack — QR Token Helper
 *
 * Handles everything related to QR-based attendance tokens:
 *   - Generating a signed session token stored in attendance_sessions
 *   - Building the QR payload string that gets encoded into the QR image
 *   - Validating an incoming scan token (expiry + HMAC integrity)
 *   - Producing human-readable error reasons for failed scans
 *
 * Security model:
 *   The QR code encodes a small JSON payload. The payload is NOT encrypted —
 *   it is readable — but it is signed with HMAC-SHA256 using APP_SECRET.
 *   This means:
 *     - Anyone can read what is in the QR (unit ID, expiry time)
 *     - Nobody can forge a valid token without knowing APP_SECRET
 *     - Screenshot sharing is mitigated by the expiry window
 *     - Duplicate scans are blocked by the UNIQUE DB constraint
 *
 * Usage:
 *   // Lecturer starts a session — call from the session_create API endpoint
 *   $result = QRHelper::createSession($unitId, $lecturerId);
 *   // $result['session_id'], $result['token'], $result['payload'], $result['expires_at']
 *
 *   // Student scans — call from the scan API endpoint
 *   $check = QRHelper::validateScan($rawQrString, $studentId);
 *   if ($check['valid']) {
 *       // record attendance using $check['session_id']
 *   } else {
 *       // return $check['error'] to the student
 *   }
 */

if (!defined('EDUTRACK_LOADED')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

class QRHelper
{
    /**
     * Current payload version.
     * Increment if the payload structure changes so old QR codes are
     * automatically rejected after an upgrade.
     */
    private const PAYLOAD_VERSION = 1;

    // ─────────────────────────────────────────────────────────────────────────
    // Session creation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Create a new attendance session in the database and return everything
     * the lecturer's page needs to render the QR code.
     *
     * Steps:
     *  1. Close any previously open session for this unit (safety guard)
     *  2. Generate a cryptographically random token
     *  3. Compute expiry timestamp from ATTENDANCE_WINDOW_MINUTES
     *  4. Sign the token with HMAC-SHA256
     *  5. Insert the session row
     *  6. Build and return the QR payload string
     *
     * @param  int    $unitId
     * @param  int    $lecturerId
     * @param  string $academicYear  e.g. '2024/2025'
     * @param  int    $semester      1 or 2
     * @param  string|null $note     Optional session note from lecturer
     * @return array {
     *   session_id: int,
     *   token:      string,   raw hex token
     *   payload:    string,   JSON string to encode into QR image
     *   expires_at: string,   datetime string
     *   window_minutes: int
     * }
     * @throws RuntimeException on DB failure
     */
    public static function createSession(
        int    $unitId,
        int    $lecturerId,
        string $academicYear,
        int    $semester,
        ?string $note = null
    ): array {
        // 1. Close any lingering open session for this unit
        self::closeOpenSessions($unitId);

        // 2. Generate token — 32 random bytes → 64 hex characters
        $token = bin2hex(random_bytes(32));

        // 3. Expiry timestamp
        $windowMinutes = (int) self::getSetting('attendance_window', ATTENDANCE_WINDOW_MINUTES);
        $expiresAt     = date('Y-m-d H:i:s', strtotime("+{$windowMinutes} minutes"));

        // 4. HMAC signature over token + unit_id + expires_at
        $hmac = self::signToken($token, $unitId, $expiresAt);

        // 5. Insert session row
        $sessionId = DB::insert(
            "INSERT INTO attendance_sessions
                (unit_id, lecturer_id, academic_year, semester,
                 session_token, token_hmac, expires_at, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$unitId, $lecturerId, $academicYear, $semester,
             $token, $hmac, $expiresAt, $note]
        );

        // 6. Build QR payload
        $payload = self::buildPayload($token, (int) $sessionId, $unitId, $expiresAt);

        return [
            'session_id'     => (int) $sessionId,
            'token'          => $token,
            'payload'        => $payload,
            'expires_at'     => $expiresAt,
            'window_minutes' => $windowMinutes,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Scan validation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Validate a raw QR string scanned by a student's device.
     *
     * Checks in order:
     *  1. Payload is valid JSON with required fields
     *  2. Payload version matches current version
     *  3. Session exists in the database
     *  4. Session is still active (is_active = 1)
     *  5. Token has not expired (expires_at > NOW())
     *  6. HMAC signature is valid (token not forged)
     *  7. Student is enrolled in the unit for this session
     *  8. Student has not already scanned this session (duplicate check)
     *
     * @param  string $rawQrString  The decoded string from jsQR
     * @param  int    $studentId    ID of the student submitting the scan
     * @return array {
     *   valid:      bool,
     *   session_id: int|null,
     *   unit_id:    int|null,
     *   error:      string|null,   human-readable reason for failure
     *   error_code: string|null    machine-readable code for frontend handling
     * }
     */
    public static function validateScan(string $rawQrString, int $studentId): array
    {
        $fail = fn(string $msg, string $code) => [
            'valid'      => false,
            'session_id' => null,
            'unit_id'    => null,
            'error'      => $msg,
            'error_code' => $code,
        ];

        // 1. Parse payload
        $payload = json_decode(trim($rawQrString), true);
        if (!$payload || !self::payloadHasRequiredFields($payload)) {
            return $fail(
                'Invalid QR code. Please scan the code displayed by your lecturer.',
                'INVALID_PAYLOAD'
            );
        }

        // 2. Version check
        if ((int) ($payload['v'] ?? 0) !== self::PAYLOAD_VERSION) {
            return $fail(
                'This QR code is from an older version of EduTrack. Please ask your lecturer to generate a new one.',
                'VERSION_MISMATCH'
            );
        }

        $token     = $payload['t'];
        $unitId    = (int) $payload['u'];
        $expiresAt = $payload['e'];

        // 3 & 4. Look up session — must exist and be active
        $session = DB::row(
            "SELECT id, unit_id, is_active, expires_at, token_hmac
             FROM attendance_sessions
             WHERE session_token = ?
             LIMIT 1",
            [$token]
        );

        if (!$session) {
            return $fail(
                'Session not found. Please scan the QR code displayed by your lecturer.',
                'SESSION_NOT_FOUND'
            );
        }

        if (!$session['is_active']) {
            return $fail(
                'This attendance session has been closed. Contact your lecturer if you were present.',
                'SESSION_CLOSED'
            );
        }

        // 5. Expiry check (server-side authoritative — do not trust payload's expiry alone)
        if (strtotime($session['expires_at']) < time()) {
            return $fail(
                'This QR code has expired. Ask your lecturer to generate a new session.',
                'TOKEN_EXPIRED'
            );
        }

        // 6. HMAC verification — recompute and compare
        $expectedHmac = self::signToken($token, $session['unit_id'], $session['expires_at']);
        if (!hash_equals($expectedHmac, $session['token_hmac'])) {
            return $fail(
                'Invalid QR code signature. Do not attempt to use modified QR codes.',
                'HMAC_INVALID'
            );
        }

        // 7. Enrollment check — student must be enrolled in this unit
        $enrolled = DB::row(
            "SELECT id FROM enrollments
             WHERE student_id = ? AND unit_id = ?
             LIMIT 1",
            [$studentId, $session['unit_id']]
        );

        if (!$enrolled) {
            return $fail(
                'You are not enrolled in the unit for this session.',
                'NOT_ENROLLED'
            );
        }

        // 8. Duplicate scan check
        $existing = DB::row(
            "SELECT id FROM attendance_logs
             WHERE session_id = ? AND student_id = ?
             LIMIT 1",
            [$session['id'], $studentId]
        );

        if ($existing) {
            return $fail(
                'Your attendance has already been recorded for this session.',
                'ALREADY_SCANNED'
            );
        }

        // All checks passed
        return [
            'valid'      => true,
            'session_id' => (int) $session['id'],
            'unit_id'    => (int) $session['unit_id'],
            'error'      => null,
            'error_code' => null,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Session management
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Close a session manually (lecturer clicks 'End Session') or
     * when the system auto-closes an expired session.
     *
     * After closing, auto-marks all enrolled students who did not scan as absent.
     *
     * @param  int $sessionId
     * @param  int $lecturerId  Used to verify the lecturer owns this session
     * @return array { closed: bool, absent_marked: int }
     */
    public static function closeSession(int $sessionId, int $lecturerId): array
    {
        // Verify the session belongs to this lecturer
        $session = DB::row(
            "SELECT id, unit_id, academic_year, semester, is_active
             FROM attendance_sessions
             WHERE id = ? AND lecturer_id = ?
             LIMIT 1",
            [$sessionId, $lecturerId]
        );

        if (!$session || !$session['is_active']) {
            return ['closed' => false, 'absent_marked' => 0];
        }

        DB::beginTransaction();

        try {
            // Mark the session closed
            DB::execute(
                "UPDATE attendance_sessions
                 SET is_active = 0, closed_at = NOW()
                 WHERE id = ?",
                [$sessionId]
            );

            // Find all enrolled students who did NOT scan
            $absentStudents = DB::rows(
                "SELECT e.student_id
                 FROM enrollments e
                 LEFT JOIN attendance_logs al
                     ON al.session_id = ? AND al.student_id = e.student_id
                 WHERE e.unit_id     = ?
                   AND e.academic_year = ?
                   AND e.semester    = ?
                   AND al.id IS NULL",
                [$sessionId, $session['unit_id'], $session['academic_year'], $session['semester']]
            );

            // Bulk-insert absent rows
            $absentCount = 0;
            foreach ($absentStudents as $row) {
                DB::insert(
                    "INSERT IGNORE INTO attendance_logs
                        (session_id, student_id, status, method)
                     VALUES (?, ?, 'absent', 'auto_absent')",
                    [$sessionId, $row['student_id']]
                );
                $absentCount++;
            }

            DB::commit();

            return ['closed' => true, 'absent_marked' => $absentCount];

        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * Return live scan data for a session — used by the lecturer's
     * polling endpoint to refresh the attendance list every few seconds.
     *
     * @param  int $sessionId
     * @return array { session: array, scans: array, total_enrolled: int }
     */
    public static function getLiveData(int $sessionId): array
    {
        $session = DB::row(
            "SELECT s.id, s.unit_id, s.expires_at, s.is_active,
                    u.code AS unit_code, u.name AS unit_name
             FROM attendance_sessions s
             JOIN units u ON u.id = s.unit_id
             WHERE s.id = ?",
            [$sessionId]
        );

        $scans = DB::rows(
            "SELECT al.student_id, al.scanned_at, al.status,
                    usr.full_name, usr.reg_number
             FROM attendance_logs al
             JOIN users usr ON usr.id = al.student_id
             WHERE al.session_id = ?
               AND al.status = 'present'
             ORDER BY al.scanned_at ASC",
            [$sessionId]
        );

        $totalEnrolled = DB::row(
            "SELECT COUNT(*) AS cnt
             FROM enrollments
             WHERE unit_id = ? AND academic_year = ?",
            [$session['unit_id'] ?? 0, date('Y') . '/' . (date('Y') + 1)]
        );

        return [
            'session'       => $session,
            'scans'         => $scans,
            'scan_count'    => count($scans),
            'total_enrolled'=> (int) ($totalEnrolled['cnt'] ?? 0),
            'server_time'   => date('Y-m-d H:i:s'),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build the compact JSON payload that gets encoded into the QR image.
     * Keys are intentionally short to keep the QR code simple (fewer modules).
     *
     * Payload fields:
     *   v  → version (int)
     *   t  → token (hex string)
     *   s  → session_id (int)
     *   u  → unit_id (int)
     *   e  → expires_at (datetime string)
     *
     * @param  string $token
     * @param  int    $sessionId
     * @param  int    $unitId
     * @param  string $expiresAt
     * @return string  JSON string
     */
    private static function buildPayload(
        string $token,
        int    $sessionId,
        int    $unitId,
        string $expiresAt
    ): string {
        return json_encode([
            'v' => self::PAYLOAD_VERSION,
            't' => $token,
            's' => $sessionId,
            'u' => $unitId,
            'e' => $expiresAt,
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Compute HMAC-SHA256 signature over the key fields.
     * The message includes token + unit_id + expires_at so that
     * even a copied token cannot be replayed for a different unit.
     *
     * @param  string $token
     * @param  int    $unitId
     * @param  string $expiresAt
     * @return string  Hex HMAC string
     */
    private static function signToken(string $token, int $unitId, string $expiresAt): string
    {
        $message = implode('|', [$token, $unitId, $expiresAt]);
        return hash_hmac('sha256', $message, APP_SECRET);
    }

    /**
     * Check that the decoded payload has all the fields we expect.
     *
     * @param  array $payload
     * @return bool
     */
    private static function payloadHasRequiredFields(array $payload): bool
    {
        return isset($payload['v'], $payload['t'], $payload['s'], $payload['u'], $payload['e'])
            && is_string($payload['t'])
            && strlen($payload['t']) === 64
            && is_int($payload['u'])
            && is_string($payload['e']);
    }

    /**
     * Close any currently open (is_active = 1) sessions for the given unit.
     * Prevents a lecturer accidentally having two active sessions at once.
     *
     * @param int $unitId
     */
    private static function closeOpenSessions(int $unitId): void
    {
        $openSessions = DB::rows(
            "SELECT id, lecturer_id FROM attendance_sessions
             WHERE unit_id = ? AND is_active = 1",
            [$unitId]
        );

        foreach ($openSessions as $s) {
            DB::execute(
                "UPDATE attendance_sessions
                 SET is_active = 0, closed_at = NOW()
                 WHERE id = ?",
                [$s['id']]
            );
        }
    }

    /**
     * Read a setting from system_settings, falling back to a default value.
     *
     * @param  string $key
     * @param  mixed  $default
     * @return mixed
     */
    private static function getSetting(string $key, mixed $default): mixed
    {
        $row = DB::row(
            "SELECT setting_value FROM system_settings WHERE setting_key = ?",
            [$key]
        );
        return $row ? $row['setting_value'] : $default;
    }

    /** Prevent instantiation */
    private function __construct() {}
}