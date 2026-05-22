<?php
/**
 * EduTrack — Live Session Page (Lecturer)
 *
 * Full-screen QR code display with:
 *   - Large QR code generated client-side from the session payload
 *   - Countdown timer synced to server-side expiry
 *   - Real-time student scan list (polled every LIVE_FEED_INTERVAL_SECONDS)
 *   - Manual attendance override controls
 *   - End Session button
 *
 * URL: /lecturer/session/live?id=SESSION_ID
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/helpers/QRHelper.php';

Auth::startSession();
Auth::requireRole('lecturer');

$user      = Auth::user();
$sessionId = (int) ($_GET['id'] ?? 0);

if ($sessionId <= 0) {
    header('Location: ' . BASE_URL . '/lecturer/dashboard');
    exit;
}

// Fetch session — must belong to this lecturer
$session = DB::row(
  "SELECT s.id, s.session_token, s.expires_at, s.is_active, s.note, s.closed_at,
      s.academic_year, s.semester,
      u.id   AS unit_id,
      u.code AS unit_code,
      u.name AS unit_name,
      c.name AS course_name
   FROM attendance_sessions s
   JOIN units u   ON u.id  = s.unit_id
   JOIN courses c ON c.id  = u.course_id
   WHERE s.id = ? AND s.lecturer_id = ?",
  [$sessionId, $user['id']]
);

if (!$session) {
    header('Location: ' . BASE_URL . '/lecturer/dashboard');
    exit;
}

// Build the QR payload the same way QRHelper does (read from DB token)
// We re-derive it here so the page can pass it to QRCode.js
$qrPayload = json_encode([
    'v' => 1,
    't' => $session['session_token'],
    's' => $session['id'],
    'u' => $session['unit_id'],
    'e' => $session['expires_at'],
], JSON_UNESCAPED_SLASHES);

// Enrolled student list for the manual mark dropdown
$enrolled = DB::rows(
    "SELECT u.id, u.reg_number, u.full_name
     FROM enrollments e
     JOIN users u ON u.id = e.student_id
     WHERE e.unit_id      = ?
       AND e.academic_year = ?
       AND e.semester      = ?
     ORDER BY u.full_name ASC",
    [$session['unit_id'], $session['academic_year'], $session['semester']]
);

$isClosed        = !$session['is_active'];
$expiresUnix     = strtotime($session['expires_at']);
$secondsLeft     = max(0, $expiresUnix - time());
$pollInterval    = LIVE_FEED_INTERVAL_SECONDS * 1000; // ms for JS
$csrfToken       = Auth::csrfToken();
$pageTitle       = 'Live Session — ' . $session['unit_code'];
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
  <!-- QRCode.js — generates QR image client-side from the payload string -->
  <script src="<?= BASE_URL ?>/public/assets/vendor/qrcode.min.js"></script>
</head>
<body>

<div class="layout">

  <?php include __DIR__ . '/../partials/sidebar_lecturer.php'; ?>

  <div class="main">

    <!-- Topbar -->
    <header class="topbar">
      <a href="<?= BASE_URL ?>/lecturer/dashboard"
         class="btn btn-ghost btn-sm" style="margin-right:var(--space-2)">
        ← Back
      </a>
      <span class="topbar-title">
        Live Session &nbsp;·&nbsp;
        <span class="font-mono" style="font-size:var(--text-sm)">
          <?= htmlspecialchars($session['unit_code']) ?>
        </span>
      </span>
      <div class="topbar-actions">
        <?php if (!$isClosed): ?>
          <button class="btn btn-danger btn-sm" onclick="endSession()">
            ⏹ End Session
          </button>
        <?php else: ?>
          <span class="badge badge-neutral" style="font-size:var(--text-sm)">
            Session Closed
          </span>
        <?php endif; ?>
      </div>
    </header>

    <div class="page-content">

      <?php if ($isClosed): ?>
        <!-- ── Session already closed ──────────────────────────────────── -->
        <div class="alert alert-warning animate-fade-in" style="margin-bottom:var(--space-6)">
          <span class="alert-icon">⚠️</span>
          <div>
            <strong>This session has been closed.</strong>
            Students can no longer scan. You can view the final register below.
          </div>
        </div>
      <?php endif; ?>

      <div class="grid grid-2" style="gap:var(--space-6);align-items:start">

        <!-- ── Left: QR Code panel ────────────────────────────────────── -->
        <div class="card animate-fade-in">

          <!-- Session info header -->
          <div style="text-align:center;margin-bottom:var(--space-5)">
            <div style="font-size:var(--text-xs);text-transform:uppercase;
                        letter-spacing:0.08em;color:var(--color-text-muted);
                        margin-bottom:var(--space-1)">
              <?= htmlspecialchars($session['course_name']) ?>
            </div>
            <h2 style="font-size:var(--text-xl);color:var(--color-primary);
                       margin-bottom:var(--space-1)">
              <?= htmlspecialchars($session['unit_name']) ?>
            </h2>
            <div class="font-mono text-sm text-muted">
              <?= htmlspecialchars($session['unit_code']) ?>
            </div>
            <?php if ($session['note']): ?>
              <div class="text-sm text-muted" style="margin-top:var(--space-2)">
                📝 <?= htmlspecialchars($session['note']) ?>
              </div>
            <?php endif; ?>
          </div>

          <?php if (!$isClosed): ?>
            <!-- QR Code display -->
            <div class="qr-panel">
              <div class="qr-code-container" id="qr-container">
                <div id="qr-canvas"></div>
              </div>

              <!-- Countdown timer -->
              <div class="qr-timer" id="countdown-display">
                <?= gmdate('i:s', $secondsLeft) ?>
              </div>
              <div class="qr-session-label">
                Time remaining &nbsp;·&nbsp; Session expires at
                <strong><?= date('H:i', $expiresUnix) ?></strong>
              </div>

              <!-- Progress bar -->
              <div style="max-width:300px;margin:0 auto var(--space-5)">
                <div style="height:6px;background:var(--color-bg-inset);
                            border-radius:var(--radius-full);overflow:hidden">
                  <div id="timer-bar"
                       style="height:100%;background:var(--color-accent);
                              border-radius:var(--radius-full);
                              transition:width 1s linear;
                              width:<?= round($secondsLeft / (ATTENDANCE_WINDOW_MINUTES * 60) * 100) ?>%">
                  </div>
                </div>
              </div>

              <div class="text-xs text-muted" style="margin-bottom:var(--space-4)">
                Students scan this code on their portal to register attendance.
              </div>
            </div>

          <?php else: ?>
            <!-- Closed state placeholder -->
            <div class="empty-state">
              <span class="empty-icon">🔒</span>
              <p class="empty-title">Session Closed</p>
              <p class="empty-text">
                Closed at <?= $session['closed_at'] ? date('H:i, d M Y', strtotime($session['closed_at'])) : 'N/A' ?>.
              </p>
              <a href="<?= BASE_URL ?>/lecturer/sessions"
                 class="btn btn-secondary btn-sm">
                View All Sessions
              </a>
            </div>
          <?php endif; ?>

        </div><!-- /card left -->

        <!-- ── Right: Live scan feed ──────────────────────────────────── -->
        <div class="card animate-fade-in" style="animation-delay:0.1s">
          <div class="card-header">
            <div>
              <div class="card-title">Live Attendance Feed</div>
              <div class="card-subtitle" id="feed-subtitle">Loading...</div>
            </div>
            <?php if (!$isClosed): ?>
              <button class="btn btn-secondary btn-sm" onclick="openManualModal()">
                ✏️ Manual Mark
              </button>
            <?php endif; ?>
          </div>

          <!-- Scan counters -->
          <div style="display:flex;gap:var(--space-6);margin-bottom:var(--space-5);
                      justify-content:center">
            <div style="text-align:center">
              <div class="scan-counter" id="present-count">—</div>
              <div class="scan-counter-label">Present</div>
            </div>
            <div style="text-align:center;color:var(--color-text-muted)">
              <div style="font-family:var(--font-heading);font-size:var(--text-4xl);
                          line-height:1">/</div>
            </div>
            <div style="text-align:center">
              <div class="scan-counter"
                   id="total-enrolled"
                   style="color:var(--color-text-secondary)">—</div>
              <div class="scan-counter-label">Enrolled</div>
            </div>
          </div>

          <!-- Student scan list -->
          <div id="live-feed" class="live-feed" style="max-height:360px;overflow-y:auto">
            <div class="empty-state" style="padding:var(--space-8) 0">
              <span class="empty-icon">⏳</span>
              <p class="empty-title">Waiting for scans</p>
              <p class="empty-text">Students who scan the QR code will appear here.</p>
            </div>
          </div>

        </div><!-- /card right -->

      </div><!-- /grid -->

      <!-- ── Full register table (always shown) ────────────────────────── -->
      <div class="card animate-fade-in" id="register-card"
           style="margin-top:var(--space-6);animation-delay:0.2s">
        <div class="card-header">
          <div>
            <div class="card-title">Class Register</div>
            <div class="card-subtitle">
              All enrolled students for this session
            </div>
          </div>
          <a href="<?= BASE_URL ?>/api/reports/class_report.php?session_id=<?= $sessionId ?>"
             class="btn btn-secondary btn-sm" target="_blank">
            🖨️ Export PDF
          </a>
        </div>

        <div class="table-wrap" id="register-wrap">
          <table class="table" id="register-table">
            <thead>
              <tr>
                <th>Reg. Number</th>
                <th>Student Name</th>
                <th>Status</th>
                <th>Method</th>
                <th>Time</th>
                <?php if (!$isClosed): ?>
                  <th>Action</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody id="register-body">
              <tr>
                <td colspan="<?= $isClosed ? 5 : 6 ?>"
                    style="text-align:center;padding:var(--space-6);
                           color:var(--color-text-muted)">
                  Loading register...
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->


<!-- ── Manual Mark Modal ─────────────────────────────────────────────────── -->
<div class="modal-backdrop" id="manual-modal" hidden>
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title">Manual Attendance Mark</h2>
      <button class="modal-close" onclick="closeManualModal()">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label" for="manual-student">
          Student <span class="required">*</span>
        </label>
        <select class="form-control" id="manual-student" required>
          <option value="">— Select student —</option>
          <?php foreach ($enrolled as $s): ?>
            <option value="<?= $s['id'] ?>">
              <?= htmlspecialchars($s['reg_number']) ?> —
              <?= htmlspecialchars($s['full_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Status <span class="required">*</span></label>
        <div style="display:flex;gap:var(--space-3)">
          <?php foreach (['present' => '✅ Present', 'absent' => '❌ Absent', 'excused' => '🟡 Excused'] as $val => $label): ?>
            <label style="display:flex;align-items:center;gap:var(--space-2);
                          cursor:pointer;font-size:var(--text-sm)">
              <input type="radio" name="manual-status"
                     value="<?= $val ?>"
                     <?= $val === 'present' ? 'checked' : '' ?>>
              <?= $label ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeManualModal()">Cancel</button>
      <button class="btn btn-primary" id="manual-submit-btn"
              onclick="submitManualMark()">
        Save Mark
      </button>
    </div>
  </div>
</div>


<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
<script>
const BASE_URL      = <?= json_encode(BASE_URL) ?>;
const SESSION_ID    = <?= json_encode($sessionId) ?>;
const IS_CLOSED     = <?= json_encode($isClosed) ?>;
const SECONDS_LEFT  = <?= json_encode($secondsLeft) ?>;
const WINDOW_SECS   = <?= json_encode(ATTENDANCE_WINDOW_MINUTES * 60) ?>;
const POLL_INTERVAL = <?= json_encode($pollInterval) ?>;
const QR_PAYLOAD    = <?= json_encode($qrPayload) ?>;

// ── Generate QR code ──────────────────────────────────────────────────────────
if (!IS_CLOSED && typeof QRCode !== 'undefined') {
  new QRCode(document.getElementById('qr-canvas'), {
    text:          QR_PAYLOAD,
    width:         260,
    height:        260,
    colorDark:     '#1A3C5E',
    colorLight:    '#ffffff',
    correctLevel:  QRCode.CorrectLevel.M,
  });
}

// ── Countdown timer ───────────────────────────────────────────────────────────
let secondsLeft = SECONDS_LEFT;
const countdownEl = document.getElementById('countdown-display');
const timerBar    = document.getElementById('timer-bar');

function updateCountdown() {
  if (!countdownEl || IS_CLOSED) return;

  const mins = Math.floor(secondsLeft / 60);
  const secs = secondsLeft % 60;
  const display = `${String(mins).padStart(2,'0')}:${String(secs).padStart(2,'0')}`;

  countdownEl.textContent = display;

  // Colour shifts
  countdownEl.className = 'qr-timer' +
    (secondsLeft <= 60  ? ' expired'  :
     secondsLeft <= 120 ? ' expiring' : '');

  // Progress bar
  if (timerBar) {
    const pct = (secondsLeft / WINDOW_SECS) * 100;
    timerBar.style.width = pct + '%';
    timerBar.style.background =
      secondsLeft <= 60  ? 'var(--color-error)'   :
      secondsLeft <= 120 ? 'var(--color-amber)'   :
                           'var(--color-accent)';
  }

  if (secondsLeft <= 0) {
    countdownEl.textContent = 'Expired';
    Toast.show('warning', 'QR code has expired. The session has been closed automatically.');
    stopPolling();
    return;
  }

  secondsLeft--;
}

let countdownTimer = null;
if (!IS_CLOSED && secondsLeft > 0) {
  updateCountdown();
  countdownTimer = setInterval(updateCountdown, 1000);
}

// ── Live feed polling ─────────────────────────────────────────────────────────
let pollTimer      = null;
let knownScanIds   = new Set();

async function fetchLiveFeed() {
  try {
    const data = await Api.get(`${BASE_URL}/api/attendance/live.php`, {
      session_id: SESSION_ID,
    });

    // Sync countdown to server time (prevents client drift)
    if (data.session && data.session.is_active) {
      secondsLeft = data.session.seconds_left;
    }

    // Update counters
    document.getElementById('present-count').textContent  = data.scan_count;
    document.getElementById('total-enrolled').textContent = data.total_enrolled;
    document.getElementById('feed-subtitle').textContent  =
      `${data.scan_count} of ${data.total_enrolled} scanned`;

    // Render new scans into the live feed
    renderLiveFeed(data.scans);

    // Refresh register
    fetchRegister();

  } catch {
    // Silent — network blip should not disrupt the display
  }
}

function renderLiveFeed(scans) {
  const feed = document.getElementById('live-feed');
  if (!scans || scans.length === 0) return;

  // Replace empty-state placeholder on first scan
  if (feed.querySelector('.empty-state')) {
    feed.innerHTML = '';
  }

  // Only add newly arrived scans (preserve scroll position)
  scans.forEach(scan => {
    const key = scan.student_id + '_' + scan.scanned_at;
    if (knownScanIds.has(key)) return;
    knownScanIds.add(key);

    const item = document.createElement('div');
    item.className = 'live-feed-item';
    item.innerHTML = `
      <div class="avatar" style="width:32px;height:32px;font-size:var(--text-xs);
           background:var(--color-accent-light);color:var(--color-accent);
           border-radius:50%;display:grid;place-items:center;font-weight:600;flex-shrink:0">
        ${(scan.full_name || '?')[0].toUpperCase()}
      </div>
      <div style="flex:1;overflow:hidden">
        <div style="font-size:var(--text-sm);font-weight:500;
                    white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
          ${escHtml(scan.full_name)}
        </div>
        <div class="font-mono text-xs text-muted">${escHtml(scan.reg_number)}</div>
      </div>
      <span class="badge badge-present">✓ Present</span>
      <span class="scan-time">${formatTime(scan.scanned_at)}</span>
    `;

    // Prepend so newest is at top
    feed.insertBefore(item, feed.firstChild);
  });
}

// ── Register table ────────────────────────────────────────────────────────────
async function fetchRegister() {
  try {
    const data = await Api.get(`${BASE_URL}/api/attendance/register.php`, {
      session_id: SESSION_ID,
    });

    renderRegister(data.rows || []);
  } catch {
    // Silent
  }
}

function renderRegister(rows) {
  const tbody   = document.getElementById('register-body');
  const isClosed = IS_CLOSED;

  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="${isClosed ? 5 : 6}"
      style="text-align:center;padding:var(--space-6);color:var(--color-text-muted)">
      No students enrolled for this session.</td></tr>`;
    return;
  }

  tbody.innerHTML = rows.map(r => {
    const statusBadge = {
      present: '<span class="badge badge-present">Present</span>',
      absent:  '<span class="badge badge-absent">Absent</span>',
      excused: '<span class="badge badge-excused">Excused</span>',
    }[r.status] || '<span class="badge badge-neutral">—</span>';

    const methodLabel = {
      qr_scan:     '📱 QR Scan',
      manual:      '✏️ Manual',
      auto_absent: '🤖 Auto',
    }[r.method] || r.method;

    const actionBtn = isClosed ? '' : `
      <td>
        <button class="btn btn-ghost btn-sm"
                onclick="quickMark(${r.student_id}, 'present')"
                title="Mark present">✅</button>
        <button class="btn btn-ghost btn-sm"
                onclick="quickMark(${r.student_id}, 'absent')"
                title="Mark absent">❌</button>
        <button class="btn btn-ghost btn-sm"
                onclick="quickMark(${r.student_id}, 'excused')"
                title="Mark excused">🟡</button>
      </td>`;

    return `<tr>
      <td class="font-mono text-xs">${escHtml(r.reg_number)}</td>
      <td class="text-sm">${escHtml(r.full_name)}</td>
      <td>${statusBadge}</td>
      <td class="text-xs text-muted">${methodLabel}</td>
      <td class="text-xs text-muted font-mono">
        ${r.scanned_at ? formatTime(r.scanned_at) : '—'}
      </td>
      ${actionBtn}
    </tr>`;
  }).join('');
}

// ── Quick mark from register row ──────────────────────────────────────────────
async function quickMark(studentId, status) {
  try {
    await Api.post(`${BASE_URL}/api/attendance/manual_mark.php`, {
      session_id: SESSION_ID,
      student_id: studentId,
      status:     status,
    });
    fetchRegister();
    fetchLiveFeed();
  } catch (err) {
    Api.showError(err);
  }
}

// ── End session ───────────────────────────────────────────────────────────────
async function endSession() {
  if (!confirm('End this session? Students will no longer be able to scan.')) return;

  try {
    const data = await Api.post(`${BASE_URL}/api/attendance/session_close.php`, {
      session_id: SESSION_ID,
    });

    Toast.show('success', data.message);
    stopPolling();

    // Reload page to show closed state
    setTimeout(() => window.location.reload(), 1500);

  } catch (err) {
    Api.showError(err);
  }
}

// ── Manual mark modal ─────────────────────────────────────────────────────────
function openManualModal()  { document.getElementById('manual-modal').hidden = false; }
function closeManualModal() { document.getElementById('manual-modal').hidden = true;  }

async function submitManualMark() {
  const studentId = document.getElementById('manual-student').value;
  const status    = document.querySelector('input[name="manual-status"]:checked')?.value;
  const btn       = document.getElementById('manual-submit-btn');

  if (!studentId) { alert('Please select a student.'); return; }
  if (!status)    { alert('Please select a status.');  return; }

  await Api.withLoading(btn, async () => {
    try {
      const data = await Api.post(`${BASE_URL}/api/attendance/manual_mark.php`, {
        session_id: SESSION_ID,
        student_id: parseInt(studentId),
        status:     status,
      });

      Toast.show('success', data.message || 'Mark saved.');
      closeManualModal();
      fetchRegister();
      fetchLiveFeed();

    } catch (err) {
      Api.showError(err);
    }
  });
}

// ── Polling control ───────────────────────────────────────────────────────────
function startPolling() {
  fetchLiveFeed(); // Immediate first fetch
  pollTimer = setInterval(fetchLiveFeed, POLL_INTERVAL);
}

function stopPolling() {
  if (pollTimer)     { clearInterval(pollTimer);     pollTimer = null;     }
  if (countdownTimer){ clearInterval(countdownTimer); countdownTimer = null; }
}

// ── Utilities ─────────────────────────────────────────────────────────────────
function escHtml(str) {
  return String(str ?? '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function formatTime(datetime) {
  if (!datetime) return '—';
  const d = new Date(datetime.replace(' ', 'T'));
  return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

// ── Init ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  fetchRegister();
  if (!IS_CLOSED) {
    startPolling();
  }
});

// Stop polling when tab is hidden (save battery / reduce server load)
document.addEventListener('visibilitychange', () => {
  if (document.hidden) {
    stopPolling();
  } else if (!IS_CLOSED) {
    startPolling();
  }
});
</script>

</body>
</html>