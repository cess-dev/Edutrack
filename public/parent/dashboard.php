<?php
/**
 * EduTrack — Parent Dashboard
 *
 * Main landing page after parent login.
 * Shows:
 *   - All linked children in a tab/card switcher
 *   - Per-child attendance summary per unit with alert indicators
 *   - Per-child recent marks snapshot
 *   - Alert banner when any child is below the attendance threshold
 *   - Quick links to full attendance and marks pages
 *
 * Mobile-first: designed for parents checking on a phone.
 */

define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/UserModel.php';
require_once __DIR__ . '/../../backend/models/AttendanceModel.php';
require_once __DIR__ . '/../../backend/models/MarksModel.php';

Auth::startSession();
Auth::requireRole('parent');

$user = Auth::user();

// ── Page data ─────────────────────────────────────────────────────────────────
$academicYear = DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year'"
)['setting_value'] ?? ACADEMIC_YEAR;

$semester = (int)(DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'active_semester'"
)['setting_value'] ?? ACTIVE_SEMESTER);

$threshold = (int)(DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'attendance_threshold'"
)['setting_value'] ?? ATTENDANCE_ALERT_THRESHOLD);

// All children linked to this parent
$children = UserModel::getLinkedStudents($user['id']);

if (empty($children)) {
    // No children linked — show setup message
    $noChildren = true;
} else {
    $noChildren = false;

    // For each child, fetch attendance summary and recent marks
    $childData = [];
    foreach ($children as $child) {
        $attendance = AttendanceModel::getStudentSummary(
            $child['id'], $academicYear, $semester
        );

        // Compute overall average for this child
        $overallAvg = 0;
        if (!empty($attendance)) {
            $overallAvg = round(
                array_sum(array_column($attendance, 'attendance_percent')) / count($attendance),
                1
            );
        }

        // Count at-risk units
        $atRiskCount = count(array_filter(
            $attendance,
            fn($u) => (float)$u['attendance_percent'] < $threshold
        ));

        // Recent published marks (last 4)
        $recentMarks = DB::rows(
            "SELECT m.score, a.name AS assessment_name, a.max_score,
                    a.type, a.assessment_date, a.weight_percent,
                    u.code AS unit_code, u.name AS unit_name
             FROM marks m
             JOIN assessments a ON a.id = m.assessment_id
             JOIN units u       ON u.id = a.unit_id
             WHERE m.student_id  = ?
               AND a.is_published = 1
             ORDER BY a.assessment_date DESC
             LIMIT 4",
            [$child['id']]
        );

        $childData[$child['id']] = [
            'info'        => $child,
            'attendance'  => $attendance,
            'overall_avg' => $overallAvg,
            'at_risk'     => $atRiskCount,
            'marks'       => $recentMarks,
        ];
    }

    // Check if ANY child has attendance below threshold (for top-level alert)
    $hasAlert = array_filter($childData, fn($c) => $c['at_risk'] > 0);
}

$csrfToken  = Auth::csrfToken();
$pageTitle  = 'Dashboard';
$schoolName = DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'school_name'"
)['setting_value'] ?? SCHOOL_NAME;
?>
<!DOCTYPE html>
<html lang="en" data-base-url="<?= htmlspecialchars(BASE_URL) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
  <title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/base.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/parent.css">
</head>
<body>

