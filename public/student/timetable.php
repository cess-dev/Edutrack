<?php
/**
 * EduTrack — Student: My Class Schedule
 *
 * Shows a read-only weekly timetable built from the class schedules
 * uploaded and confirmed by each unit's lecturer.
 * Students can toggle email reminders for their classes.
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

Auth::startSession();
Auth::requireRole('student');

$user = Auth::user();

$academicYear = DB::row("SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year'")['setting_value'] ?? ACADEMIC_YEAR;
$semester     = (int)(DB::row("SELECT setting_value FROM system_settings WHERE setting_key = 'active_semester'")['setting_value'] ?? ACTIVE_SEMESTER);

// Fetch schedule from confirmed lecturer timetables for enrolled units
$schedule = DB::rows(
    "SELECT cs.unit_code, cs.unit_name, cs.day_of_week,
            cs.start_time, cs.end_time, cs.room,
            u2.full_name AS lecturer_name
     FROM enrollments e
     JOIN units u    ON u.id  = e.unit_id
     JOIN users u2   ON u2.id = u.lecturer_id
     JOIN timetables t  ON t.lecturer_id      = u.lecturer_id
                        AND t.academic_year    = e.academic_year
                        AND t.semester         = e.semester
                        AND t.extraction_status = 'confirmed'
     JOIN class_schedules cs ON cs.timetable_id = t.id
                              AND cs.unit_code    = u.code
     WHERE e.student_id   = ?
       AND e.academic_year = ?
       AND e.semester      = ?
     GROUP BY cs.id
     ORDER BY cs.day_of_week, cs.start_time",
    [$user['id'], $academicYear, $semester]
);

$days = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

$byDay = [];
foreach ($schedule as $slot) {
    $byDay[$slot['day_of_week']][] = $slot;
}

// Notification preferences
$notifPrefs = DB::row(
    "SELECT email_enabled, notify_before_minutes FROM student_notification_preferences WHERE student_id = ?",
    [$user['id']]
) ?? ['email_enabled' => 0, 'notify_before_minutes' => 30];

$studentEmail = $user['email'] ?? '';

$csrfToken = Auth::csrfToken();
$pageTitle = 'Class Schedule';
?>
<!DOCTYPE html>
<html lang="en" data-base-url="<?= htmlspecialchars(BASE_URL) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
  <title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/base.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/student.css">
  <style>
    .week-grid   { display: grid; grid-template-columns: repeat(5, 1fr); gap: var(--space-3); }
    @media (max-width: 768px) { .week-grid { grid-template-columns: 1fr; } }
    .day-col     { min-width: 0; }
    .day-header  {
      font-weight: 700;
      font-size: var(--text-sm);
      text-transform: uppercase;
      letter-spacing: .05em;
      color: var(--color-text-muted);
      padding: var(--space-2) 0;
      border-bottom: 2px solid var(--color-border);
      margin-bottom: var(--space-2);
    }
    .class-card  {
      background: var(--color-accent-subtle, #f0fdf4);
      border-left: 3px solid var(--color-accent);
      border-radius: var(--radius-sm);
      padding: var(--space-2) var(--space-3);
      margin-bottom: var(--space-2);
      font-size: var(--text-sm);
    }
    .class-card .unit-code     { font-weight: 700; color: var(--color-accent); }
    .class-card .unit-name     { font-size: var(--text-xs); color: var(--color-text); margin: 2px 0; }
    .class-card .class-time    { font-size: var(--text-xs); color: var(--color-text-muted); }
    .class-card .lecturer-name { font-size: var(--text-xs); color: var(--color-text-muted); }
    .class-card .class-room    { font-size: var(--text-xs); color: var(--color-text-muted); }
    .no-classes { color: var(--color-text-muted); font-size: var(--text-xs); padding: var(--space-2) 0; }
    .empty-state {
      text-align: center;
      padding: var(--space-12) var(--space-6);
      color: var(--color-text-muted);
    }
    .empty-icon { font-size: 48px; margin-bottom: var(--space-3); }

    /* Notification panel */
    .notif-panel {
      display: flex; align-items: center; gap: var(--space-4); flex-wrap: wrap;
      padding: var(--space-4);
      background: var(--color-surface-raised);
      border-radius: var(--radius-md);
      border: 1px solid var(--color-border);
    }
    .toggle-wrap { display: flex; align-items: center; gap: var(--space-2); }
    .toggle { position: relative; width: 44px; height: 24px; }
    .toggle input { opacity: 0; width: 0; height: 0; }
    .toggle-slider {
      position: absolute; inset: 0;
      background: var(--color-border);
      border-radius: 24px; cursor: pointer; transition: background .2s;
    }
    .toggle-slider:before {
      content: ''; position: absolute;
      width: 18px; height: 18px; left: 3px; bottom: 3px;
      background: #fff; border-radius: 50%; transition: transform .2s;
    }
    .toggle input:checked + .toggle-slider { background: var(--color-accent); }
    .toggle input:checked + .toggle-slider:before { transform: translateX(20px); }
    .notif-label { font-weight: 600; font-size: var(--text-sm); }
    .notif-minutes {
      display: flex; align-items: center; gap: var(--space-2);
      font-size: var(--text-sm); color: var(--color-text-muted);
    }
    .notif-minutes input {
      width: 64px; padding: var(--space-1) var(--space-2);
      border: 1px solid var(--color-border); border-radius: var(--radius-sm);
      font-size: var(--text-sm); background: var(--color-surface);
      color: var(--color-text); text-align: center;
    }
  </style>
