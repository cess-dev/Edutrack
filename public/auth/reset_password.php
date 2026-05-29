<?php
/**
 * EduTrack — Reset Password via Token Page
 *
 * Accessed via the link in the reset email:
 *   /auth/reset-password?token=<raw_token>&role=<role>
 *
 * If the token is invalid/expired the user sees an error with a link back to
 * the forgot-password form.  On success they are redirected to the login page.
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

Auth::startSession();

if (Auth::isLoggedIn()) {
    header('Location: ' . BASE_URL);
    exit;
}

$validRoles = ['student', 'lecturer', 'parent', 'admin'];
$role       = in_array($_GET['role'] ?? '', $validRoles, true) ? $_GET['role'] : 'student';
$rawToken   = trim($_GET['token'] ?? '');

$roleConfig = [
    'student'  => ['icon' => '🎓', 'label' => 'Student Portal',  'color' => 'portal-badge-student',  'login' => BASE_URL . '/student/login'],
    'lecturer' => ['icon' => '👨‍🏫', 'label' => 'Lecturer Portal', 'color' => 'portal-badge-lecturer', 'login' => BASE_URL . '/lecturer/login'],
    'parent'   => ['icon' => '👨‍👩‍👧', 'label' => 'Parent Portal',   'color' => 'portal-badge-parent',   'login' => BASE_URL . '/parent/login'],
    'admin'    => ['icon' => '⚙️',  'label' => 'Admin Panel',     'color' => 'portal-badge-admin',    'login' => BASE_URL . '/admin/login'],
];

$rc = $roleConfig[$role];

// Validate token up-front so we can show an error state immediately
$tokenValid   = false;
$tokenExpired = false;
$tokenUsed    = false;

if ($rawToken) {
    $tokenHash = hash('sha256', $rawToken);
    $tokenRow  = DB::row(
        "SELECT expires_at, used_at FROM password_reset_tokens WHERE token_hash = ? LIMIT 1",
        [$tokenHash]
    );

    if ($tokenRow) {
        if ($tokenRow['used_at'] !== null) {
            $tokenUsed = true;
        } elseif (strtotime($tokenRow['expires_at']) < time()) {
            $tokenExpired = true;
        } else {
            $tokenValid = true;
        }
    }
}

$forgotUrl = BASE_URL . '/auth/forgot-password?role=' . urlencode($role);

$schoolName = DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'school_name'"
)['setting_value'] ?? SCHOOL_NAME;
?>
<!DOCTYPE html>
<html lang="en" data-base-url="<?= htmlspecialchars(BASE_URL) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/base.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/login.css">
</head>
<body class="login-body">

<div class="login-layout">

  <div class="login-brand-panel login-brand-<?= $role ?>">
    <div class="brand-content">
      <div class="brand-logo"><?= $rc['icon'] ?></div>
      <h1 class="brand-title"><?= htmlspecialchars(APP_NAME) ?></h1>
      <p class="brand-subtitle">Reset Password</p>
      <div class="brand-divider"></div>
      <p class="brand-school"><?= htmlspecialchars($schoolName) ?></p>
    </div>
    <div class="brand-footer"><?= htmlspecialchars(APP_NAME) ?> · <?= date('Y') ?></div>
  </div>

  <div class="login-form-panel">
    <div class="login-form-inner">

      <div class="portal-badge <?= $rc['color'] ?>">
        <span><?= $rc['icon'] ?></span>
        <span><?= htmlspecialchars($rc['label']) ?></span>
      </div>

      <?php if (!$rawToken || (!$tokenValid && !$tokenExpired && !$tokenUsed)): ?>
        <!-- No token or unrecognised token -->
        <h2 class="login-heading">Invalid Reset Link</h2>
        <div class="alert alert-error" style="margin-bottom:var(--space-5)">
          <span class="alert-icon">❌</span>
          <span>This password reset link is invalid. It may have been copied incorrectly.</span>
        </div>
        <a href="<?= htmlspecialchars($forgotUrl) ?>"
           class="btn btn-primary btn-full">Request a New Link</a>

      <?php elseif ($tokenExpired): ?>
        <h2 class="login-heading">Link Expired</h2>
        <div class="alert alert-warning" style="margin-bottom:var(--space-5)">
          <span class="alert-icon">⏱</span>
          <span>This reset link has expired (links are valid for
            <?= defined('PASSWORD_RESET_TOKEN_HOURS') ? PASSWORD_RESET_TOKEN_HOURS : 24 ?> hours).
            Please request a new one.</span>
        </div>
        <a href="<?= htmlspecialchars($forgotUrl) ?>"
           class="btn btn-primary btn-full">Request a New Link</a>

      <?php elseif ($tokenUsed): ?>
        <h2 class="login-heading">Link Already Used</h2>
        <div class="alert alert-warning" style="margin-bottom:var(--space-5)">
          <span class="alert-icon">✓</span>
          <span>This reset link has already been used. If you need to change your
                password again, please request a new reset link.</span>
        </div>
        <a href="<?= htmlspecialchars($rc['login']) ?>"
           class="btn btn-primary btn-full">Sign In</a>

      <?php else: ?>
        <!-- Token valid — show the reset form -->
        <h2 class="login-heading">Choose a New Password</h2>
        <p class="login-subheading text-muted">
          Enter and confirm your new password below.
        </p>

        <div id="success-state" style="display:none">
          <div class="alert alert-success" style="margin-bottom:var(--space-5)">
            <span class="alert-icon">✓</span>
            <div><strong>Password changed!</strong><br>
                 You can now sign in with your new password.</div>
          </div>
          <a href="<?= htmlspecialchars($rc['login']) ?>"
             class="btn btn-primary btn-full">Sign In Now</a>
        </div>

        <div id="reset-form-area">
          <div data-error-container class="alert alert-error"
               style="margin-bottom:var(--space-5)"></div>

          <form id="reset-form" novalidate>
            <input type="hidden" id="reset-token"
                   value="<?= htmlspecialchars($rawToken) ?>">
            <input type="hidden" id="reset-role"
                   value="<?= htmlspecialchars($role) ?>">

            <div class="form-group">
              <label class="form-label" for="password">
                New Password <span class="required">*</span>
              </label>
              <div class="password-field">
                <input type="password" id="password" class="form-control"
                       placeholder="Min 8 chars, upper+lower+digit+symbol"
                       autocomplete="new-password" required autofocus>
                <button type="button" class="password-toggle"
                        onclick="togglePw('password','eye1')"
                        aria-label="Toggle password">
                  <span id="eye1">👁</span>
                </button>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label" for="password2">
                Confirm New Password <span class="required">*</span>
              </label>
              <div class="password-field">
                <input type="password" id="password2" class="form-control"
                       placeholder="Repeat your new password"
                       autocomplete="new-password" required>
                <button type="button" class="password-toggle"
                        onclick="togglePw('password2','eye2')"
                        aria-label="Toggle confirm password">
                  <span id="eye2">👁</span>
                </button>
              </div>
            </div>

            <button type="submit" id="submit-btn"
                    class="btn btn-primary btn-full btn-lg"
                    style="margin-top:var(--space-2)">
              Set New Password
            </button>
          </form>
        </div>

      <?php endif; ?>

      <div style="margin-top:var(--space-6);text-align:center">
        <a href="<?= htmlspecialchars($rc['login']) ?>"
           class="text-sm text-accent" style="text-decoration:none">
          ← Back to Sign In
        </a>
      </div>

    </div>
  </div>

</div>

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
<script>
const BASE_URL = <?= json_encode(BASE_URL) ?>;

<?php if ($tokenValid): ?>
document.getElementById('reset-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const errEl    = document.querySelector('[data-error-container]');
  const token    = document.getElementById('reset-token').value;
  const password = document.getElementById('password').value;
  const confirm  = document.getElementById('password2').value;
  const btn      = document.getElementById('submit-btn');

  errEl.textContent = ''; errEl.hidden = true;

  if (!password || !confirm) {
    errEl.textContent = 'Both password fields are required.';
    errEl.hidden = false; return;
  }
  if (password !== confirm) {
    errEl.textContent = 'Passwords do not match.';
    errEl.hidden = false; return;
  }

  await Api.withLoading(btn, async () => {
    try {
      const data = await Api.post(
        `${BASE_URL}/api/auth/reset_password.php`,
        { token, password, password2: confirm }
      );
      document.getElementById('reset-form-area').style.display = 'none';
      document.getElementById('success-state').style.display   = 'block';
    } catch (err) {
      errEl.textContent = err.message || 'Failed to reset password.';
      errEl.hidden = false;
    }
  });
});
<?php endif; ?>

function togglePw(fieldId, iconId) {
  const input = document.getElementById(fieldId);
  const icon  = document.getElementById(iconId);
  input.type  = input.type === 'password' ? 'text' : 'password';
  icon.textContent = input.type === 'password' ? '👁' : '🙈';
}
</script>

</body>
</html>