<div class="layout">

  <?php include __DIR__ . '/../partials/sidebar_parent.php'; ?>

  <div class="main">

    <!-- Topbar -->
    <header class="topbar">
      <span class="topbar-title">Parent Dashboard</span>
      <div class="topbar-actions">
        <span class="text-sm text-muted hidden-mobile">
          <?= htmlspecialchars($schoolName) ?>
        </span>
        <a href="<?= BASE_URL ?>/api/auth/logout.php"
           class="btn btn-ghost btn-sm" data-logout>
          Sign out
        </a>
      </div>
    </header>

    <div class="page-content">

      <!-- ── Welcome header ────────────────────────────────────────────── -->
      <div class="parent-welcome animate-fade-in">
        <div class="parent-welcome-text">
          <h1 class="welcome-name">
            Welcome, <?= htmlspecialchars(explode(' ', $user['full_name'])[0]) ?>
          </h1>
          <p class="text-muted text-sm">
            <?= htmlspecialchars($academicYear) ?> · Semester <?= $semester ?> ·
            Monitoring <?= count($children) ?> student<?= count($children) !== 1 ? 's' : '' ?>
          </p>
        </div>
        <div class="school-badge">
          <span class="school-badge-icon">🏫</span>
          <span class="school-badge-name"><?= htmlspecialchars($schoolName) ?></span>
        </div>
      </div>

      <?php if ($noChildren): ?>
        <!-- ── No children linked ─────────────────────────────────────── -->
        <div class="empty-state" style="padding:var(--space-16) 0">
          <span class="empty-icon">👨‍👩‍👧</span>
          <p class="empty-title">No Students Linked</p>
          <p class="empty-text">
            Your account has not been linked to any student yet.
            Please contact the school administrator to link your account to your child.
          </p>
        </div>

      <?php else: ?>

        <!-- ── School-wide alert if any child is at risk ─────────────── -->
        <?php if (!empty($hasAlert)): ?>
          <div class="alert alert-error animate-fade-in" style="margin-bottom:var(--space-6)">
            <span class="alert-icon">🔔</span>
            <div>
              <strong>Attendance Alert:</strong>
              <?php
                $alertNames = array_map(
                    fn($c) => explode(' ', $c['info']['full_name'])[0],
                    array_values($hasAlert)
                );
                echo htmlspecialchars(implode(', ', $alertNames));
              ?>
              <?= count($hasAlert) === 1 ? 'has' : 'have' ?>
              one or more units below the
              <strong><?= $threshold ?>%</strong> attendance threshold.
              Please review the details below.
            </div>
          </div>
        <?php endif; ?>

        <!-- ── Child tabs (if multiple children) ─────────────────────── -->
        <?php if (count($children) > 1): ?>
          <div class="child-tabs animate-fade-in" id="child-tabs">
            <?php foreach ($children as $i => $child): ?>
              <button class="child-tab <?= $i === 0 ? 'active' : '' ?>"
                      onclick="switchChild('child-<?= $child['id'] ?>', this)">
                <span class="tab-avatar">
                  <?= strtoupper(substr($child['full_name'], 0, 1)) ?>
                </span>
                <span class="tab-name">
                  <?= htmlspecialchars(explode(' ', $child['full_name'])[0]) ?>
                </span>
                <?php if ($childData[$child['id']]['at_risk'] > 0): ?>
                  <span class="tab-alert">!</span>
                <?php endif; ?>
              </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- ── Per-child panels ───────────────────────────────────────── -->
        <?php foreach ($children as $i => $child):
          $data   = $childData[$child['id']];
          $hidden = $i > 0 ? 'style="display:none"' : '';
        ?>

          <div class="child-panel animate-fade-in"
               id="child-<?= $child['id'] ?>"
               <?= $hidden ?>
               style="animation-delay:<?= 0.1 + $i * 0.05 ?>s">

            <!-- Child identity strip -->
            <div class="child-identity-card">
              <div class="child-avatar-lg">
                <?= strtoupper(substr($child['full_name'], 0, 1)) ?>
              </div>
              <div class="child-identity-info">
                <div class="child-full-name">
                  <?= htmlspecialchars($child['full_name']) ?>
                </div>
                <div class="child-meta text-sm text-muted">
                  <span class="font-mono"><?= htmlspecialchars($child['reg_number']) ?></span>
                  &nbsp;·&nbsp;
                  <?= htmlspecialchars(ucfirst($child['relationship'])) ?>
                </div>
              </div>
              <div class="child-overall-badge
                <?= $data['overall_avg'] >= $threshold ? 'badge-ok' : 'badge-alert' ?>">
                <div class="child-overall-pct">
                  <?= $data['overall_avg'] ?>%
                </div>
                <div class="child-overall-label">Overall</div>
              </div>
            </div>

            <!-- Quick stats row -->
            <div class="grid-stats" style="margin:var(--space-5) 0">

              <div class="stat-card">
                <div class="stat-icon"
                     style="background:<?= $data['overall_avg'] >= $threshold ? 'var(--color-success-light)' : 'var(--color-error-light)' ?>;
                            color:<?= $data['overall_avg'] >= $threshold ? 'var(--color-success)' : 'var(--color-error)' ?>">
                  📊
                </div>
                <div class="stat-body">
                  <div class="stat-value"
                       style="color:<?= $data['overall_avg'] >= $threshold ? 'var(--color-success)' : 'var(--color-error)' ?>">
                    <?= $data['overall_avg'] ?>%
                  </div>
                  <div class="stat-label">Overall Attendance</div>
                </div>
              </div>

              <div class="stat-card">
                <div class="stat-icon" style="background:var(--color-accent-light);color:var(--color-accent)">
                  📚
                </div>
                <div class="stat-body">
                  <div class="stat-value"><?= count($data['attendance']) ?></div>
                  <div class="stat-label">Units Enrolled</div>
                </div>
              </div>

              <div class="stat-card">
                <div class="stat-icon"
                     style="background:<?= $data['at_risk'] > 0 ? 'var(--color-error-light)' : 'var(--color-bg-inset)' ?>;
                            color:<?= $data['at_risk'] > 0 ? 'var(--color-error)' : 'var(--color-text-muted)' ?>">
                  ⚠️
                </div>
                <div class="stat-body">
                  <div class="stat-value"><?= $data['at_risk'] ?></div>
                  <div class="stat-label">Units Below <?= $threshold ?>%</div>
                </div>
              </div>

            </div>

            <div class="grid grid-2" style="gap:var(--space-5)">

              <!-- Attendance per unit -->
              <div class="card">
                <div class="card-header">
                  <div>
                    <div class="card-title">Attendance by Unit</div>
                    <div class="card-subtitle">Sem <?= $semester ?>, <?= $academicYear ?></div>
                  </div>
                  <a href="<?= BASE_URL ?>/public/parent/attendance.php?student_id=<?= $child['id'] ?>"
                     class="btn btn-secondary btn-sm">Details</a>
                </div>

                <?php if (empty($data['attendance'])): ?>
                  <div class="empty-state" style="padding:var(--space-8) 0">
                    <span class="empty-icon">📚</span>
                    <p class="empty-text">No attendance data yet for this semester.</p>
                  </div>
                <?php else: ?>
                  <div class="parent-unit-list">
                    <?php foreach ($data['attendance'] as $unit):
                      $pct    = (float)$unit['attendance_percent'];
                      $status = $pct >= $threshold ? 'high' : ($pct >= 60 ? 'medium' : 'low');
                      $col    = $pct >= $threshold
                          ? 'var(--color-success)'
                          : ($pct >= 60 ? 'var(--color-amber)' : 'var(--color-error)');
                    ?>
                      <div class="parent-unit-row
                           <?= $pct < $threshold ? 'unit-at-risk' : '' ?>">
                        <div class="parent-unit-info">
                          <div class="parent-unit-code font-mono text-xs">
                            <?= htmlspecialchars($unit['unit_code']) ?>
                          </div>
                          <div class="parent-unit-name text-sm">
                            <?= htmlspecialchars($unit['unit_name']) ?>
                          </div>
                          <div class="text-xs text-muted">
                            <?= $unit['attended'] ?>/<?= $unit['total_sessions'] ?> sessions
                          </div>
                        </div>
                        <div style="flex:1;min-width:0">
                          <div class="attendance-bar">
                            <div class="bar-track">
                              <div class="bar-fill"
                                   data-pct="<?= $status ?>"
                                   style="width:<?= $pct ?>%">
                              </div>
                            </div>
                          </div>
                        </div>
                        <div class="parent-unit-pct font-mono"
                             style="color:<?= $col ?>">
                          <?= $pct ?>%
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>

              <!-- Recent marks -->
              <div class="card">
                <div class="card-header">
                  <div>
                    <div class="card-title">Recent Marks</div>
                    <div class="card-subtitle">Latest published grades</div>
                  </div>
                  <a href="<?= BASE_URL ?>/public/parent/marks.php?student_id=<?= $child['id'] ?>"
                     class="btn btn-secondary btn-sm">All Marks</a>
                </div>

                <?php if (empty($data['marks'])): ?>
                  <div class="empty-state" style="padding:var(--space-8) 0">
                    <span class="empty-icon">📝</span>
                    <p class="empty-text">No marks published yet.</p>
                  </div>
                <?php else: ?>
                  <div class="parent-marks-list">
                    <?php foreach ($data['marks'] as $mark):
                      $pct   = round(($mark['score'] / $mark['max_score']) * 100);
                      $grade = MarksModel::computeGrade($pct);
                    ?>
                      <div class="parent-mark-row">
                        <div class="grade-pill grade-<?= $grade['grade'] ?>">
                          <?= $grade['grade'] ?>
                        </div>
                        <div class="parent-mark-info">
                          <div class="text-sm font-medium">
                            <?= htmlspecialchars($mark['assessment_name']) ?>
                          </div>
                          <div class="text-xs text-muted">
                            <?= htmlspecialchars($mark['unit_code']) ?> ·
                            <?= date('d M Y', strtotime($mark['assessment_date'])) ?>
                          </div>
                        </div>
                        <div class="parent-mark-score">
                          <span class="font-semibold text-sm"><?= $mark['score'] ?></span>
                          <span class="text-xs text-muted">/ <?= $mark['max_score'] ?></span>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>

            </div><!-- /grid -->

            <!-- Quick action links for this child -->
            <div class="parent-quick-actions">
              <a href="<?= BASE_URL ?>/public/parent/attendance.php?student_id=<?= $child['id'] ?>"
                 class="quick-action-btn">
                <span>📋</span>
                <span>Full Attendance</span>
              </a>
              <a href="<?= BASE_URL ?>/public/parent/marks.php?student_id=<?= $child['id'] ?>"
                 class="quick-action-btn">
                <span>📝</span>
                <span>All Marks</span>
              </a>
              <a href="<?= BASE_URL ?>/public/parent/transcript.php?student_id=<?= $child['id'] ?>"
                 class="quick-action-btn">
                <span>🎓</span>
                <span>Transcript</span>
              </a>
            </div>

          </div><!-- /child-panel -->

        <?php endforeach; ?>

      <?php endif; ?>

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->

