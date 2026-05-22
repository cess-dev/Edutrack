<?php
/**
 * EduTrack — Start Attendance Session
 *
 * Secondary landing page for lecturers to begin a new attendance session.
 * Reuses the "My Units" section from the dashboard with the same unit data.
 */

define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/UserModel.php';

Auth::startSession();
Auth::requireRole('lecturer');

$user = Auth::user();

$academicYear = DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year'"
)['setting_value'] ?? ACADEMIC_YEAR;

$semester = (int)(DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'active_semester'"
)['setting_value'] ?? ACTIVE_SEMESTER);

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

$csrfToken = Auth::csrfToken();
$pageTitle = 'Start Session';
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

  <?php include __DIR__ . '/../partials/sidebar_lecturer.php'; ?>

  <div class="main">

    <header class="topbar">
      <a href="<?= BASE_URL ?>/public/lecturer/dashboard.php"
         class="btn btn-ghost btn-sm" style="margin-right:var(--space-2)">
        ← Back
      </a>
      <span class="topbar-title">Start Session</span>
      <div class="topbar-actions">
        <a href="<?= BASE_URL ?>/public/lecturer/units.php"
           class="btn btn-secondary btn-sm">
          View all units
        </a>
      </div>
    </header>

    <div class="page-content" style="max-width:960px;margin:0 auto;">

      <div class="welcome-strip animate-fade-in">
        <div>
          <h1 class="welcome-name">Start Your Attendance Session</h1>
          <p class="text-muted text-sm">
            Select a unit below to generate the session QR code and begin attendance.
          </p>
        </div>
      </div>

      <div class="card animate-fade-in" style="margin-top:var(--space-8)">
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

    </div>

  </div>
</div>

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

<nav class="mobile-nav">
  <a href="<?= BASE_URL ?>/public/lecturer/dashboard.php" class="mobile-nav-item">
    <span class="nav-icon">🏠</span><span>Home</span>
  </a>
  <a href="<?= BASE_URL ?>/public/lecturer/units.php" class="mobile-nav-item active">
    <span class="nav-icon">📚</span><span>Units</span>
  </a>
  <a href="<?= BASE_URL ?>/public/lecturer/marks.php" class="mobile-nav-item">
    <span class="nav-icon">📝</span><span>Marks</span>
  </a>
  <a href="<?= BASE_URL ?>/public/lecturer/disputes.php" class="mobile-nav-item">
    <span class="nav-icon">⚠️</span><span>Disputes</span>
  </a>
</nav>

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
<script>
const BASE_URL = <?= json_encode(BASE_URL) ?>;

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

document.getElementById('session-modal').addEventListener('click', function(e) {
  if (e.target === this) closeModal();
});

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

      window.location.href = `${BASE_URL}/public/lecturer/session_live.php?id=${data.session_id}`;
    } catch (err) {
      Api.showError(err);
    }
  });
}
</script>

</body>
</html>
