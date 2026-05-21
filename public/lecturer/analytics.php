<?php
/**
 * EduTrack — Lecturer Analytics
 *
 * Shows attendance trend charts and at-risk student lists for
 * each unit taught by this lecturer.
 *
 * Charts:
 *   - Per-unit attendance trend line (sessions over time)
 *   - Per-unit attendance distribution bar (% ranges)
 *   - At-risk student list with quick-link to register
 */

define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/AttendanceModel.php';

Auth::startSession();
Auth::requireRole('lecturer');

$user = Auth::user();

$academicYear = DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year'"
)['setting_value'] ?? ACADEMIC_YEAR;

$semester = (int)(DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'active_semester'"
)['setting_value'] ?? ACTIVE_SEMESTER);

$threshold = (int)(DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'attendance_threshold'"
)['setting_value'] ?? ATTENDANCE_ALERT_THRESHOLD);

// ── Units taught ──────────────────────────────────────────────────────────────
$units = DB::rows(
    "SELECT u.id, u.code, u.name,
            COUNT(DISTINCT e.student_id) AS enrolled_count
     FROM units u
     LEFT JOIN enrollments e
           ON e.unit_id = u.id
          AND e.academic_year = ?
          AND e.semester      = ?
     WHERE u.lecturer_id = ? AND u.is_active = 1
     GROUP BY u.id, u.code, u.name
     ORDER BY u.code ASC",
    [$academicYear, $semester, $user['id']]
);

// ── Per-unit data for charts ──────────────────────────────────────────────────
$unitData = [];
foreach ($units as $unit) {
    $trend = AttendanceModel::getUnitTrend(
        $unit['id'], $academicYear, $semester
    );

    $atRisk = AttendanceModel::getAtRiskStudents(
        $unit['id'], $user['id'], $academicYear, $semester
    );

    // Summary stats
    $summary = DB::rows(
        "SELECT attendance_percent
         FROM vw_attendance_summary
         WHERE unit_id = ? AND academic_year = ? AND semester = ?",
        [$unit['id'], $academicYear, $semester]
    );

    $pcts = array_column($summary, 'attendance_percent');
    $avg  = count($pcts) > 0 ? round(array_sum($pcts) / count($pcts), 1) : 0;

    // Distribution buckets
    $dist = ['90-100' => 0, '75-89' => 0, '60-74' => 0, 'below-60' => 0];
    foreach ($pcts as $p) {
        if ($p >= 90)        $dist['90-100']++;
        elseif ($p >= 75)    $dist['75-89']++;
        elseif ($p >= 60)    $dist['60-74']++;
        else                 $dist['below-60']++;
    }

    $unitData[$unit['id']] = [
        'unit'    => $unit,
        'trend'   => $trend,
        'at_risk' => $atRisk,
        'avg'     => $avg,
        'dist'    => $dist,
    ];
}

$csrfToken = Auth::csrfToken();
$pageTitle = 'Analytics';
?>
<!DOCTYPE html>
<html lang="en" data-base-url="<?= htmlspecialchars(BASE_URL) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
  <title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/base.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/lecturer.css">
  <script src="<?= BASE_URL ?>/public/assets/vendor/chart.min.js"></script>
