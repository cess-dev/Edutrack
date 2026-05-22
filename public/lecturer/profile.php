<?php
/**
 * EduTrack — Lecturer Profile Page
 * Allows the lecturer to update contact details and change their password.
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/UserModel.php';

Auth::startSession();
Auth::requireRole('lecturer');

$user    = Auth::user();
$profile = UserModel::findById($user['id']);

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
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/lecturer.css">
</head>
<body>
<div class="layout">
  <?php include __DIR__ . '/../partials/sidebar_lecturer.php'; ?>
  <div class="main">
    <header class="topbar">
      <span class="topbar-title">My Profile</span>
    </header>
    <div class="page-content" style="max-width:640px">

      <?php include __DIR__ . '/../partials/profile_form.php'; ?>

    </div>
  </div>
</div>
<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
<?php include __DIR__ . '/../partials/profile_scripts.php'; ?>
</body>
</html>