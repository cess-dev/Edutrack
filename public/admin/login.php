<?php
/**
 * EduTrack — Admin Login Page
 *
 * Extra security compared to other portals:
 *   - Admin accounts show a clear "Administrator access" warning
 *   - No registration link or password reset self-service
 *   - Brute-force lockout applies (shared with other portals via Auth)
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
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
                header('Location: ' . BASE_URL . '/admin/dashboard');
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

      <!-- Step 1: credentials -->
      <div id="step-credentials">
        <form id="login-form" method="POST" novalidate>
          <div class="form-group">
            <label class="form-label" for="reg_number">
              Admin Username or Email <span class="required">*</span>
            </label>
            <input type="text" id="reg_number" name="reg_number" class="form-control"
                   value="<?= htmlspecialchars($regNumber) ?>"
                   placeholder="e.g. ADMIN001 or admin@school.local"
                   autocomplete="username" autocapitalize="none" required autofocus>
          </div>
          <div class="form-group">
            <label class="form-label" for="password">Password <span class="required">*</span></label>
            <div class="password-field">
              <input type="password" id="password" name="password" class="form-control"
                     placeholder="Enter your password" autocomplete="current-password" required>
              <button type="button" class="password-toggle"
                      onclick="togglePassword()" aria-label="Toggle password">
                <span id="eye-icon">👁</span>
              </button>
            </div>
          </div>
          <div id="attempts-warning" class="text-xs text-warning"
               style="display:none;margin-bottom:var(--space-3)"></div>
          <button type="submit" id="login-btn"
                  class="btn btn-navy btn-full btn-lg"
                  style="margin-top:var(--space-2)">Sign In to Admin Panel</button>
          <div style="margin-top:var(--space-4);text-align:center">
            <a href="<?= BASE_URL ?>/auth/forgot-password?role=admin"
               class="text-sm text-muted" style="text-decoration:none">
              Forgot your password?
            </a>
          </div>
        </form>
      </div>

      <!-- Step 2: OTP -->
      <div id="step-otp" style="display:none">
        <div class="alert alert-info" style="margin-bottom:var(--space-5)">
          <span class="alert-icon">✉️</span>
          <span id="otp-hint-msg">A 6-digit code has been sent to your email.</span>
        </div>
        <div data-error-container="otp" class="alert alert-error"
             style="margin-bottom:var(--space-4)"></div>
        <form id="otp-form" novalidate>
          <div class="form-group">
            <label class="form-label" for="otp-input">
              Verification Code <span class="required">*</span>
            </label>
            <input type="text" id="otp-input" class="form-control"
                   placeholder="Enter 6-digit code" maxlength="6"
                   inputmode="numeric" autocomplete="one-time-code"
                   style="font-size:1.4rem;letter-spacing:6px;text-align:center">
          </div>
          <button type="submit" id="otp-btn"
                  class="btn btn-navy btn-full btn-lg"
                  style="margin-top:var(--space-2)">Verify Code</button>
        </form>
        <p class="text-xs text-muted" style="margin-top:var(--space-3);text-align:center">
          Didn't receive it? Check your <strong>spam/junk</strong> folder.
        </p>
        <div style="margin-top:var(--space-3);text-align:center">
          <a href="#" onclick="showCredentials()"
             class="text-sm text-muted" style="text-decoration:none">
            ← Back / Resend code
          </a>
        </div>
      </div>

      <!-- Other portals -->
      <div class="portal-switcher">
        <p class="text-sm text-muted">Not an admin?</p>
        <div class="portal-links">
          <a href="<?= BASE_URL ?>/lecturer/login"
             class="portal-link">👨‍🏫 Lecturer</a>
          <a href="<?= BASE_URL ?>/student/login"
             class="portal-link">🎓 Student</a>
          <a href="<?= BASE_URL ?>/parent/login"
             class="portal-link">👨‍👩‍👧 Parent</a>
        </div>
      </div>

    </div>
  </div>

</div>

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
<script>
const BASE_URL = <?= json_encode(BASE_URL) ?>;

const PORTAL_ROLE    = 'admin';
const errorContainer = document.querySelector('[data-error-container]');
const attemptsWarn   = document.getElementById('attempts-warning');

function showCredentials() {
  document.getElementById('step-otp').style.display         = 'none';
  document.getElementById('step-credentials').style.display = 'block';
  document.getElementById('otp-input').value = '';
  clearOtpErr();
}
function clearOtpErr() { const el = document.querySelector('[data-error-container="otp"]'); el.textContent = ''; el.hidden = true; }
function setOtpErr(msg) { const el = document.querySelector('[data-error-container="otp"]'); el.textContent = msg; el.hidden = false; }

document.getElementById('login-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  errorContainer.textContent = ''; errorContainer.hidden = true;
  attemptsWarn.style.display = 'none';

  const regNumber = document.getElementById('reg_number').value.trim();
  const password  = document.getElementById('password').value;
  const btn       = document.getElementById('login-btn');

  if (!regNumber || !password) { errorContainer.textContent = 'Please enter your credentials.'; errorContainer.hidden = false; return; }

  await Api.withLoading(btn, async () => {
    try {
      const data = await Api.post(`${BASE_URL}/api/auth/login.php`, { reg_number: regNumber, password });

      if (data.step === 'otp') {
        document.getElementById('otp-hint-msg').textContent = data.message;
        document.getElementById('step-credentials').style.display = 'none';
        document.getElementById('step-otp').style.display         = 'block';
        setTimeout(() => document.getElementById('otp-input').focus(), 100);
        return;
      }

      if (data.user.role !== PORTAL_ROLE) {
        errorContainer.textContent = `This portal is for administrators only. Your role is "${data.user.role}".`;
        errorContainer.hidden = false;
        await fetch(`${BASE_URL}/api/auth/logout.php`); return;
      }
      btn.innerHTML = '✓ Access granted — redirecting...'; btn.style.background = 'var(--color-success)';
      setTimeout(() => { window.location.href = data.redirect; }, 600);

    } catch (err) {
      const body = err.body || {};
      if (err.status === 429) { errorContainer.textContent = err.message; errorContainer.hidden = false; btn.disabled = true; return; }
      if (body.attempts_remaining !== undefined) { attemptsWarn.textContent = `${body.attempts_remaining} attempt(s) remaining.`; attemptsWarn.style.display = 'block'; }
      errorContainer.textContent = err.message || 'Login failed.'; errorContainer.hidden = false;
      document.getElementById('login-form').classList.add('shake');
      setTimeout(() => document.getElementById('login-form').classList.remove('shake'), 500);
    }
  });
});

document.getElementById('otp-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  clearOtpErr();
  const otp = document.getElementById('otp-input').value.trim();
  const btn = document.getElementById('otp-btn');
  if (!otp || otp.length !== 6) { setOtpErr('Please enter the 6-digit code.'); return; }

  await Api.withLoading(btn, async () => {
    try {
      const data = await Api.post(`${BASE_URL}/api/auth/verify_otp.php`, { otp });
      if (data.user.role !== PORTAL_ROLE) { setOtpErr(`This portal is for admins only. Use the correct portal.`); await fetch(`${BASE_URL}/api/auth/logout.php`); return; }
      btn.innerHTML = '✓ Access granted — redirecting...'; btn.style.background = 'var(--color-success)';
      setTimeout(() => { window.location.href = data.redirect; }, 600);
    } catch (err) {
      if (err.body?.expired) { showCredentials(); }
      setOtpErr(err.message || 'Verification failed.');
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