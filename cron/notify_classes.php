<?php
/**
 * EduTrack — Class Reminder Dispatcher
 *
 * Run this ONCE daily (e.g. 6:00 AM) via Windows Task Scheduler:
 *
 *   Program:   C:\xampp\php\php.exe
 *   Arguments: C:\xampp\htdocs\edutrack\cron\notify_classes.php
 *   Schedule:  Daily at 06:00 AM
 *
 * What it does:
 *   1. Pulls all unsent notification_queue rows scheduled for TODAY
 *   2. For each one, spawns a tiny background process that sleeps until
 *      the exact send_at time and then dispatches the email
 *   3. Exits immediately — the background processes handle timing
 *
 * This means: one scheduled task per day, no polling every N minutes.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

define('EDUTRACK_LOADED', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';

$today = date('Y-m-d');

// All unsent reminders for today
$queue = DB::rows(
    "SELECT nq.id, nq.lecturer_id, nq.schedule_id, nq.send_at, nq.class_date,
            u.email, u.full_name,
            cs.unit_code, cs.unit_name, cs.start_time, cs.end_time, cs.room
     FROM notification_queue nq
     JOIN users u              ON u.id  = nq.lecturer_id
     JOIN class_schedules cs   ON cs.id = nq.schedule_id
     WHERE nq.class_date = ?
       AND nq.sent       = 0
       AND u.email IS NOT NULL
       AND u.email != ''
       AND u.is_active = 1
     ORDER BY nq.send_at",
    [$today]
);

if (empty($queue)) {
    echo "[" . date('H:i:s') . "] No reminders queued for today ({$today}).\n";
    exit(0);
}

echo "[" . date('H:i:s') . "] Scheduling " . count($queue) . " reminder(s) for today.\n";

$phpBin = PHP_BINARY;
$sender = escapeshellarg(dirname(__DIR__) . '/cron/send_reminder.php');

foreach ($queue as $item) {
    $sendAt     = new DateTime($item['send_at']);
    $nowSeconds = time();
    $sendSeconds = $sendAt->getTimestamp();
    $delay       = max(0, $sendSeconds - $nowSeconds);

    // Pass data as CLI args to the background sender
    $id       = (int)$item['id'];
    $queueId  = escapeshellarg($id);
    $phpBin_e = escapeshellarg($phpBin);

    // Windows: start /B runs in background without a window
    // Linux/Mac: & at end sends to background
    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = "start /B {$phpBin_e} {$sender} {$queueId} {$delay}";
        pclose(popen($cmd, 'r'));
    } else {
        $cmd = "{$phpBin_e} {$sender} {$queueId} {$delay} > /dev/null 2>&1 &";
        exec($cmd);
    }

    echo "[" . date('H:i:s') . "] Queued reminder #{$id} for {$item['unit_code']} at {$item['send_at']} (delay: {$delay}s)\n";
}

echo "[" . date('H:i:s') . "] Dispatcher done.\n";
