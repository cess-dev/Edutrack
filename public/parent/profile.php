<?php
defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/UserModel.php';

Auth::startSession();
Auth::requireRole('parent');

$user     = Auth::user();
$profile  = UserModel::findById($user['id']);
$children = UserModel::getLinkedStudents($user['id']);
$csrfToken = Auth::csrfToken();
$pageTitle = 'My Profile';
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
    <header class="topbar">
      <span class="topbar-title">My Profile</span>
    </header>
    <div class="page-content" style="max-width:640px">

      <?php include __DIR__ . '/../partials/profile_form.php'; ?>

      <!-- Linked children (read-only) -->
      <?php if (!empty($children)): ?>
        <div class="card animate-fade-in" style="margin-top:var(--space-5);animation-delay:0.15s">
          <div class="card-header">
            <div class="card-title">Linked Students</div>
            <div class="card-subtitle">
              Contact the administrator to add or remove links
            </div>
          </div>
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Reg. Number</th>
                  <th>Relationship</th>
                  <th>Linked</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($children as $c): ?>
                  <tr>
                    <td class="font-medium text-sm">
                      <?= htmlspecialchars($c['full_name']) ?>
                    </td>
                    <td class="font-mono text-xs">
                      <?= htmlspecialchars($c['reg_number']) ?>
                    </td>
                    <td class="text-sm text-muted">
                      <?= htmlspecialchars(ucfirst($c['relationship'])) ?>
                    </td>
                    <td class="text-xs text-muted">
                      <?= date('d M Y', strtotime($c['linked_at'])) ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>

    </div>
  </div>
</div>
<nav class="mobile-nav">
  <a href="<?= BASE_URL ?>/parent/dashboard" class="mobile-nav-item">
    <span class="nav-icon">🏠</span><span>Home</span>
  </a>
  <a href="<?= BASE_URL ?>/parent/attendance" class="mobile-nav-item">
    <span class="nav-icon">📋</span><span>Attendance</span>
  </a>
  <a href="<?= BASE_URL ?>/parent/marks" class="mobile-nav-item">
    <span class="nav-icon">📝</span><span>Marks</span>
  </a>
  <a href="<?= BASE_URL ?>/parent/profile" class="mobile-nav-item active">
    <span class="nav-icon">👤</span><span>Profile</span>
  </a>
</nav>
<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
<?php include __DIR__ . '/../partials/profile_scripts.php'; ?>
</body>
</html>