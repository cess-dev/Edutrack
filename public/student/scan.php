<?php
/**
 * EduTrack — Student QR Scan Page
 *
 * The page students open to scan the lecturer's QR code
 * and register attendance for a class session.
 *
 * Wires together:
 *   - HTML camera viewfinder structure (required by qr-scanner.js)
 *   - QRScanner.init() with the scan API endpoint and callbacks
 *   - Success / error result display
 *   - Manual fallback: student can type/paste the QR token if camera fails
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

Auth::startSession();
Auth::requireRole('student');

$user      = Auth::user();
$csrfToken = Auth::csrfToken();
$pageTitle = 'Scan Attendance QR';

// Check if student has any active enrollments
$enrolledUnits = DB::rows(
    "SELECT u.id, u.code, u.name
     FROM enrollments e
     JOIN units u ON u.id = e.unit_id
     WHERE e.student_id   = ?
       AND e.academic_year = (SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year')
       AND e.semester      = (SELECT setting_value FROM system_settings WHERE setting_key = 'active_semester')
     ORDER BY u.code ASC",
    [$user['id']]
);
?>
<!DOCTYPE html>
<html lang="en" data-base-url="<?= htmlspecialchars(BASE_URL) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
  <title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/base.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/student.css">
  <!-- jsQR — QR decoding from camera frames -->
  <script src="<?= BASE_URL ?>/public/assets/vendor/jsQR.min.js"></script>
</head>
<body>

<div class="layout">

  <?php include __DIR__ . '/../partials/sidebar_student.php'; ?>

  <div class="main">

    <!-- Topbar -->
    <header class="topbar">
      <a href="<?= BASE_URL ?>/student/dashboard"
         class="btn btn-ghost btn-sm" style="margin-right:var(--space-2)">
        ← Back
      </a>
      <span class="topbar-title">Scan Attendance QR</span>
    </header>

    <div class="page-content">

      <?php if (empty($enrolledUnits)): ?>
        <!-- Not enrolled — show message instead of scanner -->
        <div class="alert alert-warning" style="max-width:520px;margin:0 auto">
          <span class="alert-icon">⚠️</span>
          <div>
            <strong>No units enrolled.</strong>
            You are not enrolled in any units for the current semester.
            Please contact your administrator to be enrolled before scanning attendance.
          </div>
        </div>

      <?php else: ?>

        <div class="scan-page-wrapper">

          <h1 class="scan-page-title">Scan QR Code</h1>
          <p class="scan-page-subtitle">
            Hold your camera up to the QR code displayed by your lecturer.
            Attendance will be registered automatically.
          </p>

          <!-- ── Success result (hidden until scan succeeds) ─────────────── -->
          <div id="scan-success" style="display:none" class="scan-result-card">
            <span class="scan-result-icon">✅</span>
            <div class="scan-result-unit" id="result-unit">—</div>
            <div class="scan-result-time" id="result-time">—</div>
            <p style="margin-top:var(--space-4);font-size:var(--text-sm);
                      color:var(--color-text-secondary)">
              Your attendance has been recorded. You may close this page.
            </p>
            <div style="margin-top:var(--space-5);display:flex;
                        gap:var(--space-3);justify-content:center;flex-wrap:wrap">
              <a href="<?= BASE_URL ?>/student/attendance"
                 class="btn btn-secondary btn-sm">View My Attendance</a>
              <a href="<?= BASE_URL ?>/student/dashboard"
                 class="btn btn-primary btn-sm">Back to Dashboard</a>
            </div>
          </div>

          <!-- ── Scanner card ────────────────────────────────────────────── -->
          <div id="scanner-card" class="viewfinder-card">

            <!-- Camera viewfinder -->
            <div class="camera-container">
              <video id="qr-video"
                     autoplay
                     muted
                     playsinline
                     aria-label="Camera viewfinder for QR scanning">
              </video>
              <!-- Hidden canvas used by jsQR for frame capture -->
              <canvas id="qr-canvas" aria-hidden="true"></canvas>
              <!-- Animated corner overlay — shown while scanning -->
              <div id="qr-overlay"></div>
              <div class="scan-line" id="scan-line"></div>
            </div>

            <!-- Status message -->
            <div id="scanner-status" aria-live="polite" aria-atomic="true">
              Press "Start Scanner" to activate your camera.
            </div>

            <!-- Action buttons -->
            <div class="scan-btn-group">
              <button id="start-btn" class="btn btn-primary">
                📷 Start Scanner
              </button>
              <button id="stop-btn" class="btn btn-danger" style="display:none">
                ⏹ Stop
              </button>
            </div>

          </div><!-- /viewfinder-card -->

          <!-- ── HTTPS warning (shown if not secure context) ─────────────── -->
          <div id="https-warning" class="alert alert-warning"
               style="display:none;margin-top:var(--space-4)">
            <span class="alert-icon">🔒</span>
            <div>
              <strong>Camera requires HTTPS on this network.</strong>
              You are accessing the app over an unencrypted (HTTP) connection from a
              network address. Browsers only allow camera access on HTTPS pages or
              on <code>localhost</code>.
              <ul style="margin:var(--space-2) 0 0;padding-left:var(--space-5)">
                <li>Ask your IT admin to enable HTTPS on the school server.</li>
                <li>Or use the <strong>manual token entry</strong> below as a temporary workaround.</li>
              </ul>
            </div>
          </div>

          <!-- ── Manual token fallback ───────────────────────────────────── -->
          <details class="manual-fallback" style="margin-top:var(--space-6)">
            <summary class="text-sm text-muted" style="cursor:pointer;user-select:none">
              📋 Can't use camera? Enter token manually
            </summary>
            <div style="margin-top:var(--space-4)">
              <div class="alert alert-info" style="margin-bottom:var(--space-4)">
                <span class="alert-icon">ℹ</span>
                <span>
                  Ask your lecturer to share the session token string.
                  Paste it below to register your attendance.
                </span>
              </div>
              <div class="form-group">
                <label class="form-label" for="manual-token">Session Token</label>
                <input type="text"
                       id="manual-token"
                       class="form-control font-mono"
                       placeholder="Paste the full QR payload here..."
                       autocomplete="off"
                       spellcheck="false">
                <div class="form-hint">
                  The token is a JSON string starting with {"v":1,"t":"..."}
                </div>
              </div>
              <button class="btn btn-primary" onclick="submitManualToken()">
                Submit Token
              </button>
              <div data-error-container
                   class="alert alert-error"
                   style="margin-top:var(--space-3)">
              </div>
            </div>
          </details>

          <!-- ── Enrolled units reference ────────────────────────────────── -->
          <div class="card" style="margin-top:var(--space-6);text-align:left">
            <div class="card-header">
              <div class="card-title">Your Enrolled Units</div>
              <div class="card-subtitle">
                Only sessions from these units will be accepted
              </div>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:var(--space-2)">
              <?php foreach ($enrolledUnits as $unit): ?>
                <span class="badge badge-info">
                  <?= htmlspecialchars($unit['code']) ?>
                </span>
              <?php endforeach; ?>
            </div>
          </div>

        </div><!-- /scan-page-wrapper -->

      <?php endif; ?>

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->

<!-- Mobile bottom nav -->
<nav class="mobile-nav">
  <a href="<?= BASE_URL ?>/student/dashboard" class="mobile-nav-item">
    <span class="nav-icon">🏠</span><span>Home</span>
  </a>
  <a href="<?= BASE_URL ?>/student/scan" class="mobile-nav-item active">
    <span class="nav-icon">📷</span><span>Scan</span>
  </a>
  <a href="<?= BASE_URL ?>/student/attendance" class="mobile-nav-item">
    <span class="nav-icon">📋</span><span>Attendance</span>
  </a>
  <a href="<?= BASE_URL ?>/student/marks" class="mobile-nav-item">
    <span class="nav-icon">📝</span><span>Marks</span>
  </a>
</nav>

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
<script src="<?= BASE_URL ?>/public/assets/js/qr-scanner.js"></script>
<script>
const BASE_URL = <?= json_encode(BASE_URL) ?>;

// ── Show HTTPS warning if not in a secure context ─────────────────────────────
const _isSecureCtx = location.protocol === 'https:'
                  || location.hostname === 'localhost'
                  || location.hostname === '127.0.0.1';

if (!_isSecureCtx) {
  document.getElementById('https-warning').style.display = 'flex';
}

// Also disable the Start Scanner button and show reason immediately if camera
// cannot possibly work (mediaDevices unavailable = insecure context on LAN)
if (!_isSecureCtx && (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia)) {
  const btn = document.getElementById('start-btn');
  if (btn) {
    btn.disabled = true;
    btn.title    = 'Camera requires HTTPS on network addresses';
  }
  document.getElementById('scanner-status').innerHTML =
    '<span style="color:var(--color-warning)">⚠️ Camera unavailable over HTTP on this network.' +
    ' Use the manual token entry below.</span>';
}

// ── Initialise QR scanner ─────────────────────────────────────────────────────
QRScanner.init({
  scanEndpoint: `${BASE_URL}/api/attendance/scan.php`,

  onSuccess: (data) => {
    // Hide scanner card, show success result
    document.getElementById('scanner-card').style.display = 'none';

    const successEl = document.getElementById('scan-success');
    successEl.style.display = 'block';

    document.getElementById('result-unit').textContent =
      data.unit_name || 'Attendance recorded';

    document.getElementById('result-time').textContent =
      'Recorded at ' + new Date(data.scanned_at || Date.now())
        .toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

    // Activate scan-line overlay while "scanning" was in progress
    const overlay = document.getElementById('qr-overlay');
    if (overlay) overlay.setAttribute('data-active', 'false');
  },

  onError: (data) => {
    // Non-fatal errors: scanner resets automatically after cooldown
    // For fatal errors (NOT_ENROLLED, HMAC_INVALID) scanner stops itself

    if (data.error_code === 'ALREADY_SCANNED') {
      // Special case: already scanned this session — show a softer message
      document.getElementById('scanner-card').style.display = 'none';

      const successEl = document.getElementById('scan-success');
      successEl.style.background  = 'var(--color-amber-light)';
      successEl.style.borderColor = 'var(--color-amber)';
      successEl.style.display     = 'block';

      document.getElementById('scan-success').querySelector('.scan-result-icon')
        .textContent = '🟡';
      document.getElementById('result-unit').textContent =
        'Already recorded for this session';
      document.getElementById('result-time').textContent =
        'Your attendance was already registered earlier.';
    }
  },
});

// Activate the scan-line animation when scanner is active
document.getElementById('start-btn').addEventListener('click', () => {
  setTimeout(() => {
    const overlay = document.getElementById('qr-overlay');
    if (overlay) overlay.setAttribute('data-active', 'true');
  }, 500);
});

document.getElementById('stop-btn').addEventListener('click', () => {
  const overlay = document.getElementById('qr-overlay');
  if (overlay) overlay.setAttribute('data-active', 'false');
});

// ── Manual token submission ───────────────────────────────────────────────────
async function submitManualToken() {
  const token    = document.getElementById('manual-token').value.trim();
  const errorEl  = document.querySelector('[data-error-container]');

  errorEl.textContent = '';
  errorEl.hidden      = true;

  if (!token) {
    errorEl.textContent = 'Please paste the session token first.';
    errorEl.hidden      = false;
    return;
  }

  try {
    const data = await Api.post(`${BASE_URL}/api/attendance/scan.php`, {
      qr_data: token,
    });

    if (data.success) {
      // Reuse the same success display as the QR scanner
      document.getElementById('scanner-card').style.display = 'none';
      document.querySelector('.manual-fallback').style.display = 'none';

      const successEl = document.getElementById('scan-success');
      successEl.style.display = 'block';

      document.getElementById('result-unit').textContent =
        data.unit_name || 'Attendance recorded';
      document.getElementById('result-time').textContent =
        'Recorded at ' + new Date().toLocaleTimeString([], {
          hour: '2-digit', minute: '2-digit'
        });
    }

  } catch (err) {
    errorEl.textContent = err.message || 'Submission failed. Please check the token.';
    errorEl.hidden      = false;
  }
}
</script>

</body>
</html>