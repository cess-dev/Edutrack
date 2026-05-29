<?php
/**
 * EduTrack — Forgot Password Page
 *
 * Shared across all portals.  The ?role= query parameter is used to
 * show the correct portal badge and back-link.
 *
 * After submission the page shows contextual guidance:
 *   - Email sent     → check inbox
 *   - Admin queue    → contact your administrator
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

Auth::startSession();

// If already logged in, bounce to dashboard
if (Auth::isLoggedIn()) {
    $role = Auth::role();
    $dests = [
        'admin'    => BASE_URL . '/admin/dashboard',
        'lecturer' => BASE_URL . '/lecturer/dashboard',
        'student'  => BASE_URL . '/student/dashboard',
        'parent'   => BASE_URL . '/parent/dashboard',
    ];
    header('Location: ' . ($dests[$role] ?? BASE_URL));
    exit;
}

$validRoles = ['student', 'lecturer', 'parent', 'admin'];
$role       = in_array($_GET['role'] ?? '', $validRoles, true) ? $_GET['role'] : 'student';

$roleConfig = [
    'student'  => ['icon' => '🎓', 'label' => 'Student Portal',  'color' => 'portal-badge-student',  'login' => BASE_URL . '/student/login'],
    'lecturer' => ['icon' => '👨‍🏫', 'label' => 'Lecturer Portal', 'color' => 'portal-badge-lecturer', 'login' => BASE_URL . '/lecturer/login'],
    'parent'   => ['icon' => '👨‍👩‍👧', 'label' => 'Parent Portal',   'color' => 'portal-badge-parent',   'login' => BASE_URL . '/parent/login'],
    'admin'    => ['icon' => '⚙️',  'label' => 'Admin Panel',     'color' => 'portal-badge-admin',    'login' => BASE_URL . '/admin/login'],
];

$rc = $roleConfig[$role];

$schoolName = DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'school_name'"
)['setting_value'] ?? SCHOOL_NAME;
?>
<!DOCTYPE html>
<html lang="en" data-base-url="<?= htmlspecialchars(BASE_URL) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forgot Password — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/base.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/login.css">
</head>
<body class="login-body">

<div class="login-layout">

  <div class="login-brand-panel login-brand-<?= $role ?>">
    <div class="brand-content">
      <div class="brand-logo"><?= $rc['icon'] ?></div>
      <h1 class="brand-title"><?= htmlspecialchars(APP_NAME) ?></h1>
      <p class="brand-subtitle">Password Reset</p>
      <div class="brand-divider"></div>
      <p class="brand-school"><?= htmlspecialchars($schoolName) ?></p>
      <div class="brand-features">
        <div class="brand-feature">
          <span class="feature-icon">🔐</span>
          <span>Secure password reset</span>
        </div>
        <div class="brand-feature">
          <span class="feature-icon">✉️</span>
          <span>Reset link sent to your email</span>
        </div>
        <div class="brand-feature">
          <span class="feature-icon">🛡️</span>
          <span>Admin-verified if needed</span>
        </div>
      </div>
    </div>
    <div class="brand-footer"><?= htmlspecialchars(APP_NAME) ?> · <?= date('Y') ?></div>
  </div>

  <div class="login-form-panel">
    <div class="login-form-inner">

      <div class="portal-badge <?= $rc['color'] ?>">
        <span><?= $rc['icon'] ?></span>
        <span><?= htmlspecialchars($rc['label']) ?></span>
      </div>

      <h2 class="login-heading">Forgot Your Password?</h2>
      <p class="login-subheading text-muted">
        Enter your registration number or email address and we'll send you reset instructions.
      </p>

      <!-- JS-managed states -->
      <div id="form-area">
        <div data-error-container class="alert alert-error"
             style="margin-bottom:var(--space-5)"></div>

        <form id="forgot-form" novalidate>
          <div class="form-group">
            <label class="form-label" for="identifier">
              Registration Number or Email <span class="required">*</span>
            </label>
            <input type="text"
                   id="identifier"
                   name="identifier"
                   class="form-control"
                   placeholder="e.g. STU2024001 or student@email.com"
                   autocomplete="username"
                   autocapitalize="none"
                   required
                   autofocus>
          </div>

          <button type="submit" id="submit-btn"
                  class="btn btn-primary btn-full btn-lg"
                  style="margin-top:var(--space-2)">
            Send Reset Instructions
          </button>
        </form>

        <div style="margin-top:var(--space-6);text-align:center">
          <a href="<?= htmlspecialchars($rc['login']) ?>"
             class="text-sm text-accent" style="text-decoration:none">
            ← Back to Sign In
          </a>
        </div>
      </div>

      <!-- Email success state (hidden initially) -->
      <div id="email-success" style="display:none">
        <div class="alert alert-success" style="margin-bottom:var(--space-5)">
          <span class="alert-icon">✉️</span>
          <div>
            <strong>Check your inbox!</strong><br>
            A password reset link has been sent to your email address.
            It expires in <?= defined('PASSWORD_RESET_TOKEN_HOURS') ? PASSWORD_RESET_TOKEN_HOURS : 24 ?> hours.
          </div>
        </div>
        <p class="text-sm text-muted" style="margin-bottom:var(--space-5)">
          Didn't receive it? Check your spam folder or contact your institution's
          administrator if the problem persists.
        </p>
        <a href="<?= htmlspecialchars($rc['login']) ?>"
           class="btn btn-secondary btn-full">
          ← Back to Sign In
        </a>
      </div>

      <!-- Admin queue state (hidden initially) -->
      <div id="admin-queue" style="display:none">
        <div class="alert alert-warning" style="margin-bottom:var(--space-5)">
          <span class="alert-icon">🛡️</span>
          <div id="admin-queue-msg"><strong>Administrator approval required.</strong></div>
        </div>
        <p class="text-sm text-muted" style="margin-bottom:var(--space-5)">
          Your reset request has been queued. Please contact your administrator
          or wait for them to process it. You will be notified once it's approved.
        </p>
        <a href="<?= htmlspecialchars($rc['login']) ?>"
           class="btn btn-secondary btn-full">
          ← Back to Sign In
        </a>
      </div>

    </div>
  </div>

</div>

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
<script>
const BASE_URL = <?= json_encode(BASE_URL) ?>;

document.getElementById('forgot-form').addEventListener('submit', async (e) => {
  e.preventDefault();

  const errEl      = document.querySelector('[data-error-container]');
  const identifier = document.getElementById('identifier').value.trim();
  const btn        = document.getElementById('submit-btn');

  errEl.textContent = ''; errEl.hidden = true;

  if (!identifier) {
    errEl.textContent = 'Please enter your registration number or email.';
    errEl.hidden = false;
    return;
  }

  await Api.withLoading(btn, async () => {
    try {
      const data = await Api.post(
        `${BASE_URL}/api/auth/forgot_password.php`,
        { identifier }
      );

      document.getElementById('form-area').style.display = 'none';

      if (data.method === 'admin_queue') {
        // User found but can't receive email — show admin queue state
        const msgEl = document.getElementById('admin-queue-msg');
        msgEl.innerHTML = '<strong>Administrator approval required.</strong><br>' +
          (data.message || 'Your request has been queued.');
        document.getElementById('admin-queue').style.display = 'block';
      } else {
        // method === 'email', OR user not found (generic — always show email-success
        // so we don't reveal whether the account exists)
        document.getElementById('email-success').style.display = 'block';
      }
    } catch (err) {
      errEl.textContent = err.message || 'Something went wrong. Please try again.';
      errEl.hidden = false;
    }
  });
});
</script>

</body>
</html>
