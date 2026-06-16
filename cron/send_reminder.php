<?php
/**
 * EduTrack — Individual Reminder Sender
 *
 * Called by notify_classes.php as a background process.
 * Usage:  php send_reminder.php <queue_id> <sleep_seconds>
 *
 * Sleeps until the exact send time, sends one email, marks the row as sent.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$queueId     = (int)($argv[1] ?? 0);
$sleepSeconds = (int)($argv[2] ?? 0);

if ($queueId <= 0) {
    exit('Missing queue_id.');
}

if ($sleepSeconds > 0) {
    sleep($sleepSeconds);
}

define('EDUTRACK_LOADED', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/backend/services/EmailService.php';

// Re-fetch in case it was cancelled or already sent during the sleep
$item = DB::row(
    "SELECT nq.id, nq.lecturer_id, nq.schedule_id, nq.send_at, nq.class_date, nq.sent,
            u.email, u.full_name,
            cs.unit_code, cs.unit_name, cs.start_time, cs.end_time, cs.room
     FROM notification_queue nq
     JOIN users u            ON u.id  = nq.lecturer_id
     JOIN class_schedules cs ON cs.id = nq.schedule_id
     WHERE nq.id = ?",
    [$queueId]
);

if (!$item || $item['sent']) {
    exit(0); // Already sent or cancelled
}

// Also check lecturer still has notifications enabled
$prefs = DB::row(
    "SELECT email_enabled FROM notification_preferences WHERE lecturer_id = ?",
    [$item['lecturer_id']]
);
if (!$prefs || !$prefs['email_enabled']) {
    DB::execute("UPDATE notification_queue SET sent = 1, sent_at = NOW() WHERE id = ?", [$queueId]);
    exit(0);
}

// Build email
$unitLabel = $item['unit_name']
    ? "{$item['unit_code']} — {$item['unit_name']}"
    : $item['unit_code'];

$timeStr   = date('g:i A', strtotime($item['start_time']))
           . ' – '
           . date('g:i A', strtotime($item['end_time']));

$classDate = date('l, d M Y', strtotime($item['class_date']));
$timetableUrl = BASE_URL . '/lecturer/timetable';

$prefs2    = DB::row(
    "SELECT notify_before_minutes FROM notification_preferences WHERE lecturer_id = ?",
    [$item['lecturer_id']]
);
$notifyMin = (int)($prefs2['notify_before_minutes'] ?? 30);

$roomRow = $item['room']
    ? "<tr><td style='padding:4px 12px 4px 0;color:#555'>Room</td>"
      . "<td style='padding:4px 0'><strong>" . htmlspecialchars($item['room']) . "</strong></td></tr>"
    : '';

$plural  = $notifyMin !== 1 ? 's' : '';
$subject = "Reminder: {$item['unit_code']} class in {$notifyMin} minute{$plural}";

$html = "<p>Hi " . htmlspecialchars($item['full_name']) . ",</p>"
      . "<p>Your class starts in <strong>{$notifyMin} minute{$plural}</strong>.</p>"
      . "<table style='font-family:sans-serif;border-collapse:collapse;margin:16px 0'>"
      . "<tr><td style='padding:4px 12px 4px 0;color:#555'>Unit</td>"
      . "<td style='padding:4px 0'><strong>" . htmlspecialchars($unitLabel) . "</strong></td></tr>"
      . "<tr><td style='padding:4px 12px 4px 0;color:#555'>Date</td>"
      . "<td style='padding:4px 0'>" . htmlspecialchars($classDate) . "</td></tr>"
      . "<tr><td style='padding:4px 12px 4px 0;color:#555'>Time</td>"
      . "<td style='padding:4px 0'><strong>" . htmlspecialchars($timeStr) . "</strong></td></tr>"
      . $roomRow
      . "</table>"
      . "<p style='color:#888;font-size:12px'>Manage your notification preferences: "
      . "<a href='{$timetableUrl}'>Timetable page</a>.</p>";

$ok = EmailService::send($item['email'], $subject, $html);

if ($ok) {
    DB::execute(
        "UPDATE notification_queue SET sent = 1, sent_at = NOW() WHERE id = ?",
        [$queueId]
    );
    echo "Sent reminder #{$queueId} to {$item['email']} for {$item['unit_code']}\n";
} else {
    error_log("[Reminder] Failed to send #{$queueId} to {$item['email']}");
}