<!-- Mobile bottom nav -->
<nav class="mobile-nav">
  <a href="<?= BASE_URL ?>/public/parent/dashboard.php" class="mobile-nav-item active">
    <span class="nav-icon">🏠</span><span>Home</span>
  </a>
  <a href="<?= BASE_URL ?>/public/parent/attendance.php" class="mobile-nav-item">
    <span class="nav-icon">📋</span><span>Attendance</span>
  </a>
  <a href="<?= BASE_URL ?>/public/parent/marks.php" class="mobile-nav-item">
    <span class="nav-icon">📝</span><span>Marks</span>
  </a>
  <a href="<?= BASE_URL ?>/public/parent/profile.php" class="mobile-nav-item">
    <span class="nav-icon">👤</span><span>Profile</span>
  </a>
</nav>

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
<script>
// ── Child tab switcher ────────────────────────────────────────────────────────
function switchChild(panelId, tabEl) {
  // Hide all panels
  document.querySelectorAll('.child-panel').forEach(p => p.style.display = 'none');
  // Deactivate all tabs
  document.querySelectorAll('.child-tab').forEach(t => t.classList.remove('active'));

  // Show selected panel and activate tab
  const panel = document.getElementById(panelId);
  if (panel) panel.style.display = 'block';
  if (tabEl) tabEl.classList.add('active');
}
</script>

</body>
</html>