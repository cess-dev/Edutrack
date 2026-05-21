<?php
/**
 * EduTrack — Parent Login Page
 */

define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

Auth::startSession();
Auth::redirectIfLoggedIn();

$error     = '';
$regNumber = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $regNumber = trim($_POST['reg_number'] ?? '');
    $password  = $_POST['password'] ?? '';

    if (empty($regNumber) || empty($password)) {
        $error = 'Please enter your account number and password.';
    } else {
        if (Auth::attempt($regNumber, $password)) {
            header('Location: ' . BASE_URL . '/public/parent/dashboard.php');
            exit;
        } else {
            $error = 'Invalid account number or password.';
        }
    }
}

$schoolName = DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'school_name'"
)['setting_value'] ?? SCHOOL_NAME;
?>
<!DOCTYPE html>
<html lang="en" data-base-url="<?= htmlspecialchars(BASE_URL) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Parent Login — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/base.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/login.css">
</head>
<body class="login-body">

<div class="login-layout">

  <div class="login-brand-panel login-brand-parent">
    <div class="brand-content">
      <div class="brand-logo">🎓</div>
      <h1 class="brand-title"><?= htmlspecialchars(APP_NAME) ?></h1>
      <p class="brand-subtitle">Student Monitoring System</p>
      <div class="brand-divider"></div>
      <p class="brand-school"><?= htmlspecialchars($schoolName) ?></p>
      <div class="brand-features">
        <div class="brand-feature">
          <span class="feature-icon">👁</span>
          <span>Monitor attendance in real time</span>
        </div>
        <div class="brand-feature">
          <span class="feature-icon">📊</span>
          <span>View academic performance</span>
        </div>
        <div class="brand-feature">
          <span class="feature-icon">🔔</span>
          <span>Receive attendance alerts</span>
        </div>
      </div>
    </div>
    <div class="brand-footer"><?= htmlspecialchars(APP_NAME) ?> · <?= date('Y') ?></div>
  </div>

  <div class="login-form-panel">
    <div class="login-form-inner">

      <div class="portal-badge portal-badge-parent">
        <span>👨‍👩‍👧</span>
        <span>Parent / Guardian Portal</span>
      </div>

      <h2 class="login-heading">Parent Sign In</h2>
      <p class="login-subheading text-muted">
        Sign in to monitor your child's attendance and academic progress.
      </p>

      <?php if ($error): ?>
        <div class="alert alert-error" style="margin-bottom:var(--space-5)">
          <span class="alert-icon">❌</span>
          <span><?= htmlspecialchars($error) ?></span>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['expired'])): ?>
        <div class="alert alert-warning" style="margin-bottom:var(--space-5)">
          <span class="alert-icon">⏱</span>
          <span>Your session expired. Please sign in again.</span>
        </div>
      <?php endif; ?>

      <div data-error-container class="alert alert-error" style="margin-bottom:var(--space-5)"></div>

      <form id="login-form" method="POST" novalidate>

        <div class="form-group">
          <label class="form-label" for="reg_number">
            Account Number <span class="required">*</span>
          </label>
          <input type="text"
                 id="reg_number"
                 name="reg_number"
                 class="form-control"
                 value="<?= htmlspecialchars($regNumber) ?>"
                 placeholder="e.g. PAR001"
                 autocomplete="username"
                 autocapitalize="characters"
                 required
                 autofocus>
          <div class="form-hint">
            Your account number was provided by the school administrator.
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="password">
            Password <span class="required">*</span>
          </label>
          <div class="password-field">
            <input type="password"
                   id="password"
                   name="password"
                   class="form-control"
                   placeholder="Enter your password"
                   autocomplete="current-password"
                   required>
            <button type="button" class="password-toggle"
                    onclick="togglePassword()" aria-label="Toggle password">
              <span id="eye-icon">👁</span>
            </button>
          </div>
          <div class="form-hint">
            Contact the school office if you have forgotten your password.
          </div>
        </div>

        <div id="attempts-warning" class="text-xs text-warning"
             style="display:none;margin-bottom:var(--space-3)"></div>

        <button type="submit" id="login-btn"
                class="btn btn-primary btn-full btn-lg"
                style="margin-top:var(--space-2)">
          Sign In
        </button>

      </form>

      <div class="portal-switcher">
        <p class="text-sm text-muted">Looking for another portal?</p>
        <div class="portal-links">
          <a href="<?= BASE_URL ?>/public/lecturer/login.php" class="portal-link">👨‍🏫 Lecturer</a>
          <a href="<?= BASE_URL ?>/public/student/login.php"  class="portal-link">🎓 Student</a>
          <a href="<?= BASE_URL ?>/public/admin/login.php"    class="portal-link">⚙️ Admin</a>
        </div>
      </div>

    </div>
  </div>

</div>

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
<script>
const BASE_URL = <?= json_encode(BASE_URL) ?>;

document.getElementById('login-form').addEventListener('submit', async (e) => {
  e.preventDefault();

  const errorContainer = document.querySelector('[data-error-container]');
  const attemptsWarn   = document.getElementById('attempts-warning');
  errorContainer.textContent = '';
  errorContainer.hidden = true;
  attemptsWarn.style.display = 'none';

  const regNumber = document.getElementById('reg_number').value.trim();
  const password  = document.getElementById('password').value;
  const btn       = document.getElementById('login-btn');

  if (!regNumber || !password) {
    errorContainer.textContent = 'Please fill in all fields.';
    errorContainer.hidden = false;
    return;
  }

  await Api.withLoading(btn, async () => {
    try {
      const data = await Api.post(`${BASE_URL}/api/auth/login.php`, {
        reg_number: regNumber,
        password:   password,
      });

      if (data.success) {
        if (data.user.role !== 'parent') {
          errorContainer.textContent =
            `This is the Parent Portal. Please use the ${data.user.role} portal link below.`;
          errorContainer.hidden = false;
          await fetch(`${BASE_URL}/api/auth/logout.php`);
          return;
        }
        btn.innerHTML = '✓ Redirecting...';
        btn.style.background = 'var(--color-success)';
        setTimeout(() => { window.location.href = data.redirect; }, 600);
      }

    } catch (err) {
      const body = err.body || {};
      if (body.attempts_remaining !== undefined) {
        attemptsWarn.textContent =
          `${body.attempts_remaining} attempt(s) remaining before lockout.`;
        attemptsWarn.style.display = 'block';
      }
      errorContainer.textContent = err.message || 'Login failed.';
      errorContainer.hidden = false;

      const form = document.getElementById('login-form');
      form.classList.add('shake');
      setTimeout(() => form.classList.remove('shake'), 500);
    }
  });
});

function togglePassword() {
  const input = document.getElementById('password');
  const icon  = document.getElementById('eye-icon');
  input.type  = input.type === 'password' ? 'text' : 'password';
  icon.textContent = input.type === 'password' ? '👁' : '🙈';
}
</script>

</body>
</html>