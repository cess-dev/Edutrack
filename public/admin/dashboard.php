<?php
/**
 * EduTrack — Admin Dashboard
 *
 * Main landing page after admin login.
 * Shows:
 *   - System-wide stat cards (users by role, active sessions, pending disputes)
 *   - Recent user registrations table
 *   - Quick action links to all management sections
 *   - System health indicators (DB connection, session, settings)
 */

define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/UserModel.php';

Auth::startSession();
Auth::requireRole('admin');

$user = Auth::user();

// ── System stats ──────────────────────────────────────────────────────────────
$stats = UserModel::getAdminStats();

// ── System settings snapshot ──────────────────────────────────────────────────
$settings = [];
$settingKeys = [
    'school_name', 'academic_year', 'active_semester',
    'attendance_threshold', 'attendance_window',
    'dispute_window_hours', 'maintenance_mode',
];
foreach ($settingKeys as $key) {
    $row = DB::row(
        "SELECT setting_value FROM system_settings WHERE setting_key = ?",
        [$key]
    );
    $settings[$key] = $row['setting_value'] ?? null;
}

// ── Recent audit activity (last 8 actions) ────────────────────────────────────
$recentAudit = DB::rows(
    "SELECT al.action, al.target_type, al.target_id,
            al.ip_address, al.created_at,
            u.full_name AS actor_name, u.role AS actor_role
     FROM audit_logs al
     LEFT JOIN users u ON u.id = al.user_id
     ORDER BY al.created_at DESC
     LIMIT 8"
);

// ── At-risk students count (school-wide) ──────────────────────────────────────
$threshold    = (int)($settings['attendance_threshold'] ?? ATTENDANCE_ALERT_THRESHOLD);
$academicYear = $settings['academic_year'] ?? ACADEMIC_YEAR;
$semester     = (int)($settings['active_semester'] ?? ACTIVE_SEMESTER);

$atRiskCount = (int)(DB::row(
    "SELECT COUNT(DISTINCT student_id) AS cnt
     FROM vw_attendance_summary
     WHERE attendance_percent < ?
       AND academic_year = ?
       AND semester = ?
       AND total_sessions > 0",
    [$threshold, $academicYear, $semester]
)['cnt'] ?? 0);

