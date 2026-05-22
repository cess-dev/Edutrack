<?php
/**
 * EduTrack — Student Sidebar Partial
 *
 * Included by every student portal page.
 * Reads $user from the calling page's scope.
 */

$currentPage = basename($_SERVER['SCRIPT_FILENAME']);

function studentNavActive(string $file, string $current): string {
    return $current === $file ? 'active' : '';
}

// Count pending disputes
$studentPendingDisputes = (int)(DB::row(
    "SELECT COUNT(*) AS cnt FROM disputes
     WHERE student_id = ? AND status = 'pending'",
    [$user['id'] ?? 0]
)['cnt'] ?? 0);
?>

<aside class="sidebar">
  <div class="sidebar-brand">
    <div class="brand-icon">🎓</div>
    <div>
      <div class="brand-name"><?= htmlspecialchars(APP_NAME) ?></div>
      <div class="brand-role">Student Portal</div>
    </div>
  </div>

  <nav class="nav" aria-label="Student navigation">

    <div class="nav-section-label">Overview</div>

    <a href="<?= BASE_URL ?>/student/dashboard"
       class="nav-item <?= studentNavActive('dashboard.php', $currentPage) ?>">
      <span class="nav-icon">🏠</span>
      <span>Dashboard</span>
    </a>

    <div class="nav-section-label">Attendance</div>

    <a href="<?= BASE_URL ?>/student/scan"
       class="nav-item <?= studentNavActive('scan.php', $currentPage) ?>">
      <span class="nav-icon">📷</span>
      <span>Scan QR Code</span>
    </a>

    <a href="<?= BASE_URL ?>/student/attendance"
       class="nav-item <?= studentNavActive('attendance.php', $currentPage) ?>">
      <span class="nav-icon">📋</span>
      <span>My Attendance</span>
    </a>

    <a href="<?= BASE_URL ?>/public/student/disputes.php"
       class="nav-item <?= studentNavActive('disputes.php', $currentPage) ?>">
      <span class="nav-icon">🔔</span>
      <span>Disputes</span>
      <?php if ($studentPendingDisputes > 0): ?>
        <span class="nav-badge"><?= $studentPendingDisputes ?></span>
      <?php endif; ?>
    </a>

    <div class="nav-section-label">Academics</div>

    <a href="<?= BASE_URL ?>/student/marks"
       class="nav-item <?= studentNavActive('marks.php', $currentPage) ?>">
      <span class="nav-icon">📝</span>
      <span>My Marks</span>
    </a>

    <a href="<?= BASE_URL ?>/student/transcript"
       class="nav-item <?= studentNavActive('transcript.php', $currentPage) ?>">
      <span class="nav-icon">🎓</span>
      <span>Transcript</span>
    </a>

    <div class="nav-section-label">Account</div>

    <a href="<?= BASE_URL ?>/student/profile"
       class="nav-item <?= studentNavActive('profile.php', $currentPage) ?>">
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
      <div class="avatar">
        <?= strtoupper(substr($user['full_name'] ?? 'S', 0, 1)) ?>
      </div>
      <div class="user-info">
        <div class="user-name"><?= htmlspecialchars($user['full_name'] ?? '') ?></div>
        <div class="user-role"><?= htmlspecialchars($user['reg_number'] ?? '') ?></div>
      </div>
    </div>
  </div>
</aside>