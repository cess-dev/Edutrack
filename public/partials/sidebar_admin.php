<?php
/**
 * EduTrack — Admin Sidebar Partial
 * Included by every admin portal page.
 */

$currentPage = basename($_SERVER['SCRIPT_FILENAME']);

function adminNavActive(string $file, string $current): string {
    return $current === $file ? 'active' : '';
}

$pendingDisputes = (int)(DB::row(
    "SELECT COUNT(*) AS cnt FROM disputes WHERE status = 'pending'"
)['cnt'] ?? 0);

$activeSessions = (int)(DB::row(
    "SELECT COUNT(*) AS cnt FROM attendance_sessions WHERE is_active = 1"
)['cnt'] ?? 0);
?>
<aside class="sidebar">
  <div class="sidebar-brand">
    <div class="brand-icon">⚙️</div>
    <div>
      <div class="brand-name"><?= htmlspecialchars(APP_NAME) ?></div>
      <div class="brand-role">Admin Panel</div>
    </div>
  </div>

  <nav class="nav" aria-label="Admin navigation">

    <div class="nav-section-label">Overview</div>

    <a href="<?= BASE_URL ?>/admin/dashboard"
       class="nav-item <?= adminNavActive('dashboard.php', $currentPage) ?>">
      <span class="nav-icon">🏠</span>
      <span>Dashboard</span>
    </a>

    <div class="nav-section-label">Users</div>

    <a href="<?= BASE_URL ?>/admin/users"
       class="nav-item <?= adminNavActive('users.php', $currentPage) ?>">
      <span class="nav-icon">👥</span>
      <span>All Users</span>
    </a>

    <a href="<?= BASE_URL ?>/admin/enrollments"
       class="nav-item <?= adminNavActive('enrollments.php', $currentPage) ?>">
      <span class="nav-icon">📋</span>
      <span>Enrollments</span>
    </a>

    <div class="nav-section-label">Academic</div>

    <a href="<?= BASE_URL ?>/admin/courses"
       class="nav-item <?= adminNavActive('courses.php', $currentPage) ?>">
      <span class="nav-icon">📚</span>
      <span>Courses &amp; Units</span>
    </a>

    <div class="nav-section-label">Monitoring</div>

    <a href="<?= BASE_URL ?>/public/admin/attendance.php"
       class="nav-item <?= adminNavActive('attendance.php', $currentPage) ?>">
      <span class="nav-icon">📊</span>
      <span>Attendance Overview</span>
      <?php if ($activeSessions > 0): ?>
        <span class="nav-badge"><?= $activeSessions ?></span>
      <?php endif; ?>
    </a>

    <a href="<?= BASE_URL ?>/public/admin/disputes.php"
       class="nav-item <?= adminNavActive('disputes.php', $currentPage) ?>">
      <span class="nav-icon">⚠️</span>
      <span>Disputes</span>
      <?php if ($pendingDisputes > 0): ?>
        <span class="nav-badge"><?= $pendingDisputes ?></span>
      <?php endif; ?>
    </a>

    <div class="nav-section-label">System</div>

    <a href="<?= BASE_URL ?>/admin/settings"
       class="nav-item <?= adminNavActive('settings.php', $currentPage) ?>">
      <span class="nav-icon">⚙️</span>
      <span>Settings</span>
    </a>

    <a href="<?= BASE_URL ?>/admin/reports"
       class="nav-item <?= adminNavActive('reports.php', $currentPage) ?>">
      <span class="nav-icon">🖨️</span>
      <span>Reports</span>
    </a>

    <a href="<?= BASE_URL ?>/admin/audit"
       class="nav-item <?= adminNavActive('audit.php', $currentPage) ?>">
      <span class="nav-icon">🔍</span>
      <span>Audit Log</span>
    </a>

    <div class="nav-section-label">Account</div>

    <a href="<?= BASE_URL ?>/admin/profile"
       class="nav-item <?= adminNavActive('profile.php', $currentPage) ?>">
      <span class="nav-icon">👤</span>
      <span>My Profile</span>
    </a>

    <a href="<?= BASE_URL ?>/api/auth/logout"
       class="nav-item" data-logout>
      <span class="nav-icon">🚪</span>
      <span>Sign Out</span>
    </a>

  </nav>

  <div class="sidebar-footer">
    <div class="user-pill">
      <div class="avatar" style="background:var(--color-coral)">
        <?= strtoupper(substr($user['full_name'] ?? 'A', 0, 1)) ?>
      </div>
      <div class="user-info">
        <div class="user-name"><?= htmlspecialchars($user['full_name'] ?? '') ?></div>
        <div class="user-role">Administrator</div>
      </div>
    </div>
  </div>
</aside>