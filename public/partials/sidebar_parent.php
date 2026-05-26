<?php
/**
 * EduTrack — Parent Sidebar Partial
 *
 * Included by every parent portal page.
 * Reads $user and $children from the calling page's scope.
 */

$currentPage = basename($_SERVER['SCRIPT_FILENAME']);

function parentNavActive(string $file, string $current): string {
    return $current === $file ? 'active' : '';
}

// Use $children if already loaded; otherwise fetch
if (!isset($children)) {
    $children = UserModel::getLinkedStudents($user['id'] ?? 0);
}

// First child for default links
$firstChildId = !empty($children) ? $children[0]['id'] : '';
?>

<aside class="sidebar">
  <div class="sidebar-brand">
    <div class="brand-icon">👨‍👩‍👧</div>
    <div>
      <div class="brand-name"><?= htmlspecialchars(APP_NAME) ?></div>
      <div class="brand-role">Parent Portal</div>
    </div>
    <button type="button" class="sidebar-close-btn" aria-label="Close navigation">✕</button>
  </div>

  <?php if (!empty($_SESSION['must_change_password'])): ?>
  <div style="background:var(--color-warning-subtle,#FFF9E6);
              border-left:3px solid var(--color-warning,#D97706);
              padding:var(--space-3) var(--space-4);font-size:var(--text-xs)">
    <strong style="color:var(--color-warning,#D97706)">🔐 Temporary password</strong><br>
    <span>Please <a href="<?= BASE_URL ?>/parent/profile"
                   style="color:var(--color-accent);text-decoration:underline">
      change your password</a> before continuing.</span>
  </div>
  <?php endif; ?>

  <nav class="nav" aria-label="Parent navigation">

    <div class="nav-section-label">Overview</div>

    <a href="<?= BASE_URL ?>/parent/dashboard"
       class="nav-item <?= parentNavActive('dashboard.php', $currentPage) ?>">
      <span class="nav-icon">🏠</span>
      <span>Dashboard</span>
    </a>

    <?php if (!empty($children)): ?>
      <div class="nav-section-label">
        My <?= count($children) > 1 ? 'Children' : 'Child' ?>
      </div>

      <?php foreach ($children as $child): ?>
        <a href="<?= BASE_URL ?>/parent/attendance?student_id=<?= $child['id'] ?>"
           class="nav-item <?= (parentNavActive('attendance.php', $currentPage) && ($_GET['student_id'] ?? '') == $child['id']) ? 'active' : '' ?>">
          <span class="nav-icon">
            <span style="width:20px;height:20px;background:var(--color-accent);
                         border-radius:50%;display:grid;place-items:center;
                         font-size:10px;font-weight:700;color:white">
              <?= strtoupper(substr($child['full_name'], 0, 1)) ?>
            </span>
          </span>
          <span><?= htmlspecialchars(explode(' ', $child['full_name'])[0]) ?></span>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>

    <div class="nav-section-label">Attendance</div>

    <a href="<?= BASE_URL ?>/parent/attendance<?= $firstChildId ? "?student_id={$firstChildId}" : '' ?>"
       class="nav-item <?= parentNavActive('attendance.php', $currentPage) ?>">
      <span class="nav-icon">📋</span>
      <span>Attendance</span>
    </a>

    <div class="nav-section-label">Academics</div>

    <a href="<?= BASE_URL ?>/parent/marks<?= $firstChildId ? "?student_id={$firstChildId}" : '' ?>"
       class="nav-item <?= parentNavActive('marks.php', $currentPage) ?>">
      <span class="nav-icon">📝</span>
      <span>Marks</span>
    </a>

    <a href="<?= BASE_URL ?>/parent/transcript<?= $firstChildId ? "?student_id={$firstChildId}" : '' ?>"
       class="nav-item <?= parentNavActive('transcript.php', $currentPage) ?>">
      <span class="nav-icon">🎓</span>
      <span>Transcript</span>
    </a>

    <a href="<?= BASE_URL ?>/parent/history<?= $firstChildId ? "?student_id={$firstChildId}" : '' ?>"
       class="nav-item <?= parentNavActive('history.php', $currentPage) ?>">
      <span class="nav-icon">📚</span>
      <span>Semester History</span>
    </a>

    <div class="nav-section-label">Account</div>

    <a href="<?= BASE_URL ?>/parent/profile"
       class="nav-item <?= parentNavActive('profile.php', $currentPage) ?>">
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
        <?= strtoupper(substr($user['full_name'] ?? 'P', 0, 1)) ?>
      </div>
      <div class="user-info">
        <div class="user-name"><?= htmlspecialchars($user['full_name'] ?? '') ?></div>
        <div class="user-role">Parent / Guardian</div>
      </div>
    </div>
  </div>
</aside>