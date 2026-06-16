<?php
/**
 * EduTrack — Parent: Child's Class Schedule
 *
 * Shows a read-only weekly timetable for the selected child based on
 * the schedules uploaded and confirmed by each subject's lecturer.
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/UserModel.php';

Auth::startSession();
Auth::requireRole('parent');

$user     = Auth::user();
$children = UserModel::getLinkedStudents($user['id']);

// Select child
$studentId = (int)($_GET['student_id'] ?? ($children[0]['id'] ?? 0));
$child     = null;
foreach ($children as $c) {
    if ($c['id'] === $studentId) { $child = $c; break; }
}

if (!$child && !empty($children)) {
    $child     = $children[0];
    $studentId = $child['id'];
}

$academicYear = DB::row("SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year'")['setting_value'] ?? ACADEMIC_YEAR;
$semester     = (int)(DB::row("SELECT setting_value FROM system_settings WHERE setting_key = 'active_semester'")['setting_value'] ?? ACTIVE_SEMESTER);

// Fetch schedule for the selected student
$schedule = [];
if ($child) {
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
        [$studentId, $academicYear, $semester]
    );
}

$days = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

// Group by day
$byDay = [];
foreach ($schedule as $slot) {
    $byDay[$slot['day_of_week']][] = $slot;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Class Schedule — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/base.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/parent.css">
  <style>
    .child-tabs { display: flex; gap: var(--space-2); margin-bottom: var(--space-6); flex-wrap: wrap; }
    .child-tab  {
      padding: var(--space-2) var(--space-4);
      border-radius: var(--radius-full, 9999px);
      border: 1px solid var(--color-border);
      background: var(--color-surface);
      color: var(--color-text);
      text-decoration: none;
      font-size: var(--text-sm);
      font-weight: 500;
      display: flex; align-items: center; gap: var(--space-2);
    }
    .child-tab.active {
      background: var(--color-accent);
      border-color: var(--color-accent);
      color: #fff;
    }
    .child-avatar {
      width: 22px; height: 22px;
      border-radius: 50%;
      background: rgba(255,255,255,.3);
      display: grid; place-items: center;
      font-size: 11px; font-weight: 700;
    }
    .child-tab:not(.active) .child-avatar {
      background: var(--color-accent);
      color: #fff;
    }

    /* Weekly grid */
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
  </style>
</head>
<body>
<div class="layout">
  <?php require_once __DIR__ . '/../partials/sidebar_parent.php'; ?>

  <main class="main-content">
    <div class="page-header">
      <h1 class="page-title">Class Schedule</h1>
      <p class="page-subtitle">
        <?= htmlspecialchars($academicYear) ?> &nbsp;·&nbsp; Semester <?= $semester ?>
      </p>
    </div>

    <?php if (empty($children)): ?>
      <div class="empty-state">
        <div class="empty-icon">👨‍👩‍👧</div>
        <p>No students are linked to your account.<br>Contact the school administrator.</p>
      </div>
    <?php else: ?>

      <!-- Child tabs -->
      <?php if (count($children) > 1): ?>
      <div class="child-tabs">
        <?php foreach ($children as $ch): ?>
        <a href="?student_id=<?= $ch['id'] ?>"
           class="child-tab <?= $ch['id'] === $studentId ? 'active' : '' ?>">
          <div class="child-avatar"><?= strtoupper(substr($ch['full_name'], 0, 1)) ?></div>
          <span><?= htmlspecialchars(explode(' ', $ch['full_name'])[0]) ?></span>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Schedule card -->
      <div class="card">
        <div class="card-header">
          <h2 class="card-title">
            <?= htmlspecialchars($child['full_name'] ?? '') ?>'s Weekly Timetable
          </h2>
          <p style="font-size:var(--text-xs);color:var(--color-text-muted);margin:var(--space-1) 0 0">
            <?= htmlspecialchars($child['reg_number'] ?? '') ?>
          </p>
        </div>
        <div class="card-body">

          <?php if (empty($schedule)): ?>
          <div class="empty-state">
            <div class="empty-icon">📅</div>
            <p>No class schedule is available yet.<br>
               Schedules appear here once lecturers upload and confirm their timetables.</p>
          </div>

          <?php else: ?>

          <!-- Weekday grid (Mon–Fri) -->
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
          // Sat/Sun only if data exists
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

    <?php endif; ?>
  </main>
</div>

<?php require_once __DIR__ . '/../partials/parent_ai_widget.php'; ?>
</body>
</html>
