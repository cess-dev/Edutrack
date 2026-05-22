<?php
/**
 * EduTrack — Student Dashboard
 *
 * Main landing page after student login.
 * Shows:
 *   - Attendance summary cards per enrolled unit (percentage + bar)
 *   - Overall attendance average across all units
 *   - Quick-access QR scan button
 *   - Recent attendance log (last 5 entries)
 *   - Marks snapshot (latest published assessments)
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/AttendanceModel.php';
require_once __DIR__ . '/../../backend/models/MarksModel.php';

Auth::startSession();
Auth::requireRole('student');

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

// Attendance summary per unit (from vw_attendance_summary)
$attendanceSummary = AttendanceModel::getStudentSummary(
    $user['id'], $academicYear, $semester
);

// Overall average attendance
$overallAvg = 0;
if (!empty($attendanceSummary)) {
    $total = array_sum(array_column($attendanceSummary, 'attendance_percent'));
    $overallAvg = round($total / count($attendanceSummary), 1);
}

// Recent attendance log (last 5 entries)
$recentLog = AttendanceModel::getStudentHistory($user['id'], 1, 5);

// Latest published marks (last 5)
$recentMarks = DB::rows(
    "SELECT m.score, a.name AS assessment_name, a.max_score,
            a.type, a.assessment_date,
            u.code AS unit_code, u.name AS unit_name
     FROM marks m
     JOIN assessments a ON a.id = m.assessment_id
     JOIN units u       ON u.id = a.unit_id
     WHERE m.student_id   = ?
       AND a.is_published  = 1
     ORDER BY a.assessment_date DESC, m.uploaded_at DESC
     LIMIT 5",
    [$user['id']]
);

// Pending disputes
$pendingDisputes = (int)(DB::row(
    "SELECT COUNT(*) AS cnt FROM disputes
     WHERE student_id = ? AND status = 'pending'",
    [$user['id']]
)['cnt'] ?? 0);

$csrfToken = Auth::csrfToken();
$pageTitle = 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en" data-base-url="<?= htmlspecialchars(BASE_URL) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
  <title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars(APP_NAME) ?> Student</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/base.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/student.css">
</head>
<body>

<div class="layout">

  <?php include __DIR__ . '/../partials/sidebar_student.php'; ?>

  <div class="main">

    <!-- Topbar -->
    <header class="topbar">
      <span class="topbar-title">Dashboard</span>
      <div class="topbar-actions">
        <span class="text-sm text-muted">
          <?= htmlspecialchars($academicYear) ?> &nbsp;·&nbsp; Sem <?= $semester ?>
        </span>
        <a href="<?= BASE_URL ?>/student/scan"
           class="btn btn-primary btn-sm">
          📷 Scan QR
        </a>
        <a href="<?= BASE_URL ?>/api/auth/logout"
           class="btn btn-ghost btn-sm" data-logout>
          Sign out
        </a>
      </div>
    </header>

    <div class="page-content">

      <!-- Welcome strip -->
      <div class="welcome-strip animate-fade-in">
        <div>
          <h1 class="welcome-name">
            Hello, <?= htmlspecialchars(explode(' ', $user['full_name'])[0]) ?> 👋
          </h1>
          <p class="text-muted text-sm">
            <?= htmlspecialchars($academicYear) ?> · Semester <?= $semester ?> ·
            <span class="font-mono"><?= htmlspecialchars($user['reg_number']) ?></span>
          </p>
        </div>
        <a href="<?= BASE_URL ?>/student/scan"
           class="btn btn-primary scan-cta">
          <span style="font-size:1.2em">📷</span>
          Scan Attendance QR
        </a>
      </div>

      <!-- ── Overall attendance alert ──────────────────────────────────── -->
      <?php if (!empty($attendanceSummary) && $overallAvg < $threshold): ?>
        <div class="alert alert-error animate-fade-in" style="margin-bottom:var(--space-6)">
          <span class="alert-icon">⚠️</span>
          <div>
            <strong>Attendance Warning:</strong> Your overall attendance is
            <strong><?= $overallAvg ?>%</strong>, below the required
            <strong><?= $threshold ?>%</strong> threshold.
            Check the units below and contact your lecturer if needed.
          </div>
        </div>
      <?php endif; ?>

      <!-- ── Top stat strip ─────────────────────────────────────────────── -->
      <div class="grid-stats" style="margin-bottom:var(--space-8)">

        <div class="stat-card animate-fade-in" style="animation-delay:0.05s">
          <div class="stat-icon"
               style="background:<?= $overallAvg >= $threshold ? 'var(--color-success-light)' : 'var(--color-error-light)' ?>;
                      color:<?= $overallAvg >= $threshold ? 'var(--color-success)' : 'var(--color-error)' ?>">
            📊
          </div>
          <div class="stat-body">
            <div class="stat-value"
                 style="color:<?= $overallAvg >= $threshold ? 'var(--color-success)' : 'var(--color-error)' ?>">
              <?= $overallAvg ?>%
            </div>
            <div class="stat-label">Overall Attendance</div>
          </div>
        </div>

        <div class="stat-card animate-fade-in" style="animation-delay:0.1s">
          <div class="stat-icon" style="background:var(--color-accent-light);color:var(--color-accent)">
            📚
          </div>
          <div class="stat-body">
            <div class="stat-value"><?= count($attendanceSummary) ?></div>
            <div class="stat-label">Enrolled Units</div>
          </div>
        </div>

        <div class="stat-card animate-fade-in" style="animation-delay:0.15s">
          <div class="stat-icon" style="background:var(--color-amber-light);color:var(--color-amber)">
            ⚠️
          </div>
          <div class="stat-body">
            <div class="stat-value">
              <?= count(array_filter($attendanceSummary, fn($u) => $u['attendance_percent'] < $threshold)) ?>
            </div>
            <div class="stat-label">Units Below <?= $threshold ?>%</div>
          </div>
        </div>

        <div class="stat-card animate-fade-in" style="animation-delay:0.2s">
          <div class="stat-icon"
               style="background:<?= $pendingDisputes > 0 ? 'var(--color-error-light)' : 'var(--color-bg-inset)' ?>;
                      color:<?= $pendingDisputes > 0 ? 'var(--color-error)' : 'var(--color-text-muted)' ?>">
            🔔
          </div>
          <div class="stat-body">
            <div class="stat-value"><?= $pendingDisputes ?></div>
            <div class="stat-label">Pending Disputes</div>
          </div>
        </div>

      </div>

      <!-- ── Attendance per unit ────────────────────────────────────────── -->
      <div class="card animate-fade-in" style="margin-bottom:var(--space-6);animation-delay:0.25s">
        <div class="card-header">
          <div>
            <div class="card-title">Attendance by Unit</div>
            <div class="card-subtitle">
              <?= htmlspecialchars($academicYear) ?> · Semester <?= $semester ?>
            </div>
          </div>
          <a href="<?= BASE_URL ?>/student/attendance"
             class="btn btn-secondary btn-sm">
            Full History
          </a>
        </div>

        <?php if (empty($attendanceSummary)): ?>
          <div class="empty-state" style="padding:var(--space-10) 0">
            <span class="empty-icon">📚</span>
            <p class="empty-title">No units found</p>
            <p class="empty-text">
              You have not been enrolled in any units yet.
              Contact your administrator.
            </p>
          </div>
        <?php else: ?>
          <div class="unit-attendance-list">
            <?php foreach ($attendanceSummary as $unit): ?>
              <?php
                $pct     = (float) $unit['attendance_percent'];
                $barPct  = $pct === null ? 0 : $pct;
                $status  = $pct >= $threshold ? 'high' : ($pct >= 60 ? 'medium' : 'low');
                $textCol = $pct >= $threshold
                    ? 'var(--color-success)'
                    : ($pct >= 60 ? 'var(--color-amber)' : 'var(--color-error)');
              ?>
              <div class="unit-attendance-row">
                <div class="unit-att-info">
                  <div class="unit-att-code"><?= htmlspecialchars($unit['unit_code']) ?></div>
                  <div class="unit-att-name"><?= htmlspecialchars($unit['unit_name']) ?></div>
                  <div class="unit-att-detail text-xs text-muted">
                    <?= $unit['attended'] ?> / <?= $unit['total_sessions'] ?> sessions attended
                    <?php if ($unit['excused'] > 0): ?>
                      &nbsp;·&nbsp; <?= $unit['excused'] ?> excused
                    <?php endif; ?>
                  </div>
                </div>
                <div class="unit-att-bar-wrap">
                  <div class="attendance-bar">
                    <div class="bar-track">
                      <div class="bar-fill"
                           data-pct="<?= $status ?>"
                           style="width:<?= $barPct ?>%">
                      </div>
                    </div>
                  </div>
                  <?php if ($pct < $threshold): ?>
                    <div class="text-xs" style="color:var(--color-error);margin-top:2px">
                      <?= $threshold - $pct ?>% below threshold
                    </div>
                  <?php endif; ?>
                </div>
                <div class="unit-att-pct" style="color:<?= $textCol ?>">
                  <?= $pct !== null ? $pct . '%' : 'N/A' ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="grid grid-2" style="gap:var(--space-6)">

        <!-- ── Recent attendance log ──────────────────────────────────── -->
        <div class="card animate-fade-in" style="animation-delay:0.3s">
          <div class="card-header">
            <div class="card-title">Recent Attendance</div>
            <a href="<?= BASE_URL ?>/student/attendance"
               class="btn btn-secondary btn-sm">View all</a>
          </div>

          <?php if (empty($recentLog['rows'])): ?>
            <div class="empty-state" style="padding:var(--space-8) 0">
              <span class="empty-icon">📋</span>
              <p class="empty-title">No records yet</p>
              <p class="empty-text">Scan a QR code in class to register attendance.</p>
            </div>
          <?php else: ?>
            <div class="recent-log-list">
              <?php foreach ($recentLog['rows'] as $log): ?>
                <div class="log-row">
                  <div class="log-status-icon">
                    <?php if ($log['status'] === 'present'): ?>
                      <span class="status-dot dot-present"></span>
                    <?php elseif ($log['status'] === 'excused'): ?>
                      <span class="status-dot dot-excused"></span>
                    <?php else: ?>
                      <span class="status-dot dot-absent"></span>
                    <?php endif; ?>
                  </div>
                  <div class="log-info">
                    <div class="log-unit">
                      <span class="font-mono text-xs"><?= htmlspecialchars($log['unit_code']) ?></span>
                      &nbsp;—&nbsp;
                      <span class="text-sm"><?= htmlspecialchars($log['unit_name']) ?></span>
                    </div>
                    <div class="log-meta text-xs text-muted">
                      <?= date('D d M, H:i', strtotime($log['started_at'])) ?>
                      &nbsp;·&nbsp;
                      <?= htmlspecialchars($log['lecturer_name']) ?>
                    </div>
                  </div>
                  <div class="log-badge">
                    <span class="badge badge-<?= $log['status'] ?>">
                      <?= ucfirst($log['status']) ?>
                    </span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- ── Recent marks ───────────────────────────────────────────── -->
        <div class="card animate-fade-in" style="animation-delay:0.35s">
          <div class="card-header">
            <div class="card-title">Recent Marks</div>
            <a href="<?= BASE_URL ?>/student/marks"
               class="btn btn-secondary btn-sm">View all</a>
          </div>

          <?php if (empty($recentMarks)): ?>
            <div class="empty-state" style="padding:var(--space-8) 0">
              <span class="empty-icon">📝</span>
              <p class="empty-title">No marks published yet</p>
              <p class="empty-text">
                Your lecturer will publish marks once assessments are graded.
              </p>
            </div>
          <?php else: ?>
            <div class="marks-preview-list">
              <?php foreach ($recentMarks as $mark): ?>
                <?php
                  $scorePct = round(($mark['score'] / $mark['max_score']) * 100);
                  $grade    = MarksModel::computeGrade(
                      ($mark['score'] / $mark['max_score']) * 100
                  );
                ?>
                <div class="mark-preview-row">
                  <div class="grade-pill grade-<?= $grade['grade'] ?>">
                    <?= $grade['grade'] ?>
                  </div>
                  <div class="mark-info">
                    <div class="mark-name text-sm font-medium">
                      <?= htmlspecialchars($mark['assessment_name']) ?>
                    </div>
                    <div class="mark-unit text-xs text-muted">
                      <?= htmlspecialchars($mark['unit_code']) ?> ·
                      <?= date('d M Y', strtotime($mark['assessment_date'])) ?>
                    </div>
                  </div>
                  <div class="mark-score">
                    <span class="font-semibold"><?= htmlspecialchars($mark['score']) ?></span>
                    <span class="text-muted text-xs">/ <?= htmlspecialchars($mark['max_score']) ?></span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

      </div><!-- /grid -->

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->

<!-- ── Mobile bottom nav ──────────────────────────────────────────────────── -->
<nav class="mobile-nav">
  <a href="<?= BASE_URL ?>/student/dashboard" class="mobile-nav-item active">
    <span class="nav-icon">🏠</span><span>Home</span>
  </a>
  <a href="<?= BASE_URL ?>/student/scan" class="mobile-nav-item">
    <span class="nav-icon">📷</span><span>Scan</span>
  </a>
  <a href="<?= BASE_URL ?>/student/attendance" class="mobile-nav-item">
    <span class="nav-icon">📋</span><span>Attendance</span>
  </a>
  <a href="<?= BASE_URL ?>/student/marks" class="mobile-nav-item">
    <span class="nav-icon">📝</span><span>Marks</span>
  </a>
</nav>

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>

</body>
</html>