<?php
/**
 * EduTrack — Admin Attendance Overview
 *
 * Shows active attendance sessions, at-risk student counts,
 * and a quick summary for the current academic year and semester.
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/AttendanceModel.php';

Auth::startSession();
Auth::requireRole('admin');

$user = Auth::user();

$academicYear = DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year'"
)['setting_value'] ?? ACADEMIC_YEAR;

$semester = (int)(DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'active_semester'"
)['setting_value'] ?? ACTIVE_SEMESTER);

$pendingDisputes = (int)(DB::row(
    "SELECT COUNT(*) AS cnt FROM disputes WHERE status = 'pending'"
)['cnt'] ?? 0);

$activeSessions = DB::rows(
    "SELECT s.id, s.started_at, s.expires_at, s.is_active,
            u.code AS unit_code, u.name AS unit_name,
            lec.full_name AS lecturer_name,
            COUNT(CASE WHEN al.status = 'present' THEN 1 END) AS present_count,
            COUNT(al.id) AS total_logged
     FROM attendance_sessions s
     JOIN units u ON u.id = s.unit_id
     JOIN users lec ON lec.id = s.lecturer_id
     LEFT JOIN attendance_logs al ON al.session_id = s.id
     WHERE s.is_active = 1
     GROUP BY s.id, s.started_at, s.expires_at, s.is_active,
              u.code, u.name, lec.full_name
     ORDER BY s.started_at DESC"
);

$activeSessionCount = count($activeSessions);

$totalSessions = (int)(DB::row(
    "SELECT COUNT(*) AS cnt FROM attendance_sessions WHERE academic_year = ? AND semester = ?",
    [$academicYear, $semester]
)['cnt'] ?? 0);

$atRiskStudents = AttendanceModel::getAtRiskStudents(0, 0, $academicYear, $semester);
$atRiskCount = count($atRiskStudents);

$csrfToken = Auth::csrfToken();
$pageTitle = 'Attendance Overview';
?>
<!DOCTYPE html>
<html lang="en" data-base-url="<?= htmlspecialchars(BASE_URL) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
  <title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/base.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/admin.css">
</head>
<body>
<div class="layout">

  <?php include __DIR__ . '/../partials/sidebar_admin.php'; ?>

  <div class="main">
    <header class="topbar">
      <span class="topbar-title">Attendance Overview</span>
      <div class="topbar-actions">
        <a href="<?= BASE_URL ?>/admin/disputes" class="btn btn-secondary btn-sm">
          View Disputes
        </a>
      </div>
    </header>

    <div class="page-content">

      <div class="admin-welcome animate-fade-in">
        <div>
          <h1 class="welcome-name">Attendance Overview</h1>
          <p class="text-muted text-sm">
            <?= htmlspecialchars($academicYear) ?> · Semester <?= $semester ?>
          </p>
        </div>
      </div>

      <div class="grid-stats animate-fade-in" style="margin-bottom:var(--space-7)">
        <div class="stat-card" style="animation-delay:0.05s">
          <div class="stat-icon" style="background:var(--color-success-light);color:var(--color-success)">
            ▶️
          </div>
          <div class="stat-body">
            <div class="stat-value"><?= $activeSessionCount ?></div>
            <div class="stat-label">Live Sessions</div>
            <div class="text-xs text-muted" style="margin-top:4px">
              <?= $activeSessionCount === 1 ? 'Currently active' : 'Currently active' ?>
            </div>
          </div>
        </div>

        <div class="stat-card" style="animation-delay:0.1s">
          <div class="stat-icon" style="background:var(--color-amber-light);color:var(--color-amber)">
            📉
          </div>
          <div class="stat-body">
            <div class="stat-value"><?= $atRiskCount ?></div>
            <div class="stat-label">At-Risk Records</div>
            <div class="text-xs text-muted" style="margin-top:4px">
              Students below attendance threshold
            </div>
          </div>
        </div>

        <div class="stat-card" style="animation-delay:0.15s">
          <div class="stat-icon" style="background:var(--color-error-light);color:var(--color-error)">
            ⚠️
          </div>
          <div class="stat-body">
            <div class="stat-value"><?= $pendingDisputes ?></div>
            <div class="stat-label">Pending Disputes</div>
            <div class="text-xs text-muted" style="margin-top:4px">
              Needs lecturer review
            </div>
          </div>
        </div>

        <div class="stat-card" style="animation-delay:0.2s">
          <div class="stat-icon" style="background:var(--color-bg-inset);color:var(--color-text-muted)">
            📚
          </div>
          <div class="stat-body">
            <div class="stat-value"><?= $totalSessions ?></div>
            <div class="stat-label">Total Sessions</div>
            <div class="text-xs text-muted" style="margin-top:4px">
              This academic year & semester
            </div>
          </div>
        </div>
      </div>

      <div class="grid grid-2" style="gap:var(--space-4);margin-top:var(--space-8)">
        <div class="card animate-fade-in" style="animation-delay:0.05s">
          <h2 class="card-title">Active Sessions</h2>
          <?php if (empty($activeSessions)): ?>
            <div class="empty-state" style="padding:var(--space-10) 0">
              <span class="empty-icon">🕒</span>
              <p class="empty-title">No live attendance sessions</p>
              <p class="empty-text">No active sessions are running right now.</p>
            </div>
          <?php else: ?>
            <div class="table-wrap">
              <table class="table">
                <thead>
                  <tr>
                    <th>Session</th>
                    <th>Unit</th>
                    <th>Lecturer</th>
                    <th>Started</th>
                    <th>Present</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($activeSessions as $session): ?>
                    <tr>
                      <td>#<?= $session['id'] ?></td>
                      <td>
                        <span class="badge badge-info font-mono text-xs">
                          <?= htmlspecialchars($session['unit_code']) ?>
                        </span>
                        <div class="text-xs text-muted" style="margin-top:2px">
                          <?= htmlspecialchars($session['unit_name']) ?>
                        </div>
                      </td>
                      <td class="text-xs text-muted">
                        <?= htmlspecialchars($session['lecturer_name']) ?>
                      </td>
                      <td class="text-xs text-muted">
                        <?= date('d M Y, H:i', strtotime($session['started_at'])) ?>
                      </td>
                      <td class="text-xs text-muted">
                        <?= $session['present_count'] ?> / <?= max(1, $session['total_logged']) ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <div class="card animate-fade-in" style="animation-delay:0.1s">
          <h2 class="card-title">At-Risk Students</h2>
          <?php if (empty($atRiskStudents)): ?>
            <div class="empty-state" style="padding:var(--space-10) 0">
              <span class="empty-icon">✅</span>
              <p class="empty-title">No at-risk records</p>
              <p class="empty-text">All tracked students are above the attendance threshold.</p>
            </div>
          <?php else: ?>
            <div class="table-wrap">
              <table class="table">
                <thead>
                  <tr>
                    <th>Student</th>
                    <th>Unit</th>
                    <th>Attendance</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach (array_slice($atRiskStudents, 0, 10) as $item): ?>
                    <tr>
                      <td>
                        <?= htmlspecialchars($item['student_name']) ?>
                        <div class="text-xs text-muted"><?= htmlspecialchars($item['student_id']) ?></div>
                      </td>
                      <td>
                        <span class="badge badge-info font-mono text-xs">
                          <?= htmlspecialchars($item['unit_code']) ?>
                        </span>
                        <div class="text-xs text-muted" style="margin-top:2px">
                          <?= htmlspecialchars($item['unit_name']) ?>
                        </div>
                      </td>
                      <td class="text-xs text-danger">
                        <?= number_format($item['attendance_percent'], 1) ?>%
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php if (count($atRiskStudents) > 10): ?>
              <div class="text-xs text-muted" style="margin-top:var(--space-4)">
                Showing first 10 of <?= $atRiskCount ?> at-risk records.
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
</body>
</html>