$csrfToken = Auth::csrfToken();
$pageTitle = 'Admin Dashboard';
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
      <span class="topbar-title">Admin Dashboard</span>
      <div class="topbar-actions">
        <?php if ($settings['maintenance_mode'] == '1'): ?>
          <span class="badge badge-danger">
            🔧 Maintenance Mode ON
          </span>
        <?php endif; ?>
        <span class="text-sm text-muted hidden-mobile">
          <?= htmlspecialchars($settings['school_name'] ?? APP_NAME) ?>
        </span>
        <a href="<?= BASE_URL ?>/api/auth/logout.php"
           class="btn btn-ghost btn-sm" data-logout>
          Sign out
        </a>
      </div>
    </header>

    <div class="page-content">

      <!-- Welcome strip -->
      <div class="admin-welcome animate-fade-in">
        <div>
          <h1 class="welcome-name">
            System Overview
          </h1>
          <p class="text-muted text-sm">
            <?= htmlspecialchars($settings['school_name'] ?? '') ?>
            &nbsp;·&nbsp;
            <?= htmlspecialchars($academicYear) ?>
            &nbsp;·&nbsp;
            Semester <?= $semester ?>
          </p>
        </div>
        <a href="<?= BASE_URL ?>/public/admin/users.php"
           class="btn btn-primary">
          + Add User
        </a>
      </div>

      <!-- ── Stat cards ──────────────────────────────────────────────────── -->
      <div class="grid-stats animate-fade-in" style="margin-bottom:var(--space-8)">

        <div class="stat-card" style="animation-delay:0.05s">
          <div class="stat-icon"
               style="background:#EEF0FB;color:#534AB7">
            👨‍🏫
          </div>
          <div class="stat-body">
            <div class="stat-value"><?= $stats['lecturer'] ?></div>
            <div class="stat-label">Lecturers</div>
            <a href="<?= BASE_URL ?>/public/admin/users.php?role=lecturer"
               class="text-xs text-accent" style="margin-top:4px;display:block">
              Manage →
            </a>
          </div>
        </div>

        <div class="stat-card" style="animation-delay:0.1s">
          <div class="stat-icon"
               style="background:var(--color-accent-light);color:var(--color-accent)">
            🎓
          </div>
          <div class="stat-body">
            <div class="stat-value"><?= $stats['student'] ?></div>
            <div class="stat-label">Students</div>
            <a href="<?= BASE_URL ?>/public/admin/users.php?role=student"
               class="text-xs text-accent" style="margin-top:4px;display:block">
              Manage →
            </a>
          </div>
        </div>

        <div class="stat-card" style="animation-delay:0.15s">
          <div class="stat-icon"
               style="background:var(--color-amber-light);color:var(--color-amber)">
            👨‍👩‍👧
          </div>
          <div class="stat-body">
            <div class="stat-value"><?= $stats['parent'] ?></div>
            <div class="stat-label">Parents</div>
            <a href="<?= BASE_URL ?>/public/admin/users.php?role=parent"
               class="text-xs text-accent" style="margin-top:4px;display:block">
              Manage →
            </a>
          </div>
        </div>

        <div class="stat-card" style="animation-delay:0.2s">
          <div class="stat-icon"
               style="background:var(--color-success-light);color:var(--color-success)">
            ▶️
          </div>
          <div class="stat-body">
            <div class="stat-value"><?= $stats['active_sessions'] ?></div>
            <div class="stat-label">Live Sessions</div>
            <?php if ($stats['active_sessions'] > 0): ?>
              <div class="text-xs" style="color:var(--color-success);margin-top:4px">
                ● Active right now
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="stat-card" style="animation-delay:0.25s">
          <div class="stat-icon"
               style="background:<?= $stats['pending_disputes'] > 0 ? 'var(--color-error-light)' : 'var(--color-bg-inset)' ?>;
                      color:<?= $stats['pending_disputes'] > 0 ? 'var(--color-error)' : 'var(--color-text-muted)' ?>">
            ⚠️
          </div>
          <div class="stat-body">
            <div class="stat-value"><?= $stats['pending_disputes'] ?></div>
            <div class="stat-label">Pending Disputes</div>
          </div>
        </div>

        <div class="stat-card" style="animation-delay:0.3s">
          <div class="stat-icon"
               style="background:<?= $atRiskCount > 0 ? 'var(--color-error-light)' : 'var(--color-bg-inset)' ?>;
                      color:<?= $atRiskCount > 0 ? 'var(--color-error)' : 'var(--color-text-muted)' ?>">
            📉
          </div>
          <div class="stat-body">
            <div class="stat-value"><?= $atRiskCount ?></div>
            <div class="stat-label">At-Risk Students</div>
            <div class="text-xs text-muted" style="margin-top:2px">
              Below <?= $threshold ?>% attendance
            </div>
          </div>
        </div>

      </div>

      <div class="grid grid-2" style="gap:var(--space-6)">

        <!-- ── Quick actions ────────────────────────────────────────────── -->
        <div class="card animate-fade-in" style="animation-delay:0.35s">
          <div class="card-header">
            <div class="card-title">Quick Actions</div>
          </div>
          <div class="admin-quick-grid">

            <a href="<?= BASE_URL ?>/public/admin/users.php"
               class="admin-quick-card">
              <span class="admin-quick-icon">👥</span>
              <span class="admin-quick-label">Manage Users</span>
            </a>

            <a href="<?= BASE_URL ?>/public/admin/courses.php"
               class="admin-quick-card">
              <span class="admin-quick-icon">📚</span>
              <span class="admin-quick-label">Courses &amp; Units</span>
            </a>

            <a href="<?= BASE_URL ?>/public/admin/enrollments.php"
               class="admin-quick-card">
              <span class="admin-quick-icon">📋</span>
              <span class="admin-quick-label">Enrollments</span>
            </a>

            <a href="<?= BASE_URL ?>/public/admin/settings.php"
               class="admin-quick-card">
              <span class="admin-quick-icon">⚙️</span>
              <span class="admin-quick-label">System Settings</span>
            </a>

            <a href="<?= BASE_URL ?>/public/admin/reports.php"
               class="admin-quick-card">
              <span class="admin-quick-icon">🖨️</span>
              <span class="admin-quick-label">Reports</span>
            </a>

            <a href="<?= BASE_URL ?>/public/admin/audit.php"
               class="admin-quick-card">
              <span class="admin-quick-icon">🔍</span>
              <span class="admin-quick-label">Audit Logs</span>
            </a>

          </div>
        </div>

        <!-- ── System settings snapshot ────────────────────────────────── -->
        <div class="card animate-fade-in" style="animation-delay:0.4s">
          <div class="card-header">
            <div class="card-title">System Settings</div>
            <a href="<?= BASE_URL ?>/public/admin/settings.php"
               class="btn btn-secondary btn-sm">Edit</a>
          </div>
          <div class="settings-snapshot">

            <?php
              $snapshotItems = [
                ['label' => 'School Name',         'key' => 'school_name'],
                ['label' => 'Academic Year',        'key' => 'academic_year'],
                ['label' => 'Active Semester',      'key' => 'active_semester'],
                ['label' => 'Attendance Threshold', 'key' => 'attendance_threshold', 'suffix' => '%'],
                ['label' => 'QR Window',            'key' => 'attendance_window',    'suffix' => ' min'],
                ['label' => 'Dispute Window',       'key' => 'dispute_window_hours', 'suffix' => ' hrs'],
              ];
              foreach ($snapshotItems as $item):
                $val = $settings[$item['key']] ?? '—';
                $suffix = $item['suffix'] ?? '';
            ?>
              <div class="snapshot-row">
                <span class="snapshot-label"><?= $item['label'] ?></span>
                <span class="snapshot-value">
                  <?= htmlspecialchars($val . $suffix) ?>
                </span>
              </div>
            <?php endforeach; ?>

            <div class="snapshot-row">
              <span class="snapshot-label">Maintenance Mode</span>
              <span class="snapshot-value">
                <?php if ($settings['maintenance_mode'] == '1'): ?>
                  <span class="badge badge-danger">ON</span>
                <?php else: ?>
                  <span class="badge badge-success">OFF</span>
                <?php endif; ?>
              </span>
            </div>

          </div>
        </div>

      </div>

      <!-- ── Recent users ─────────────────────────────────────────────────── -->
      <div class="card animate-fade-in"
           style="margin-top:var(--space-6);animation-delay:0.45s">
        <div class="card-header">
          <div>
            <div class="card-title">Recently Registered Users</div>
            <div class="card-subtitle">Last 5 accounts created</div>
          </div>
          <a href="<?= BASE_URL ?>/public/admin/users.php"
             class="btn btn-secondary btn-sm">View All</a>
        </div>

        <?php if (empty($stats['recent_users'])): ?>
          <div class="empty-state" style="padding:var(--space-8) 0">
            <span class="empty-icon">👥</span>
            <p class="empty-title">No users yet</p>
            <p class="empty-text">
              <a href="<?= BASE_URL ?>/public/admin/users.php">Add the first user</a>
              to get started.
            </p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Reg. Number</th>
                  <th>Role</th>
                  <th>Registered</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($stats['recent_users'] as $u): ?>
                  <tr>
                    <td>
                      <div style="display:flex;align-items:center;gap:var(--space-3)">
                        <div class="user-avatar-sm">
                          <?= strtoupper(substr($u['full_name'], 0, 1)) ?>
                        </div>
                        <span class="font-medium text-sm">
                          <?= htmlspecialchars($u['full_name']) ?>
                        </span>
                      </div>
                    </td>
                    <td class="font-mono text-xs">
                      <?= htmlspecialchars($u['reg_number']) ?>
                    </td>
                    <td>
                      <?php
                        $roleColors = [
                          'admin'    => 'badge-danger',
                          'lecturer' => 'badge-info',
                          'student'  => 'badge-success',
                          'parent'   => 'badge-warning',
                        ];
                      ?>
                      <span class="badge <?= $roleColors[$u['role']] ?? 'badge-neutral' ?>">
                        <?= ucfirst($u['role']) ?>
                      </span>
                    </td>
                    <td class="text-sm text-muted">
                      <?= date('d M Y', strtotime($u['created_at'])) ?>
                    </td>
                    <td>
                      <a href="<?= BASE_URL ?>/public/admin/users.php?edit=<?= $u['id'] ?? '' ?>"
                         class="btn btn-ghost btn-sm">
                        Edit
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <!-- ── Recent audit log ─────────────────────────────────────────────── -->
      <div class="card animate-fade-in"
           style="margin-top:var(--space-6);animation-delay:0.5s">
        <div class="card-header">
          <div>
            <div class="card-title">Recent Activity</div>
            <div class="card-subtitle">Last 8 system actions</div>
          </div>
          <a href="<?= BASE_URL ?>/public/admin/audit.php"
             class="btn btn-secondary btn-sm">Full Log</a>
        </div>

        <?php if (empty($recentAudit)): ?>
          <div class="empty-state" style="padding:var(--space-8) 0">
            <span class="empty-icon">🔍</span>
            <p class="empty-text">No activity recorded yet.</p>
          </div>
        <?php else: ?>
          <div class="audit-list">
            <?php foreach ($recentAudit as $entry):
              $actionIcons = [
                'user_login'            => '🔑',
                'user_logout'           => '🚪',
                'user_created'          => '👤',
                'user_activated'        => '✅',
                'user_deactivated'      => '🚫',
                'session_created'       => '▶️',
                'session_closed'        => '⏹',
                'attendance_scanned'    => '📱',
                'mark_saved'            => '📝',
                'marks_bulk_uploaded'   => '📂',
                'assessment_published'  => '📣',
                'assessment_unpublished'=> '🔒',
                'password_reset'        => '🔐',
              ];
              $icon = $actionIcons[$entry['action']] ?? '📋';
              $actionLabel = ucwords(str_replace('_', ' ', $entry['action']));
            ?>
              <div class="audit-row">
                <span class="audit-icon"><?= $icon ?></span>
                <div class="audit-info">
                  <div class="audit-action text-sm font-medium">
                    <?= htmlspecialchars($actionLabel) ?>
                  </div>
                  <div class="text-xs text-muted">
                    <?= htmlspecialchars($entry['actor_name'] ?? 'System') ?>
                    <?php if ($entry['actor_role']): ?>
                      <span class="badge badge-neutral"
                            style="font-size:10px;padding:1px 5px">
                        <?= $entry['actor_role'] ?>
                      </span>
                    <?php endif; ?>
                    &nbsp;·&nbsp;
                    <?= htmlspecialchars($entry['ip_address'] ?? '') ?>
                  </div>
                </div>
                <div class="audit-time text-xs text-muted font-mono">
                  <?= date('d M, H:i', strtotime($entry['created_at'])) ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
</body>
</html>