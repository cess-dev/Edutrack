<?php
/**
 * EduTrack — Admin Login Page
 *
 * Extra security compared to other portals:
 *   - Admin accounts show a clear "Administrator access" warning
 *   - No registration link or password reset self-service
 *   - Brute-force lockout applies (shared with other portals via Auth)
 */

define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

Auth::startSession();
Auth::redirectIfLoggedIn();

$error     = '';
$regNumber = '';

// ── No-JS fallback ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $regNumber = trim($_POST['reg_number'] ?? '');
    $password  = $_POST['password'] ?? '';

    if (empty($regNumber) || empty($password)) {
        $error = 'Please enter your credentials.';
    } else {
        if (Auth::attempt($regNumber, $password)) {
            // Extra check: admin portal only for admin role
            if (Auth::role() !== 'admin') {
                Auth::logout();
                $error = 'This portal is for administrators only.';
            } else {
                header('Location: ' . BASE_URL . '/public/admin/dashboard.php');
                exit;
            }
        } else {
            $error = 'Invalid credentials.';
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
  <title>Admin Login — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/base.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/login.css">
</head>
<body class="login-body">

<div class="login-layout">

  <!-- Brand panel -->
  <div class="login-brand-panel login-brand-admin">
    <div class="brand-content">
      <div class="brand-logo">⚙️</div>
      <h1 class="brand-title"><?= htmlspecialchars(APP_NAME) ?></h1>
      <p class="brand-subtitle">Administration Panel</p>
      <div class="brand-divider"></div>
      <p class="brand-school"><?= htmlspecialchars($schoolName) ?></p>
      <div class="brand-features">
        <div class="brand-feature">
          <span class="feature-icon">👥</span>
          <span>Manage all user accounts</span>
        </div>
        <div class="brand-feature">
          <span class="feature-icon">📚</span>
          <span>Configure courses and units</span>
        </div>
        <div class="brand-feature">
          <span class="feature-icon">⚙️</span>
          <span>System settings and audit logs</span>
        </div>
      </div>
    </div>
    <div class="brand-footer"><?= htmlspecialchars(APP_NAME) ?> · <?= date('Y') ?></div>
  </div>

  <!-- Form panel -->
  <div class="login-form-panel">
    <div class="login-form-inner">

      <div class="portal-badge portal-badge-admin">
        <span>⚙️</span>
        <span>Administrator Access</span>
      </div>

      <h2 class="login-heading">Admin Sign In</h2>
      <p class="login-subheading text-muted">
        Restricted to system administrators only.
      </p>

      <!-- Security notice -->
      <div class="alert alert-warning" style="margin-bottom:var(--space-5)">
        <span class="alert-icon">🔒</span>
        <div class="text-sm">
          This panel provides full system access. All actions are
          logged in the audit trail. Ensure you sign out when done.
        </div>
      </div>

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

      <div data-error-container class="alert alert-error"
           style="margin-bottom:var(--space-5)"></div>

      <form id="login-form" method="POST" novalidate>
        <div class="form-group">
          <label class="form-label" for="reg_number">
            Admin Username <span class="required">*</span>
          </label>
          <input type="text"
                 id="reg_number"
                 name="reg_number"
                 class="form-control"
                 value="<?= htmlspecialchars($regNumber) ?>"
                 placeholder="e.g. ADMIN001"
                 autocomplete="username"
                 autocapitalize="characters"
                 required
                 autofocus>
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
            <button type="button"
                    class="password-toggle"
                    onclick="togglePassword()"
                    aria-label="Toggle password">
              <span id="eye-icon">👁</span>
            </button>
          </div>
        </div>

        <div id="attempts-warning" class="text-xs text-warning"
             style="display:none;margin-bottom:var(--space-3)"></div>

        <button type="submit"
                id="login-btn"
                class="btn btn-navy btn-full btn-lg"
                style="margin-top:var(--space-2)">
          Sign In to Admin Panel
        </button>
      </form>

      <!-- Other portals -->
      <div class="portal-switcher">
        <p class="text-sm text-muted">Not an admin?</p>
        <div class="portal-links">
          <a href="<?= BASE_URL ?>/public/lecturer/login.php"
             class="portal-link">👨‍🏫 Lecturer</a>
          <a href="<?= BASE_URL ?>/public/student/login.php"
             class="portal-link">🎓 Student</a>
          <a href="<?= BASE_URL ?>/public/parent/login.php"
             class="portal-link">👨‍👩‍👧 Parent</a>
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
  errorContainer.textContent = ''; errorContainer.hidden = true;
  attemptsWarn.style.display = 'none';

  const regNumber = document.getElementById('reg_number').value.trim();
  const password  = document.getElementById('password').value;
  const btn       = document.getElementById('login-btn');

  if (!regNumber || !password) {
    errorContainer.textContent = 'Please enter your credentials.';
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
        // Strict role check — admin portal only
        if (data.user.role !== 'admin') {
          errorContainer.textContent =
            'This portal is for administrators only. ' +
            `Your role is "${data.user.role}". ` +
            'Please use the correct portal link below.';
          errorContainer.hidden = false;
          // Log the wrong-portal user out immediately
          await fetch(`${BASE_URL}/api/auth/logout.php`);
          return;
        }

        btn.innerHTML        = '✓ Access granted — redirecting...';
        btn.style.background = 'var(--color-success)';
        setTimeout(() => { window.location.href = data.redirect; }, 600);
      }

    } catch (err) {
      const body = err.body || {};

      if (err.status === 429) {
        errorContainer.textContent = err.message;
        errorContainer.hidden      = false;
        btn.disabled = true;
        return;
      }

      if (body.attempts_remaining !== undefined) {
        attemptsWarn.textContent =
          `${body.attempts_remaining} attempt(s) remaining before lockout.`;
        attemptsWarn.style.display = 'block';
      }

      errorContainer.textContent = err.message || 'Login failed.';
      errorContainer.hidden = false;

      document.getElementById('login-form').classList.add('shake');
      setTimeout(() => {
        document.getElementById('login-form').classList.remove('shake');
      }, 500);
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