</head>
<body>
<div class="layout">
  <?php include __DIR__ . '/../partials/sidebar_student.php'; ?>

  <div class="main">
    <header class="topbar">
      <span class="topbar-title">Class Schedule</span>
      <div class="topbar-actions">
        <span class="text-sm text-muted">
          <?= htmlspecialchars($academicYear) ?> &nbsp;·&nbsp; Semester <?= $semester ?>
        </span>
      </div>
    </header>

    <div class="page-content">

    <!-- Notification preferences -->
    <?php if (!empty($schedule)): ?>
    <div class="card" style="margin-bottom:var(--space-6)">
      <div class="card-header">
        <h2 class="card-title">Class Reminders</h2>
      </div>
      <div class="card-body">
        <?php if (empty($studentEmail)): ?>
          <div class="alert alert-warning">
            Add an email address to your <a href="<?= BASE_URL ?>/student/profile">profile</a> to enable class reminders.
          </div>
        <?php else: ?>
        <div class="notif-panel">
          <label class="toggle-wrap">
            <label class="toggle">
              <input type="checkbox" id="notif-toggle"
                     <?= $notifPrefs['email_enabled'] ? 'checked' : '' ?>>
              <span class="toggle-slider"></span>
            </label>
            <span class="notif-label">Email reminders</span>
          </label>
          <div class="notif-minutes">
            <span>Send</span>
            <input type="number" id="notif-minutes" min="5" max="120" step="5"
                   value="<?= (int)$notifPrefs['notify_before_minutes'] ?>">
            <span>minutes before class</span>
          </div>
          <span id="notif-status" style="font-size:var(--text-xs);color:var(--color-text-muted)"></span>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Schedule card -->
    <div class="card">
      <div class="card-header">
        <h2 class="card-title">Weekly Timetable</h2>
      </div>
      <div class="card-body">

        <?php if (empty($schedule)): ?>
        <div class="empty-state">
          <div class="empty-icon">📅</div>
          <p>No class schedule is available yet.<br>
             Schedules appear here once your lecturers upload and confirm their timetables.</p>
        </div>

        <?php else: ?>

        <!-- Weekday grid (Mon-Fri) -->
        <div class="week-grid">
          <?php for ($d = 1; $d <= 5; $d++): ?>
          <div class="day-col">
            <div class="day-header"><?= $days[$d] ?></div>
            <?php if (!empty($byDay[$d])): ?>
              <?php foreach ($byDay[$d] as $slot): ?>
              <div class="class-card">
                <div class="unit-code"><?= htmlspecialchars($slot['unit_code']) ?></div>
                <?php if ($slot['unit_name']): ?>
                <div class="unit-name"><?= htmlspecialchars($slot['unit_name']) ?></div>
                <?php endif; ?>
                <div class="class-time">
                  <?= date('g:i A', strtotime($slot['start_time'])) ?>
                  – <?= date('g:i A', strtotime($slot['end_time'])) ?>
                </div>
                <?php if ($slot['room']): ?>
                <div class="class-room">📍 <?= htmlspecialchars($slot['room']) ?></div>
                <?php endif; ?>
                <div class="lecturer-name">👤 <?= htmlspecialchars($slot['lecturer_name']) ?></div>
              </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="no-classes">No classes</div>
            <?php endif; ?>
          </div>
          <?php endfor; ?>
        </div>

        <?php
        $hasWeekend = !empty($byDay[6]) || !empty($byDay[7]);
        if ($hasWeekend): ?>
        <div class="week-grid" style="grid-template-columns:repeat(2,1fr);margin-top:var(--space-4)">
          <?php foreach ([6, 7] as $d): ?>
          <div class="day-col">
            <div class="day-header"><?= $days[$d] ?></div>
            <?php if (!empty($byDay[$d])): ?>
              <?php foreach ($byDay[$d] as $slot): ?>
              <div class="class-card">
                <div class="unit-code"><?= htmlspecialchars($slot['unit_code']) ?></div>
                <div class="class-time">
                  <?= date('g:i A', strtotime($slot['start_time'])) ?>
                  – <?= date('g:i A', strtotime($slot['end_time'])) ?>
                </div>
                <?php if ($slot['room']): ?>
                <div class="class-room">📍 <?= htmlspecialchars($slot['room']) ?></div>
                <?php endif; ?>
                <div class="lecturer-name">👤 <?= htmlspecialchars($slot['lecturer_name']) ?></div>
              </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="no-classes">No classes</div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php endif; ?>

      </div>
    </div>

    </div><!-- /page-content -->
  </div><!-- /main -->
</div>

<?php include __DIR__ . '/../partials/ai_chat_widget.php'; ?>

<script>
(function () {
  const toggle  = document.getElementById('notif-toggle');
  const minutes = document.getElementById('notif-minutes');
  const status  = document.getElementById('notif-status');
  if (!toggle) return;

  async function save() {
    status.textContent = 'Saving…';
    try {
      const BASE = document.documentElement.dataset.baseUrl || '';
      const res  = await fetch(`${BASE}/api/timetable/preferences`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify({
          email_enabled: toggle.checked ? 1 : 0,
          notify_before_minutes: parseInt(minutes.value, 10) || 30,
        }),
      });
      const data = await res.json();
      status.textContent = data.success ? 'Saved' : (data.error || 'Error');
      // Update CSRF token if rotated
      const newToken = res.headers.get('X-New-CSRF-Token');
      if (newToken) {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) meta.content = newToken;
      }
    } catch {
      status.textContent = 'Network error';
    }
    setTimeout(() => { status.textContent = ''; }, 2500);
  }

  toggle.addEventListener('change', save);
  minutes.addEventListener('change', save);
})();
</script>
</body>
</html>
