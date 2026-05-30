<?php
/**
 * EduTrack — Admin Sidebar Partial
 * Included by every admin portal page.
 */

$requestPath = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$requestPath = preg_replace('#^edutrack/?#', '', $requestPath);
$currentPage = basename($requestPath) ?: basename($_SERVER['SCRIPT_FILENAME']);

function adminNavActive(string $route, string $current): string {
    return $current === $route ? 'active' : '';
}

$pendingDisputes = (int)(DB::row(
    "SELECT COUNT(*) AS cnt FROM disputes WHERE status = 'pending'"
)['cnt'] ?? 0);

$activeSessions = (int)(DB::row(
    "SELECT COUNT(*) AS cnt FROM attendance_sessions WHERE is_active = 1"
)['cnt'] ?? 0);

try {
    $pendingPasswordResets = (int)(DB::row(
        "SELECT COUNT(*) AS cnt FROM password_reset_requests WHERE status = 'pending'"
    )['cnt'] ?? 0);
} catch (PDOException $e) {
    $pendingPasswordResets = 0;
}

try {
    $activeOtpCount = (int)(DB::row(
        "SELECT COUNT(*) AS cnt FROM users
         WHERE login_otp IS NOT NULL AND login_otp_expires > NOW()"
    )['cnt'] ?? 0);
} catch (PDOException $e) {
    $activeOtpCount = 0;
}

$usersBadge = $pendingPasswordResets + $activeOtpCount;
?>
<aside class="sidebar">
  <div class="sidebar-brand">
    <div class="brand-icon">⚙️</div>
    <div>
      <div class="brand-name"><?= htmlspecialchars(APP_NAME) ?></div>
      <div class="brand-role">Admin Panel</div>
    </div>
    <button type="button" class="sidebar-close-btn" aria-label="Close navigation">✕</button>
  </div>

  <?php if (!empty($_SESSION['must_change_password'])): ?>
  <div style="background:var(--color-warning-subtle,#FFF9E6);
              border-left:3px solid var(--color-warning,#D97706);
              padding:var(--space-3) var(--space-4);font-size:var(--text-xs)">
    <strong style="color:var(--color-warning,#D97706)">🔐 Temporary password</strong><br>
    <span>Please <a href="<?= BASE_URL ?>/admin/profile"
                   style="color:var(--color-accent);text-decoration:underline">
      change your password</a> before continuing.</span>
  </div>
  <?php endif; ?>

  <nav class="nav" aria-label="Admin navigation">

    <div class="nav-section-label">Overview</div>

    <a href="<?= BASE_URL ?>/admin/dashboard"
       class="nav-item <?= adminNavActive('dashboard', $currentPage) ?>">
      <span class="nav-icon">🏠</span>
      <span>Dashboard</span>
    </a>

    <div class="nav-section-label">Users</div>

    <a href="<?= BASE_URL ?>/admin/users"
       class="nav-item <?= adminNavActive('users', $currentPage) ?>">
      <span class="nav-icon">👥</span>
      <span>All Users</span>
      <?php if ($usersBadge > 0): ?>
        <span class="nav-badge"><?= $usersBadge ?></span>
      <?php endif; ?>
    </a>

    <a href="<?= BASE_URL ?>/admin/enrollments"
       class="nav-item <?= adminNavActive('enrollments', $currentPage) ?>">
      <span class="nav-icon">📋</span>
      <span>Enrollments</span>
    </a>

    <div class="nav-section-label">Academic</div>

    <a href="<?= BASE_URL ?>/admin/courses"
       class="nav-item <?= adminNavActive('courses', $currentPage) ?>">
      <span class="nav-icon">📚</span>
      <span>Courses &amp; Units</span>
    </a>

    <div class="nav-section-label">Monitoring</div>

    <a href="<?= BASE_URL ?>/admin/attendance"
       class="nav-item <?= adminNavActive('attendance', $currentPage) ?>">
      <span class="nav-icon">📊</span>
      <span>Attendance Overview</span>
      <?php if ($activeSessions > 0): ?>
        <span class="nav-badge"><?= $activeSessions ?></span>
      <?php endif; ?>
    </a>

    <a href="<?= BASE_URL ?>/admin/disputes"
       class="nav-item <?= adminNavActive('disputes', $currentPage) ?>">
      <span class="nav-icon">⚠️</span>
      <span>Disputes</span>
      <?php if ($pendingDisputes > 0): ?>
        <span class="nav-badge"><?= $pendingDisputes ?></span>
      <?php endif; ?>
    </a>

    <div class="nav-section-label">System</div>

    <a href="<?= BASE_URL ?>/admin/settings"
       class="nav-item <?= adminNavActive('settings', $currentPage) ?>">
      <span class="nav-icon">⚙️</span>
      <span>Settings</span>
    </a>

    <a href="<?= BASE_URL ?>/admin/reports"
       class="nav-item <?= adminNavActive('reports', $currentPage) ?>">
      <span class="nav-icon">🖨️</span>
      <span>Reports</span>
    </a>

    <a href="<?= BASE_URL ?>/admin/audit"
       class="nav-item <?= adminNavActive('audit', $currentPage) ?>">
      <span class="nav-icon">🔍</span>
      <span>Audit Log</span>
    </a>

    <div class="nav-section-label">Account</div>

    <a href="<?= BASE_URL ?>/admin/profile"
       class="nav-item <?= adminNavActive('profile', $currentPage) ?>">
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