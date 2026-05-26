<?php
/**
 * EduTrack — Lecturer Login Page
 *
 * Handles both GET (render form) and POST (via JavaScript fetch).
 * The form submits via Ajax.post() to /api/auth/login.php,
 * which returns a redirect URL on success.
 *
 * On direct PHP POST (no-JS fallback) the form posts here and
 * this file proxies the credentials to the API manually.
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

Auth::startSession();

// Redirect already-authenticated lecturers straight to dashboard
Auth::redirectIfLoggedIn();

$error    = '';
$regNumber = '';

// ── No-JS fallback: handle direct form POST ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $regNumber = trim($_POST['reg_number'] ?? '');
    $password  = $_POST['password'] ?? '';

    if (empty($regNumber) || empty($password)) {
        $error = 'Please enter your staff number (or email) and password.';
    } else {
        $ok = Auth::attempt($regNumber, $password);
        if ($ok) {
            header('Location: ' . BASE_URL . '/lecturer/dashboard');
            exit;
        } else {
            $error = 'Invalid credentials. Check your staff number or email and try again.';
        }
    }
}

$pageTitle  = 'Lecturer Login';
$schoolName = DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'school_name'"
)['setting_value'] ?? SCHOOL_NAME;
?>
<!DOCTYPE html>
<html lang="en" data-base-url="<?= htmlspecialchars(BASE_URL) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/base.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/login.css">
</head>
<body class="login-body">

<div class="login-layout">

  <!-- ── Left panel — branding ────────────────────────────────────────── -->
  <div class="login-brand-panel">
    <div class="brand-content">
      <div class="brand-logo">🎓</div>
      <h1 class="brand-title"><?= htmlspecialchars(APP_NAME) ?></h1>
      <p class="brand-subtitle">Student Monitoring System</p>
      <div class="brand-divider"></div>
      <p class="brand-school"><?= htmlspecialchars($schoolName) ?></p>

      <div class="brand-features">
        <div class="brand-feature">
          <span class="feature-icon">📋</span>
          <span>QR attendance sessions</span>
        </div>
        <div class="brand-feature">
          <span class="feature-icon">📊</span>
          <span>Class analytics &amp; reports</span>
        </div>
        <div class="brand-feature">
          <span class="feature-icon">📝</span>
          <span>Grade management</span>
        </div>
      </div>
    </div>

    <div class="brand-footer">
      <?= htmlspecialchars(APP_NAME) ?> · <?= date('Y') ?>
    </div>
  </div>

  <!-- ── Right panel — login form ─────────────────────────────────────── -->
  <div class="login-form-panel">
    <div class="login-form-inner">

      <!-- Portal badge -->
      <div class="portal-badge portal-badge-lecturer">
        <span>👨‍🏫</span>
        <span>Lecturer Portal</span>
      </div>

      <h2 class="login-heading">Welcome back</h2>
      <p class="login-subheading text-muted">
        Sign in with your staff credentials to continue.
      </p>

      <!-- No-JS error message -->
      <?php if ($error): ?>
        <div class="alert alert-error" style="margin-bottom:var(--space-5)">
          <span class="alert-icon">❌</span>
          <span><?= htmlspecialchars($error) ?></span>
        </div>
      <?php endif; ?>

      <!-- Session expired notice -->
      <?php if (isset($_GET['expired'])): ?>
        <div class="alert alert-warning" style="margin-bottom:var(--space-5)">
          <span class="alert-icon">⏱</span>
          <span>Your session expired. Please sign in again.</span>
        </div>
      <?php endif; ?>

      <!-- AJAX error container -->
      <div data-error-container
           class="alert alert-error"
           style="margin-bottom:var(--space-5)">
      </div>

      <!-- Login form -->
      <form id="login-form" method="POST" novalidate>

        <div class="form-group">
          <label class="form-label" for="reg_number">
            Staff Number or Email
            <span class="required">*</span>
          </label>
          <input type="text"
                 id="reg_number"
                 name="reg_number"
                 class="form-control"
                 value="<?= htmlspecialchars($regNumber) ?>"
                 placeholder="e.g. LEC001 or email@school.local"
                 autocomplete="username"
                 autocapitalize="none"
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
                    aria-label="Toggle password visibility">
              <span id="eye-icon">👁</span>
            </button>
          </div>
        </div>

        <!-- Attempts remaining indicator (shown after first failure) -->
        <div id="attempts-warning" class="text-xs text-warning"
             style="display:none;margin-bottom:var(--space-3)">
        </div>

        <!-- Lockout countdown (shown during lockout) -->
        <div id="lockout-notice" class="alert alert-error"
             style="display:none;margin-bottom:var(--space-4)">
          <span class="alert-icon">🔒</span>
          <span id="lockout-message"></span>
        </div>

        <button type="submit"
                id="login-btn"
                class="btn btn-primary btn-full btn-lg"
                style="margin-top:var(--space-2)">
          Sign In
        </button>

      </form>

      <!-- Portal switcher -->
      <div class="portal-switcher">
        <p class="text-sm text-muted">Not a lecturer?</p>
        <div class="portal-links">
          <a href="<?= BASE_URL ?>/student/login"
             class="portal-link">
            🎓 Student
          </a>
          <a href="<?= BASE_URL ?>/parent/login"
             class="portal-link">
            👨‍👩‍👧 Parent
          </a>
          <a href="<?= BASE_URL ?>/admin/login"
             class="portal-link">
            ⚙️ Admin
          </a>
        </div>
      </div>

    </div><!-- /login-form-inner -->
  </div><!-- /login-form-panel -->

</div><!-- /login-layout -->

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
<script>
const BASE_URL = <?= json_encode(BASE_URL) ?>;

const form         = document.getElementById('login-form');
const loginBtn     = document.getElementById('login-btn');
const errorContainer = document.querySelector('[data-error-container]');
const attemptsWarn = document.getElementById('attempts-warning');
const lockoutDiv   = document.getElementById('lockout-notice');
const lockoutMsg   = document.getElementById('lockout-message');

// ── Form submit via fetch ─────────────────────────────────────────────────
form.addEventListener('submit', async (e) => {
  e.preventDefault();

  // Clear previous messages
  errorContainer.textContent = '';
  errorContainer.hidden      = true;
  attemptsWarn.style.display = 'none';
  lockoutDiv.style.display   = 'none';

  const regNumber = document.getElementById('reg_number').value.trim();
  const password  = document.getElementById('password').value;

  if (!regNumber || !password) {
    showError('Please enter your registration number and password.');
    return;
  }

  await Api.withLoading(loginBtn, async () => {
    try {
      const data = await Api.post(`${BASE_URL}/api/auth/login.php`, {
        reg_number: regNumber,
        password:   password,
      });

      if (data.success) {
        // Verify this login is for a lecturer account
        if (data.user.role !== 'lecturer' && data.user.role !== 'admin') {
          showError(
            `This is the Lecturer Portal. Your account role is "${data.user.role}". ` +
            `Please use the correct portal link below.`
          );
          // Log out immediately — wrong portal
          await fetch(`${BASE_URL}/api/auth/logout.php`);
          return;
        }

        loginBtn.innerHTML = '✓ Signed in! Redirecting...';
        loginBtn.style.background = 'var(--color-success)';

        setTimeout(() => {
          window.location.href = data.redirect;
        }, 600);
      }

    } catch (err) {
      const body = err.body || {};

      if (err.status === 429) {
        // Locked out
        lockoutMsg.textContent = err.message;
        lockoutDiv.style.display = 'flex';
        loginBtn.disabled = true;
        return;
      }

      if (body.attempts_remaining !== undefined) {
        attemptsWarn.textContent =
          `${body.attempts_remaining} attempt(s) remaining before your IP is locked out.`;
        attemptsWarn.style.display = 'block';
      }

      showError(err.message || 'Login failed. Please try again.');

      // Shake the form on failure
      form.classList.add('shake');
      setTimeout(() => form.classList.remove('shake'), 500);
    }
  });
});

function showError(msg) {
  errorContainer.textContent = msg;
  errorContainer.hidden      = false;
}

// ── Password visibility toggle ────────────────────────────────────────────
function togglePassword() {
  const input   = document.getElementById('password');
  const icon    = document.getElementById('eye-icon');
  const isHidden = input.type === 'password';

  input.type  = isHidden ? 'text' : 'password';
  icon.textContent = isHidden ? '🙈' : '👁';
}

// ── Enter key on reg_number field moves focus to password ─────────────────
document.getElementById('reg_number').addEventListener('keydown', (e) => {
  if (e.key === 'Enter') {
    e.preventDefault();
    document.getElementById('password').focus();
  }
});
</script>

</body>
</html>