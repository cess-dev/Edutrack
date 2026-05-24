<?php
/**
 * EduTrack — Student Attendance Disputes Page
 *
 * Shows the student's submitted attendance disputes and
 * provides a separate interface for raising new disputes.
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/AttendanceModel.php';

Auth::startSession();
Auth::requireRole('student');

$user = Auth::user();

$disputeWindow = (int)(DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'dispute_window_hours'"
)['setting_value'] ?? DISPUTE_WINDOW_HOURS);

$filterStatus = in_array($_GET['status'] ?? '', ['all', 'pending', 'approved', 'rejected'], true)
    ? $_GET['status'] : 'all';

$statusSql = '';
$params    = [$user['id']];
if ($filterStatus !== 'all') {
    $statusSql = ' AND d.status = ?';
    $params[]  = $filterStatus;
}

$disputes = DB::rows(
    "SELECT
        d.id,
        d.reason,
        d.status,
        d.reviewer_note,
        d.reviewed_at,
        d.created_at,
        u.code          AS unit_code,
        u.name          AS unit_name,
        s.started_at    AS session_date,
        lec.full_name   AS lecturer_name
     FROM disputes d
     JOIN attendance_sessions s ON s.id = d.session_id
     JOIN units u               ON u.id = s.unit_id
     JOIN users lec             ON lec.id = s.lecturer_id
     WHERE d.student_id = ?
       {$statusSql}
     ORDER BY d.created_at DESC",
    $params
);

$eligibleSessions = DB::rows(
    "SELECT
        al.session_id,
        s.started_at,
        s.closed_at,
        u.code      AS unit_code,
        u.name      AS unit_name,
        lec.full_name AS lecturer_name
     FROM attendance_logs al
     JOIN attendance_sessions s ON s.id = al.session_id
     JOIN units u ON u.id = s.unit_id
     JOIN users lec ON lec.id = s.lecturer_id
     LEFT JOIN disputes d
       ON d.session_id = s.id AND d.student_id = al.student_id
     WHERE al.student_id = ?
       AND al.status = 'absent'
       AND d.id IS NULL
       AND s.closed_at IS NOT NULL
       AND s.closed_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
     ORDER BY s.started_at DESC",
    [$user['id'], $disputeWindow]
);

$csrfToken = Auth::csrfToken();
$pageTitle = 'Attendance Disputes';
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
</head>
<body>
<div class="layout">

  <?php include __DIR__ . '/../partials/sidebar_student.php'; ?>

  <div class="main">
    <header class="topbar">
      <span class="topbar-title">Attendance Disputes</span>
      <div class="topbar-actions">
        <a href="<?= BASE_URL ?>/student/attendance" class="btn btn-secondary btn-sm">
          ← Back to Attendance
        </a>
      </div>
    </header>

    <div class="page-content">
      <div class="grid-stats animate-fade-in" style="margin-bottom:var(--space-6)">
        <div class="stat-card">
          <div class="stat-icon" style="background:var(--color-accent-light);color:var(--color-accent)">🔔</div>
          <div class="stat-body">
            <div class="stat-value"><?= count($disputes) ?></div>
            <div class="stat-label">Total Disputes</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:var(--color-warning-light);color:var(--color-warning)">⏳</div>
          <div class="stat-body">
            <div class="stat-value"><?= count(array_filter($disputes, fn($d) => $d['status'] === 'pending')) ?></div>
            <div class="stat-label">Pending</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:var(--color-success-light);color:var(--color-success)">✅</div>
          <div class="stat-body">
            <div class="stat-value"><?= count(array_filter($disputes, fn($d) => $d['status'] === 'approved')) ?></div>
            <div class="stat-label">Approved</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:var(--color-error-light);color:var(--color-error)">❌</div>
          <div class="stat-body">
            <div class="stat-value"><?= count(array_filter($disputes, fn($d) => $d['status'] === 'rejected')) ?></div>
            <div class="stat-label">Rejected</div>
          </div>
        </div>
      </div>

      <div class="card animate-fade-in" id="eligible-sessions">
        <div class="card-header">
          <div>
            <h2 class="card-title">Raise a New Dispute</h2>
            <p class="text-sm text-muted" style="margin-top:0.25rem">
              Select an absent session within the dispute window to request review.
            </p>
          </div>
        </div>
        <div class="card-toolbar" style="display:flex;justify-content:flex-end;gap:0.75rem;margin:0 0 var(--space-5);padding-bottom:var(--space-3);border-bottom:1px solid var(--color-border-light);">
          <?php if (!empty($eligibleSessions)): ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="openFirstDisputeModal()">
              Add New Dispute
            </button>
          <?php else: ?>
            <button type="button" class="btn btn-secondary btn-sm" disabled>
              No eligible sessions
            </button>
          <?php endif; ?>
        </div>

        <?php if (empty($eligibleSessions)): ?>
          <div class="empty-state" style="padding:var(--space-12) 0">
            <span class="empty-icon">✅</span>
            <p class="empty-title">No eligible absent sessions</p>
            <p class="empty-text">
              You do not have any absent sessions eligible for dispute right now.
              Check your attendance page for the latest record.
            </p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Unit</th>
                  <th>Lecturer</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($eligibleSessions as $session): ?>
                  <tr>
                    <td class="text-sm">
                      <div class="font-medium"><?= date('D d M Y', strtotime($session['started_at'])) ?></div>
                      <div class="text-xs text-muted font-mono"><?= date('H:i', strtotime($session['started_at'])) ?></div>
                    </td>
                    <td>
                      <div class="font-mono text-xs text-accent font-semibold"><?= htmlspecialchars($session['unit_code']) ?></div>
                      <div class="text-xs text-muted"><?= htmlspecialchars($session['unit_name']) ?></div>
                    </td>
                    <td class="text-sm text-muted"><?= htmlspecialchars($session['lecturer_name']) ?></td>
                    <td>
                      <button class="btn btn-secondary btn-sm"
                              onclick="openDisputeModal(
                                <?= $session['session_id'] ?>,
                                '<?= htmlspecialchars($session['unit_code'], ENT_QUOTES) ?>',
                                '<?= date('d M Y', strtotime($session['started_at'])) ?>'
                              )">
                        Submit Dispute
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <div class="card animate-fade-in" style="margin-top:var(--space-6)">
        <div class="card-header">
          <div>
            <h2 class="card-title">My Disputes</h2>
            <p class="text-sm text-muted" style="margin-top:0.25rem">
              Review the status of your submitted disputes.
            </p>
          </div>
          <div class="dispute-tabs">
            <?php
              $tabs = [
                'all'      => 'All',
                'pending'  => 'Pending',
                'approved' => 'Approved',
                'rejected' => 'Rejected',
              ];
              foreach ($tabs as $status => $label):
                $qs = http_build_query(['status' => $status]);
            ?>
              <a href="?<?= $qs ?>" class="dispute-tab <?= $filterStatus === $status ? 'active' : '' ?>">
                <?= $label ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if (empty($disputes)): ?>
          <div class="empty-state" style="padding:var(--space-12) 0">
            <span class="empty-icon">📝</span>
            <p class="empty-title">No disputes found</p>
            <p class="empty-text">
              <?= $filterStatus !== 'all'
                  ? 'No disputes match the selected filter.'
                  : 'You have not submitted any attendance disputes yet.' ?>
            </p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>Submitted</th>
                  <th>Session</th>
                  <th>Unit</th>
                  <th>Status</th>
                  <th>Lecturer</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($disputes as $dispute): ?>
                  <tr>
                    <td class="text-sm">
                      <div class="font-medium"><?= date('d M Y', strtotime($dispute['created_at'])) ?></div>
                      <div class="text-xs text-muted font-mono"><?= date('H:i', strtotime($dispute['created_at'])) ?></div>
                    </td>
                    <td class="text-sm">
                      <div class="font-medium"><?= date('D d M Y', strtotime($dispute['session_date'])) ?></div>
                    </td>
                    <td>
                      <div class="font-mono text-xs text-accent font-semibold"><?= htmlspecialchars($dispute['unit_code']) ?></div>
                      <div class="text-xs text-muted"><?= htmlspecialchars($dispute['unit_name']) ?></div>
                    </td>
                    <td>
                      <?php
                        $statusClass = match ($dispute['status']) {
                          'pending'  => 'badge-warning',
                          'approved' => 'badge-success',
                          'rejected' => 'badge-danger',
                          default    => 'badge-neutral',
                        };
                      ?>
                      <span class="badge <?= $statusClass ?> text-xs">
                        <?= ucfirst($dispute['status']) ?>
                      </span>
                    </td>
                    <td class="text-sm text-muted"><?= htmlspecialchars($dispute['lecturer_name']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->

<div class="modal-backdrop" id="dispute-modal"
     style="display:none;position:fixed;inset:0;background:rgba(14,42,66,0.55);
            backdrop-filter:blur(2px);z-index:200;align-items:center;
            justify-content:center;padding:var(--space-4)">
  <div class="modal" style="max-width:520px">
    <div class="modal-header">
      <h2 class="modal-title">Submit Attendance Dispute</h2>
      <button type="button" class="modal-close" onclick="closeDisputeModal()">✕</button>
    </div>
    <div class="modal-body">
      <div class="alert alert-info" style="margin-bottom:var(--space-4)">
        <span class="alert-icon">ℹ</span>
        <div>
          Disputing attendance for <strong id="dispute-context"></strong>.
          Your lecturer will review your reason and approve or reject it.
        </div>
      </div>

      <div data-error-container class="alert alert-error" hidden
           style="margin-bottom:var(--space-4)"></div>

      <div class="form-group">
        <label class="form-label" for="dispute-reason">
          Your Reason <span class="required">*</span>
        </label>
        <textarea id="dispute-reason" class="form-control" rows="4"
                  placeholder="Explain why you believe you were present for this session..."
                  style="resize:vertical"></textarea>
        <div class="form-hint">
          Be specific — mention any evidence such as location, device issues, or other details.
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeDisputeModal()">Cancel</button>
      <button type="button" class="btn btn-primary" id="dispute-submit-btn" onclick="submitDispute()">
        Submit Dispute
      </button>
    </div>
  </div>
</div>

<nav class="mobile-nav">
  <a href="<?= BASE_URL ?>/student/dashboard" class="mobile-nav-item">
    <span class="nav-icon">🏠</span><span>Home</span>
  </a>
  <a href="<?= BASE_URL ?>/student/scan" class="mobile-nav-item">
    <span class="nav-icon">📷</span><span>Scan</span>
  </a>
  <a href="<?= BASE_URL ?>/student/attendance" class="mobile-nav-item">
    <span class="nav-icon">📋</span><span>Attendance</span>
  </a>
  <a href="<?= BASE_URL ?>/student/disputes" class="mobile-nav-item active">
    <span class="nav-icon">🔔</span><span>Disputes</span>
  </a>
  <a href="<?= BASE_URL ?>/student/marks" class="mobile-nav-item">
    <span class="nav-icon">📝</span><span>Marks</span>
  </a>
</nav>

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
<script>
const BASE_URL = <?= json_encode(BASE_URL) ?>;
const modal = document.getElementById('dispute-modal');
let activeSessionId = null;

function openDisputeModal(sessionId, unitCode, sessionDate) {
  activeSessionId = sessionId;
  document.getElementById('dispute-context').textContent = `${unitCode} on ${sessionDate}`;
  document.getElementById('dispute-reason').value = '';
  const errEl = document.querySelector('#dispute-modal [data-error-container]');
  errEl.textContent = '';
  errEl.hidden = true;

  modal.style.display = 'flex';
  document.body.style.overflow = 'hidden';
  setTimeout(() => document.getElementById('dispute-reason').focus(), 100);
}

function closeDisputeModal() {
  modal.style.display = 'none';
  document.body.style.overflow = '';
  activeSessionId = null;
}

modal.addEventListener('click', function(e) {
  if (e.target === this) closeDisputeModal();
});

document.addEventListener('keydown', function(event) {
  if (event.key === 'Escape' && modal.style.display === 'flex') {
    closeDisputeModal();
  }
});

async function submitDispute() {
  const reason = document.getElementById('dispute-reason').value.trim();
  const btn = document.getElementById('dispute-submit-btn');
  const errEl = document.querySelector('#dispute-modal [data-error-container]');

  errEl.textContent = '';
  errEl.hidden = true;

  if (!reason) {
    errEl.textContent = 'Please enter a reason for the dispute.';
    errEl.hidden = false;
    return;
  }

  if (reason.length < 20) {
    errEl.textContent = 'Please provide a more detailed reason (at least 20 characters).';
    errEl.hidden = false;
    return;
  }

  await Api.withLoading(btn, async () => {
    try {
      const data = await Api.post(`${BASE_URL}/api/attendance/dispute_submit.php`, {
        session_id: activeSessionId,
        reason: reason,
      });

      Toast.show('success', data.message);
      closeDisputeModal();
      setTimeout(() => window.location.reload(), 1000);
    } catch (err) {
      errEl.textContent = err.message;
      errEl.hidden = false;
    }
  });
}

function openFirstDisputeModal() {
  if (eligibleTargets.length === 0) {
    return;
  }
  const session = eligibleTargets[0];
  openDisputeModal(session.id, session.unit_code, session.session_date);
}

const eligibleTargets = <?= json_encode(array_map(fn($session) => [
    'id'          => (int)$session['session_id'],
    'unit_code'   => $session['unit_code'],
    'session_date'=> date('d M Y', strtotime($session['started_at'])),
], $eligibleSessions), JSON_THROW_ON_ERROR) ?>;

// Remove any query parameter that would otherwise open the modal by default.
const url = new URL(window.location.href);
if (url.searchParams.has('session_id')) {
  url.searchParams.delete('session_id');
  window.history.replaceState({}, document.title, url.toString());
}
</script>

</body>
</html>
