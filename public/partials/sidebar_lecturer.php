<?php
/**
 * EduTrack — Lecturer Sidebar Partial
 *
 * Included by every lecturer portal page.
 * Reads $user from the calling page's scope (set after Auth::user()).
 * Marks the active nav item by comparing the current script path.
 */

$currentPage = basename($_SERVER['SCRIPT_FILENAME']);
$currentDir  = basename(dirname($_SERVER['SCRIPT_FILENAME']));

function navActive(string $file, string $current): string {
    return $current === $file ? 'active' : '';
}

// Count pending disputes for the badge
$pendingDisputes = (int)(DB::row(
    "SELECT COUNT(*) AS cnt
     FROM disputes d
     JOIN attendance_sessions s ON s.id = d.session_id
     WHERE s.lecturer_id = ? AND d.status = 'pending'",
    [$user['id'] ?? 0]
)['cnt'] ?? 0);
?>

<aside class="sidebar">
  <!-- Brand -->
  <div class="sidebar-brand">
    <div class="brand-icon">🎓</div>
    <div>
      <div class="brand-name"><?= htmlspecialchars(APP_NAME) ?></div>
      <div class="brand-role">Lecturer Portal</div>
    </div>
  </div>

  <!-- Navigation -->
  <nav class="nav" aria-label="Lecturer navigation">

    <div class="nav-section-label">Overview</div>

    <a href="<?= BASE_URL ?>/public/lecturer/dashboard.php"
       class="nav-item <?= navActive('dashboard.php', $currentPage) ?>">
      <span class="nav-icon">🏠</span>
      <span>Dashboard</span>
    </a>

    <div class="nav-section-label">Attendance</div>

    <a href="<?= BASE_URL ?>/public/lecturer/session_start.php"
       class="nav-item <?= navActive('session_start.php', $currentPage) ?>">
      <span class="nav-icon">▶️</span>
      <span>Start Session</span>
    </a>

    <a href="<?= BASE_URL ?>/public/lecturer/sessions.php"
       class="nav-item <?= navActive('sessions.php', $currentPage) ?>">
      <span class="nav-icon">📋</span>
      <span>Session History</span>
    </a>

    <a href="<?= BASE_URL ?>/public/lecturer/disputes.php"
       class="nav-item <?= navActive('disputes.php', $currentPage) ?>">
      <span class="nav-icon">⚠️</span>
      <span>Disputes</span>
      <?php if ($pendingDisputes > 0): ?>
        <span class="nav-badge"><?= $pendingDisputes ?></span>
      <?php endif; ?>
    </a>

    <div class="nav-section-label">Marks</div>

    <a href="<?= BASE_URL ?>/public/lecturer/marks.php"
       class="nav-item <?= navActive('marks.php', $currentPage) ?>">
      <span class="nav-icon">📝</span>
      <span>Upload Marks</span>
    </a>

    <a href="<?= BASE_URL ?>/public/lecturer/marksheet.php"
       class="nav-item <?= navActive('marksheet.php', $currentPage) ?>">
      <span class="nav-icon">📊</span>
      <span>Mark Sheet</span>
    </a>

    <div class="nav-section-label">Reports</div>

    <a href="<?= BASE_URL ?>/public/lecturer/analytics.php"
       class="nav-item <?= navActive('analytics.php', $currentPage) ?>">
      <span class="nav-icon">📈</span>
      <span>Analytics</span>
    </a>


    <div class="nav-section-label">Account</div>

    <a href="<?= BASE_URL ?>/public/lecturer/profile.php"
       class="nav-item <?= navActive('profile.php', $currentPage) ?>">
      <span class="nav-icon">👤</span>
      <span>My Profile</span>
    </a>

    <a href="<?= BASE_URL ?>/api/auth/logout.php"
       class="nav-item"
       data-logout>
      <span class="nav-icon">🚪</span>
      <span>Sign Out</span>
    </a>

  </nav>

  <!-- User pill at bottom -->
  <div class="sidebar-footer">
    <div class="user-pill">
      <div class="avatar">
        <?= strtoupper(substr($user['full_name'] ?? 'L', 0, 1)) ?>
      </div>
      <div class="user-info">
        <div class="user-name"><?= htmlspecialchars($user['full_name'] ?? '') ?></div>
        <div class="user-role"><?= htmlspecialchars($user['reg_number'] ?? '') ?></div>
      </div>
    </div>
  </div>
</aside>