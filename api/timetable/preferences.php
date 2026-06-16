<?php
/**
 * EduTrack — Notification Preferences Endpoint
 *
 * GET  — returns current preferences for the authenticated lecturer
 * POST — saves updated preferences
 *
 * Method:  GET | POST
 * Access:  Lecturer only
 *
 * POST body (JSON):
 *   { "email_enabled": true, "notify_before_minutes": 30 }
 *
 * Success (200):
 *   { "success": true, "prefs": { "email_enabled": bool, "notify_before_minutes": int } }
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

Auth::startSession();
Auth::requireRole('lecturer');

header('Content-Type: application/json');

$user = Auth::user();

// ── GET ───────────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $prefs = DB::row(
        "SELECT email_enabled, notify_before_minutes FROM notification_preferences WHERE lecturer_id = ?",
        [$user['id']]
    );

    $prefs = $prefs ?? ['email_enabled' => 0, 'notify_before_minutes' => 30];

    echo json_encode([
        'success' => true,
        'prefs'   => [
            'email_enabled'         => (bool)$prefs['email_enabled'],
            'notify_before_minutes' => (int)$prefs['notify_before_minutes'],
        ],
    ]);
    exit;
}

// ── POST ──────────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body    = json_decode(file_get_contents('php://input'), true);
    $enabled = filter_var($body['email_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $minutes = (int)($body['notify_before_minutes'] ?? 30);

    if ($minutes < 5 || $minutes > 1440) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'notify_before_minutes must be between 5 and 1440.']);
        exit;
    }

    // Verify lecturer has an email address to notify
    if ($enabled) {
        $lecturerEmail = DB::row("SELECT email FROM users WHERE id = ?", [$user['id']])['email'] ?? '';
        if (empty($lecturerEmail)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'You must add an email address to your profile before enabling notifications.']);
            exit;
        }
    }

    DB::execute(
        "INSERT INTO notification_preferences (lecturer_id, email_enabled, notify_before_minutes)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE email_enabled = VALUES(email_enabled),
                                 notify_before_minutes = VALUES(notify_before_minutes)",
        [$user['id'], $enabled ? 1 : 0, $minutes]
    );

    // Re-generate the queue whenever prefs change so timing stays accurate
    if ($enabled) {
        // Clear unsent future entries for this lecturer
        DB::execute(
            "DELETE FROM notification_queue
             WHERE lecturer_id = ? AND sent = 0 AND send_at > NOW()",
            [$user['id']]
        );

        // Re-queue from the confirmed timetable
        $tt = DB::row(
            "SELECT id FROM timetables
             WHERE lecturer_id = ? AND extraction_status = 'confirmed'
             ORDER BY confirmed_at DESC LIMIT 1",
            [$user['id']]
        );

        if ($tt) {
            $slots     = DB::rows(
                "SELECT id, day_of_week, start_time FROM class_schedules WHERE timetable_id = ?",
                [$tt['id']]
            );
            $today      = new DateTime('today');
            $weeksAhead = 4;

            foreach ($slots as $slot) {
                $targetDow = (int)$slot['day_of_week'];
                for ($week = 0; $week < $weeksAhead; $week++) {
                    $d = clone $today;
                    $d->modify("+{$week} weeks");
                    $diff = $targetDow - (int)$d->format('N');
                    if ($diff < 0) $diff += 7;
                    $d->modify("+{$diff} days");

                    $classDate = $d->format('Y-m-d');
                    $sendAt    = new DateTime($classDate . ' ' . $slot['start_time']);
                    $sendAt->modify("-{$minutes} minutes");

                    if ($sendAt <= new DateTime()) continue;

                    DB::execute(
                        "INSERT IGNORE INTO notification_queue
                             (lecturer_id, schedule_id, send_at, class_date)
                         VALUES (?, ?, ?, ?)",
                        [$user['id'], $slot['id'], $sendAt->format('Y-m-d H:i:s'), $classDate]
                    );
                }
            }
        }
    } else {
        // Disable: remove all unsent future entries
        DB::execute(
            "DELETE FROM notification_queue
             WHERE lecturer_id = ? AND sent = 0 AND send_at > NOW()",
            [$user['id']]
        );
    }

    echo json_encode([
        'success' => true,
        'prefs'   => ['email_enabled' => $enabled, 'notify_before_minutes' => $minutes],
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