</head>
<body>
<div class="layout">

  <?php include __DIR__ . '/../partials/sidebar_lecturer.php'; ?>

  <div class="main">
    <header class="topbar">
      <span class="topbar-title">Analytics</span>
      <div class="topbar-actions">
        <span class="text-sm text-muted">
          <?= htmlspecialchars($academicYear) ?> · Semester <?= $semester ?>
          · Threshold: <?= $threshold ?>%
        </span>
      </div>
    </header>

    <div class="page-content">

      <?php if (empty($units)): ?>
        <div class="empty-state animate-fade-in">
          <span class="empty-icon">📊</span>
          <p class="empty-title">No units assigned</p>
          <p class="empty-text">
            Contact the administrator to be assigned to units.
          </p>
        </div>

      <?php else: ?>
        <?php foreach ($units as $unit):
          $data = $unitData[$unit['id']];

          // Prepare chart data (JSON for JS)
          $trendLabels = array_map(
              fn($t) => date('d M', strtotime($t['session_date'])),
              $data['trend']
          );
          $trendValues = array_column($data['trend'], 'percent');
          $distLabels  = ['90-100%', '75-89%', '60-74%', 'Below 60%'];
          $distValues  = array_values($data['dist']);
          $distColors  = ['#0F7B6C', '#1A3C5E', '#C47B12', '#D85A30'];
        ?>
          <div class="card animate-fade-in"
               style="margin-bottom:var(--space-6)">

            <!-- Unit header -->
            <div class="card-header">
              <div>
                <div style="display:flex;align-items:center;gap:var(--space-3)">
                  <span class="font-mono text-xs font-semibold text-accent">
                    <?= htmlspecialchars($unit['code']) ?>
                  </span>
                  <span class="card-title">
                    <?= htmlspecialchars($unit['name']) ?>
                  </span>
                </div>
                <div class="card-subtitle">
                  <?= $unit['enrolled_count'] ?> students enrolled
                  · Average attendance: <strong><?= $data['avg'] ?>%</strong>
                  <?php if ($data['avg'] < $threshold): ?>
                    <span style="color:var(--color-error)">
                      ⚠ Below threshold
                    </span>
                  <?php endif; ?>
                </div>
              </div>
              <div style="display:flex;gap:var(--space-3)">
                <a href="<?= BASE_URL ?>/api/reports/class_report.php?unit_id=<?= $unit['id'] ?>"
                   class="btn btn-secondary btn-sm" target="_blank">
                  🖨️ Export PDF
                </a>
              </div>
            </div>

            <div class="grid grid-2" style="gap:var(--space-5);margin-bottom:var(--space-5)">

              <!-- Trend line chart -->
              <div>
                <div class="section-title"
                     style="font-size:var(--text-sm);margin-bottom:var(--space-3)">
                  Attendance Trend
                </div>
                <?php if (empty($data['trend'])): ?>
                  <div class="empty-state" style="padding:var(--space-6) 0">
                    <span class="empty-icon" style="font-size:1.5rem">📊</span>
                    <p class="empty-text">No sessions recorded yet.</p>
                  </div>
                <?php else: ?>
                  <div class="chart-container">
                    <canvas id="trend-<?= $unit['id'] ?>"></canvas>
                  </div>
                <?php endif; ?>
              </div>

              <!-- Distribution bar chart -->
              <div>
                <div class="section-title"
                     style="font-size:var(--text-sm);margin-bottom:var(--space-3)">
                  Attendance Distribution
                </div>
                <?php if (array_sum($distValues) === 0): ?>
                  <div class="empty-state" style="padding:var(--space-6) 0">
                    <span class="empty-icon" style="font-size:1.5rem">📊</span>
                    <p class="empty-text">No attendance data yet.</p>
                  </div>
                <?php else: ?>
                  <div class="chart-container">
                    <canvas id="dist-<?= $unit['id'] ?>"></canvas>
                  </div>
                <?php endif; ?>
              </div>

            </div>

            <!-- At-risk students -->
            <?php if (!empty($data['at_risk'])): ?>
              <div style="border-top:1px solid var(--color-border-light);
                          padding-top:var(--space-5)">
                <div class="section-title"
                     style="font-size:var(--text-sm);color:var(--color-error);
                            margin-bottom:var(--space-3)">
                  ⚠️ At-Risk Students (below <?= $threshold ?>%)
                </div>
                <div class="table-wrap">
                  <table class="table">
                    <thead>
                      <tr>
                        <th>Student</th>
                        <th>Reg.</th>
                        <th>Attended</th>
                        <th>Sessions</th>
                        <th>Attendance</th>
                        <th>Contact</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($data['at_risk'] as $s):
                        $pct = (float)$s['attendance_percent'];
                        $col = $pct < 60 ? 'var(--color-error)' : 'var(--color-amber)';
                      ?>
                        <tr class="at-risk-row">
                          <td class="font-medium text-sm">
                            <?= htmlspecialchars($s['student_name']) ?>
                          </td>
                          <td class="font-mono text-xs">
                            <?= htmlspecialchars($s['student_name']) ?>
                          </td>
                          <td class="font-semibold" style="color:var(--color-success)">
                            <?= $s['attended'] ?>
                          </td>
                          <td class="text-muted"><?= $s['total_sessions'] ?></td>
                          <td class="font-mono font-semibold"
                              style="color:<?= $col ?>">
                            <?= $pct ?>%
                          </td>
                          <td class="text-xs text-muted">
                            <?= $s['phone']
                                ? htmlspecialchars($s['phone'])
                                : (htmlspecialchars($s['email'] ?? '—')) ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            <?php else: ?>
              <div class="alert alert-success"
                   style="border-top:1px solid var(--color-border-light);
                          border-radius:0 0 var(--radius-lg) var(--radius-lg);
                          margin:-1px">
                <span class="alert-icon">✅</span>
                <span>
                  All students are above the <?= $threshold ?>% attendance threshold.
                </span>
              </div>
            <?php endif; ?>

          </div><!-- /card -->

          <!-- Inject chart data for this unit -->
          <script>
          (function() {
            const trendLabels = <?= json_encode($trendLabels) ?>;
            const trendValues = <?= json_encode($trendValues) ?>;
            const distLabels  = <?= json_encode($distLabels) ?>;
            const distValues  = <?= json_encode($distValues) ?>;
            const distColors  = <?= json_encode($distColors) ?>;
            const threshold   = <?= json_encode($threshold) ?>;

            // Trend line
            if (trendLabels.length > 0) {
              const trendCtx = document.getElementById('trend-<?= $unit['id'] ?>');
              if (trendCtx) {
                new Chart(trendCtx, {
                  type: 'line',
                  data: {
                    labels: trendLabels,
                    datasets: [{
                      label: 'Attendance %',
                      data:  trendValues,
                      borderColor:     '#0F7B6C',
                      backgroundColor: 'rgba(15,123,108,0.08)',
                      borderWidth:     2,
                      pointRadius:     4,
                      pointBackgroundColor: '#0F7B6C',
                      tension: 0.3,
                      fill: true,
                    }],
                  },
                  options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                      legend: { display: false },
                      tooltip: {
                        callbacks: {
                          label: ctx => ` ${ctx.parsed.y}% attendance`,
                        },
                      },
                    },
                    scales: {
                      y: {
                        min: 0, max: 100,
                        ticks: { callback: v => v + '%' },
                        grid: { color: 'rgba(0,0,0,0.04)' },
                      },
                      x: { grid: { display: false } },
                    },
                    // Threshold reference line
                    plugins: {
                      annotation: undefined, // optional plugin
                    },
                  },
                });
              }
            }

            // Distribution bar
            if (distValues.some(v => v > 0)) {
              const distCtx = document.getElementById('dist-<?= $unit['id'] ?>');
              if (distCtx) {
                new Chart(distCtx, {
                  type: 'bar',
                  data: {
                    labels: distLabels,
                    datasets: [{
                      label: 'Students',
                      data:  distValues,
                      backgroundColor: distColors,
                      borderRadius: 6,
                    }],
                  },
                  options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                      legend: { display: false },
                      tooltip: {
                        callbacks: {
                          label: ctx => ` ${ctx.parsed.y} student(s)`,
                        },
                      },
                    },
                    scales: {
                      y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 },
                        grid: { color: 'rgba(0,0,0,0.04)' },
                      },
                      x: { grid: { display: false } },
                    },
                  },
                });
              }
            }
          })();
          </script>

        <?php endforeach; ?>
      <?php endif; ?>

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->

<nav class="mobile-nav">
  <a href="<?= BASE_URL ?>/public/lecturer/dashboard.php" class="mobile-nav-item">
    <span class="nav-icon">🏠</span><span>Home</span>
  </a>
  <a href="<?= BASE_URL ?>/public/lecturer/sessions.php" class="mobile-nav-item">
    <span class="nav-icon">📋</span><span>Sessions</span>
  </a>
  <a href="<?= BASE_URL ?>/public/lecturer/marks.php" class="mobile-nav-item">
    <span class="nav-icon">📝</span><span>Marks</span>
  </a>
  <a href="<?= BASE_URL ?>/public/lecturer/analytics.php" class="mobile-nav-item active">
    <span class="nav-icon">📊</span><span>Analytics</span>
  </a>
</nav>

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
</body>
</html>