<?php
/**
 * EduTrack — Lecturer Dashboard
 *
 * Main landing page after lecturer login.
 * Shows:
 *   - Stat cards: units taught, total students, sessions run, pending disputes
 *   - Active session panel (if a session is currently live)
 *   - Units list with quick-start attendance button per unit
 *   - Recent sessions table
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/UserModel.php';
require_once __DIR__ . '/../../backend/models/AttendanceModel.php';

Auth::startSession();
Auth::requireRole('lecturer');

$user = Auth::user();

// ── Page data ─────────────────────────────────────────────────────────────────
$academicYear = DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year'"
)['setting_value'] ?? ACADEMIC_YEAR;

$semester = (int)(DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'active_semester'"
)['setting_value'] ?? ACTIVE_SEMESTER);

$stats = UserModel::getLecturerStats($user['id'], $academicYear, $semester);

// Units taught by this lecturer
$units = DB::rows(
    "SELECT u.id, u.code, u.name, u.credit_hours,
            c.name AS course_name,
            COUNT(DISTINCT e.student_id) AS enrolled_count,
            (SELECT COUNT(*) FROM attendance_sessions s
             WHERE s.unit_id = u.id AND s.is_active = 0
               AND s.academic_year = ? AND s.semester = ?) AS session_count
     FROM units u
     JOIN courses c ON c.id = u.course_id
     LEFT JOIN enrollments e
         ON e.unit_id = u.id
        AND e.academic_year = ?
        AND e.semester = ?
     WHERE u.lecturer_id = ? AND u.is_active = 1
     GROUP BY u.id, u.code, u.name, u.credit_hours, c.name
     ORDER BY u.code ASC",
    [$academicYear, $semester, $academicYear, $semester, $user['id']]
);

// Check for an active (live) session belonging to this lecturer
$activeSession = DB::row(
    "SELECT s.id, s.expires_at, u.code AS unit_code, u.name AS unit_name
     FROM attendance_sessions s
     JOIN units u ON u.id = s.unit_id
     WHERE s.lecturer_id = ? AND s.is_active = 1
     LIMIT 1",
    [$user['id']]
);

// Recent sessions (last 5)
$recentSessions = DB::rows(
    "SELECT s.id, s.started_at, s.closed_at, s.is_active,
            u.code AS unit_code, u.name AS unit_name,
            COUNT(CASE WHEN al.status = 'present' THEN 1 END) AS present_count,
            COUNT(al.id) AS total_logged
     FROM attendance_sessions s
     JOIN units u ON u.id = s.unit_id
     LEFT JOIN attendance_logs al ON al.session_id = s.id
     WHERE s.lecturer_id = ?
     GROUP BY s.id, s.started_at, s.closed_at, s.is_active, u.code, u.name
     ORDER BY s.started_at DESC
     LIMIT 5",
    [$user['id']]
);

$csrfToken = Auth::csrfToken();
$pageTitle = 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en" data-base-url="<?= htmlspecialchars(BASE_URL) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
  <title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars(APP_NAME) ?> Lecturer</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/base.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/lecturer.css">
</head>
<body>

<div class="layout">

  <!-- ── Sidebar ──────────────────────────────────────────────────────────── -->
  <?php include __DIR__ . '/../partials/sidebar_lecturer.php'; ?>

  <!-- ── Main ─────────────────────────────────────────────────────────────── -->
  <div class="main">

    <!-- Topbar -->
    <header class="topbar">
      <span class="topbar-title">Dashboard</span>
      <div class="topbar-actions">
        <span class="text-sm text-muted">
          <?= htmlspecialchars($academicYear) ?> &nbsp;·&nbsp; Semester <?= $semester ?>
        </span>
        <a href="<?= BASE_URL ?>/api/auth/logout"
           class="btn btn-ghost btn-sm"
           data-logout>
          Sign out
        </a>
      </div>
    </header>

    <div class="page-content">

      <!-- Welcome strip -->
      <div class="welcome-strip animate-fade-in">
        <div>
          <h1 class="welcome-name">
            Good <?= date('H') < 12 ? 'morning' : (date('H') < 17 ? 'afternoon' : 'evening') ?>,
            <?= htmlspecialchars(explode(' ', $user['full_name'])[0]) ?>
          </h1>
          <p class="text-muted text-sm">
            Here's what's happening across your units today.
          </p>
        </div>
        <button class="btn btn-primary" id="quick-start-btn" onclick="openSessionModal()">
          <span>▶</span> Start Attendance
        </button>
      </div>

      <!-- ── Stat cards ──────────────────────────────────────────────────── -->
      <div class="grid-stats" style="margin-bottom: var(--space-8)">

        <div class="stat-card animate-fade-in" style="animation-delay:0.05s">
          <div class="stat-icon" style="background:var(--color-accent-light);color:var(--color-accent)">📚</div>
          <div class="stat-body">
            <div class="stat-value"><?= $stats['units'] ?></div>
            <div class="stat-label">Units Taught</div>
          </div>
        </div>

        <div class="stat-card animate-fade-in" style="animation-delay:0.1s">
          <div class="stat-icon" style="background:#EEF0FB;color:var(--color-purple,#534AB7)">👥</div>
          <div class="stat-body">
            <div class="stat-value"><?= $stats['students'] ?></div>
            <div class="stat-label">Students Enrolled</div>
          </div>
        </div>

        <div class="stat-card animate-fade-in" style="animation-delay:0.15s">
          <div class="stat-icon" style="background:var(--color-amber-light);color:var(--color-amber)">📋</div>
          <div class="stat-body">
            <div class="stat-value"><?= $stats['sessions'] ?></div>
            <div class="stat-label">Sessions This Semester</div>
          </div>
        </div>

        <div class="stat-card animate-fade-in" style="animation-delay:0.2s">
          <div class="stat-icon" style="background:var(--color-error-light);color:var(--color-error)">⚠️</div>
          <div class="stat-body">
            <div class="stat-value"><?= $stats['pending_disputes'] ?></div>
            <div class="stat-label">Pending Disputes</div>
            <?php if ($stats['pending_disputes'] > 0): ?>
              <a href="<?= BASE_URL ?>/lecturer/disputes"
                 class="text-xs text-accent" style="margin-top:4px;display:block">
                Review now →
              </a>
            <?php endif; ?>
          </div>
        </div>

      </div>

      <!-- ── Active session alert ────────────────────────────────────────── -->
      <?php if ($activeSession): ?>
      <div class="active-session-banner animate-fade-in" id="active-banner">
        <div class="active-session-dot"></div>
        <div class="active-session-info">
          <strong>Live Session:</strong>
          <?= htmlspecialchars($activeSession['unit_code']) ?> —
          <?= htmlspecialchars($activeSession['unit_name']) ?>
          <span class="text-sm text-muted" style="margin-left:8px">
            Expires <?= date('H:i', strtotime($activeSession['expires_at'])) ?>
          </span>
        </div>
        <div class="active-session-actions">
          <a href="<?= BASE_URL ?>/lecturer/session/live?id=<?= $activeSession['id'] ?>"
             class="btn btn-primary btn-sm">
            View Live Feed
          </a>
          <button class="btn btn-danger btn-sm"
                  onclick="closeSession(<?= $activeSession['id'] ?>)">
            End Session
          </button>
        </div>
      </div>
      <?php endif; ?>

      <div class="grid grid-2" style="gap:var(--space-6)">

        <!-- ── Units list ────────────────────────────────────────────────── -->
        <div class="card animate-fade-in" style="animation-delay:0.25s">
          <div class="card-header">
            <div>
              <div class="card-title">My Units</div>
              <div class="card-subtitle"><?= count($units) ?> unit(s) this semester</div>
            </div>
            <a href="<?= BASE_URL ?>/public/lecturer/units.php" class="btn btn-secondary btn-sm">
              View all
            </a>
          </div>

          <?php if (empty($units)): ?>
            <div class="empty-state" style="padding:var(--space-10) 0">
              <span class="empty-icon">📚</span>
              <p class="empty-title">No units assigned</p>
              <p class="empty-text">Contact your administrator to be assigned to units.</p>
            </div>
          <?php else: ?>
            <div class="unit-list">
              <?php foreach ($units as $unit): ?>
              <div class="unit-row">
                <div class="unit-code-badge"><?= htmlspecialchars($unit['code']) ?></div>
                <div class="unit-details">
                  <div class="unit-name"><?= htmlspecialchars($unit['name']) ?></div>
                  <div class="unit-meta text-xs text-muted">
                    <?= htmlspecialchars($unit['course_name']) ?>
                    &nbsp;·&nbsp; <?= $unit['enrolled_count'] ?> students
                    &nbsp;·&nbsp; <?= $unit['session_count'] ?> sessions
                  </div>
                </div>
                <button class="btn btn-primary btn-sm"
                        onclick="openSessionModal(<?= $unit['id'] ?>, '<?= htmlspecialchars($unit['code'], ENT_QUOTES) ?>')">
                  ▶ Start
                </button>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- ── Recent sessions ──────────────────────────────────────────── -->
        <div class="card animate-fade-in" style="animation-delay:0.3s">
          <div class="card-header">
            <div>
              <div class="card-title">Recent Sessions</div>
              <div class="card-subtitle">Last 5 attendance sessions</div>
            </div>
            <a href="<?= BASE_URL ?>/lecturer/sessions" class="btn btn-secondary btn-sm">
              View all
            </a>
          </div>

          <?php if (empty($recentSessions)): ?>
            <div class="empty-state" style="padding:var(--space-10) 0">
              <span class="empty-icon">📋</span>
              <p class="empty-title">No sessions yet</p>
              <p class="empty-text">Start an attendance session from your units list.</p>
            </div>
          <?php else: ?>
            <div class="table-wrap">
              <table class="table">
                <thead>
                  <tr>
                    <th>Unit</th>
                    <th>Date</th>
                    <th>Present</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($recentSessions as $s): ?>
                  <tr>
                    <td>
                      <span class="font-mono text-xs"><?= htmlspecialchars($s['unit_code']) ?></span>
                    </td>
                    <td class="text-sm text-muted">
                      <?= date('d M, H:i', strtotime($s['started_at'])) ?>
                    </td>
                    <td>
                      <span class="font-semibold"><?= $s['present_count'] ?></span>
                      <span class="text-muted text-xs">/ <?= $s['total_logged'] ?></span>
                    </td>
                    <td>
                      <?php if ($s['is_active']): ?>
                        <span class="badge badge-success">● Live</span>
                      <?php else: ?>
                        <span class="badge badge-neutral">Closed</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

      </div><!-- /grid -->

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->


<!-- ── Start Session Modal ───────────────────────────────────────────────── -->
<div class="modal-backdrop" id="session-modal" hidden>
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title">Start Attendance Session</h2>
      <button class="modal-close" onclick="closeModal()" aria-label="Close">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label" for="modal-unit">
          Unit <span class="required">*</span>
        </label>
        <select class="form-control" id="modal-unit" required>
          <option value="">— Select a unit —</option>
          <?php foreach ($units as $unit): ?>
            <option value="<?= $unit['id'] ?>">
              <?= htmlspecialchars($unit['code']) ?> — <?= htmlspecialchars($unit['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label" for="modal-note">Session Note (optional)</label>
        <input type="text" class="form-control" id="modal-note"
               placeholder="e.g. Week 4 lecture, CAT review...">
      </div>
      <div class="alert alert-info" style="margin-bottom:0">
        <span class="alert-icon">ℹ</span>
        <span>
          A QR code will be generated valid for
          <strong><?= ATTENDANCE_WINDOW_MINUTES ?> minutes</strong>.
          Any existing open session for the selected unit will be closed automatically.
        </span>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
      <button class="btn btn-primary" id="start-session-btn" onclick="startSession()">
        <span>▶</span> Generate QR Code
      </button>
    </div>
  </div>
</div>


<!-- ── Mobile nav ────────────────────────────────────────────────────────── -->
<nav class="mobile-nav">
  <a href="<?= BASE_URL ?>/lecturer/dashboard" class="mobile-nav-item active">
    <span class="nav-icon">🏠</span><span>Home</span>
  </a>
  <a href="<?= BASE_URL ?>/public/lecturer/units.php" class="mobile-nav-item">
    <span class="nav-icon">📚</span><span>Units</span>
  </a>
  <a href="<?= BASE_URL ?>/lecturer/marks" class="mobile-nav-item">
    <span class="nav-icon">📝</span><span>Marks</span>
  </a>
  <a href="<?= BASE_URL ?>/lecturer/disputes" class="mobile-nav-item">
    <span class="nav-icon">⚠️</span><span>Disputes</span>
  </a>
</nav>


<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
<script>
const BASE_URL = <?= json_encode(BASE_URL) ?>;

// ── Session modal ─────────────────────────────────────────────────────────────
function openSessionModal(preselectedUnitId = null, unitCode = null) {
  const modal = document.getElementById('session-modal');
  modal.hidden = false;
  document.body.style.overflow = 'hidden';

  if (preselectedUnitId) {
    document.getElementById('modal-unit').value = preselectedUnitId;
  }

  requestAnimationFrame(() => {
    modal.querySelector('.modal').classList.add('modal-enter');
  });
}

function closeModal() {
  const modal = document.getElementById('session-modal');
  modal.hidden = true;
  document.body.style.overflow = '';
}

// Close modal on backdrop click
document.getElementById('session-modal').addEventListener('click', function(e) {
  if (e.target === this) closeModal();
});

// ── Start session ─────────────────────────────────────────────────────────────
async function startSession() {
  const unitId = document.getElementById('modal-unit').value;
  const note   = document.getElementById('modal-note').value.trim();
  const btn    = document.getElementById('start-session-btn');

  if (!unitId) {
    alert('Please select a unit.');
    return;
  }

  await Api.withLoading(btn, async () => {
    try {
      const data = await Api.post(`${BASE_URL}/api/attendance/session_create.php`, {
        unit_id: parseInt(unitId),
        note:    note || null,
      });

      closeModal();

      // Redirect to the live QR display page
      window.location.href = `${BASE_URL}/lecturer/session/live?id=${data.session_id}`;

    } catch (err) {
      Api.showError(err);
    }
  });
}

// ── Close session ─────────────────────────────────────────────────────────────
async function closeSession(sessionId) {
  if (!confirm('End this session? Students will no longer be able to scan.')) return;

  try {
    const data = await Api.post(`${BASE_URL}/api/attendance/session_close.php`, {
      session_id: sessionId,
    });

    Toast.show('success', data.message);

    // Remove the active banner
    const banner = document.getElementById('active-banner');
    if (banner) {
      banner.style.opacity = '0';
      setTimeout(() => banner.remove(), 300);
    }

  } catch (err) {
    Api.showError(err);
  }
}
</script>

</body>
</html>