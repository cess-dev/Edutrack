<?php
/**
 * EduTrack — Timetable Save (Confirm) Endpoint
 *
 * Called after the lecturer reviews the AI-extracted slots and confirms them.
 * Saves the (possibly edited) slots to class_schedules and marks the timetable
 * as confirmed. Any previous confirmed schedule for this lecturer/semester is
 * replaced.
 *
 * Method:  POST  application/json
 * Access:  Lecturer only
 * Body:
 *   {
 *     "timetable_id": int,
 *     "slots": [
 *       { "day_of_week": 1, "unit_code": "CS101", "unit_name": "...",
 *         "start_time": "08:00", "end_time": "10:00", "room": "Lab 1" },
 *       ...
 *     ]
 *   }
 *
 * Success (200):
 *   { "success": true, "saved": int }
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

Auth::startSession();
Auth::requireRole('lecturer');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$user  = Auth::user();
$body  = json_decode(file_get_contents('php://input'), true);
$ttId  = (int)($body['timetable_id'] ?? 0);
$slots = $body['slots'] ?? [];

if ($ttId <= 0 || empty($slots)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'timetable_id and slots are required.']);
    exit;
}

// Verify timetable belongs to this lecturer
$tt = DB::row(
    "SELECT id, academic_year, semester FROM timetables WHERE id = ? AND lecturer_id = ?",
    [$ttId, $user['id']]
);

if (!$tt) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Timetable not found or access denied.']);
    exit;
}

// ── Validate slots ────────────────────────────────────────────────────────────

$valid = [];
foreach ($slots as $slot) {
    $day   = (int)($slot['day_of_week'] ?? 0);
    $code  = strtoupper(trim($slot['unit_code'] ?? ''));
    $start = trim($slot['start_time'] ?? '');
    $end   = trim($slot['end_time']   ?? '');

    if ($day < 1 || $day > 7 || $code === '' || !$start || !$end) continue;

    // Normalise times to HH:MM
    if (!preg_match('/^\d{2}:\d{2}$/', $start)) continue;
    if (!preg_match('/^\d{2}:\d{2}$/', $end))   continue;

    $valid[] = [
        'day_of_week' => $day,
        'unit_code'   => $code,
        'unit_name'   => !empty($slot['unit_name']) ? trim($slot['unit_name']) : null,
        'start_time'  => $start,
        'end_time'    => $end,
        'room'        => !empty($slot['room']) ? trim($slot['room']) : null,
    ];
}

if (empty($valid)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No valid slots provided.']);
    exit;
}

// ── Resolve unit IDs ──────────────────────────────────────────────────────────

$unitMap = [];
$allUnits = DB::rows(
    "SELECT id, code FROM units WHERE lecturer_id = ? AND is_active = 1",
    [$user['id']]
);
foreach ($allUnits as $u) {
    $unitMap[strtoupper($u['code'])] = $u['id'];
}

// ── Replace existing confirmed schedules for this timetable ──────────────────
// Only save units that belong to this lecturer to prevent wrong attribution.

DB::execute("DELETE FROM class_schedules WHERE timetable_id = ?", [$ttId]);

$saved   = 0;
$skipped = [];
foreach ($valid as $slot) {
    $unitId = $unitMap[$slot['unit_code']] ?? null;

    if ($unitId === null) {
        $skipped[] = $slot['unit_code'];
        continue;
    }

    DB::execute(
        "INSERT INTO class_schedules
            (timetable_id, unit_id, unit_code, unit_name, day_of_week, start_time, end_time, room)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $ttId,
            $unitId,
            $slot['unit_code'],
            $slot['unit_name'],
            $slot['day_of_week'],
            $slot['start_time'],
            $slot['end_time'],
            $slot['room'],
        ]
    );
    $saved++;
}

if ($saved === 0) {
    $skippedUnique = array_values(array_unique($skipped));
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error'   => 'None of the units in this timetable (' . implode(', ', $skippedUnique) . ') are assigned to you. '
                   . 'Your assigned units are: ' . implode(', ', array_keys($unitMap)) . '. '
                   . 'Please upload a timetable containing your own units.',
    ]);
    exit;
}

// ── Replace any previously confirmed timetable for this lecturer/semester ─────

DB::execute(
    "UPDATE timetables SET extraction_status = 'replaced'
     WHERE lecturer_id = ? AND academic_year = ? AND semester = ?
       AND extraction_status = 'confirmed' AND id != ?",
    [$user['id'], $tt['academic_year'], $tt['semester'], $ttId]
);

// Clean up orphaned schedules from replaced timetables
DB::execute(
    "DELETE cs FROM class_schedules cs
     JOIN timetables t ON t.id = cs.timetable_id
     WHERE t.lecturer_id = ? AND t.extraction_status = 'replaced'",
    [$user['id']]
);

// ── Mark this timetable as confirmed ─────────────────────────────────────────

DB::execute(
    "UPDATE timetables SET extraction_status = 'confirmed', confirmed_at = NOW() WHERE id = ?",
    [$ttId]
);

// ── Pre-schedule notifications for the next 4 weeks ───────────────────────────
// Only for lecturers with email notifications enabled.
$prefs = DB::row(
    "SELECT email_enabled, notify_before_minutes FROM notification_preferences WHERE lecturer_id = ?",
    [$user['id']]
);

if ($prefs && $prefs['email_enabled']) {
    $minutesBefore = (int)$prefs['notify_before_minutes'];

    // Fetch the saved slots (we just inserted them)
    $slots = DB::rows(
        "SELECT id, day_of_week, start_time FROM class_schedules WHERE timetable_id = ?",
        [$ttId]
    );

    $today     = new DateTime('today');
    $weeksAhead = 4; // queue 4 weeks of reminders

    foreach ($slots as $slot) {
        $targetDow = (int)$slot['day_of_week']; // 1=Mon … 7=Sun

        for ($week = 0; $week < $weeksAhead; $week++) {
            // Find the next occurrence of this day-of-week
            $d = clone $today;
            $d->modify("+{$week} weeks");
            // Advance to the correct day within that week
            $currentDow = (int)$d->format('N');
            $diff = $targetDow - $currentDow;
            if ($diff < 0) $diff += 7;
            $d->modify("+{$diff} days");

            $classDate = $d->format('Y-m-d');

            // send_at = class start minus notify window
            $sendAt = new DateTime($classDate . ' ' . $slot['start_time']);
            $sendAt->modify("-{$minutesBefore} minutes");

            // Skip if send_at is already in the past
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

$response = ['success' => true, 'saved' => $saved];
if (!empty($skipped)) {
    $skippedUnique = array_values(array_unique($skipped));
    $response['skipped'] = $skippedUnique;
    $response['skipped_message'] = count($skippedUnique) . ' unit(s) not assigned to you were skipped: ' . implode(', ', $skippedUnique);
}
echo json_encode($response);
