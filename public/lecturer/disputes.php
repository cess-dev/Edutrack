<?php
/**
 * EduTrack — Lecturer Disputes Page
 *
 * Shows all attendance disputes for sessions taught by this lecturer.
 * Lecturers can:
 *   - Filter disputes by status (pending / approved / rejected / all)
 *   - View the student's reason
 *   - Approve (updates attendance log to 'excused') or Reject with a note
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/AttendanceModel.php';

Auth::startSession();
Auth::requireRole('lecturer');

$user = Auth::user();

// ── Filter ────────────────────────────────────────────────────────────────────
$validStatuses = ['pending', 'approved', 'rejected', 'all'];
$filterStatus  = in_array($_GET['status'] ?? '', $validStatuses, true)
    ? $_GET['status']
    : 'pending';

$disputes = AttendanceModel::getLecturerDisputes($user['id'], $filterStatus);

// Counts per status for tab badges
$counts = [];
foreach (['pending', 'approved', 'rejected', 'all'] as $s) {
    $counts[$s] = (int)(DB::row(
        "SELECT COUNT(*) AS cnt
         FROM disputes d
         JOIN attendance_sessions s ON s.id = d.session_id
         WHERE s.lecturer_id = ?" . ($s !== 'all' ? " AND d.status = '{$s}'" : ''),
        [$user['id']]
    )['cnt'] ?? 0);
}

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
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/lecturer.css">
</head>
<body>
<div class="layout">

  <?php include __DIR__ . '/../partials/sidebar_lecturer.php'; ?>

  <div class="main">
    <header class="topbar">
      <span class="topbar-title">Attendance Disputes</span>
      <div class="topbar-actions">
        <?php if ($counts['pending'] > 0): ?>
          <span class="badge badge-danger"><?= $counts['pending'] ?> pending</span>
        <?php endif; ?>
      </div>
    </header>

    <div class="page-content">

      <!-- Status filter tabs -->
      <div class="dispute-tabs animate-fade-in">
        <?php
          $tabLabels = [
            'pending'  => ['label' => 'Pending',  'icon' => '⏳'],
            'approved' => ['label' => 'Approved', 'icon' => '✅'],
            'rejected' => ['label' => 'Rejected', 'icon' => '❌'],
            'all'      => ['label' => 'All',      'icon' => '📋'],
          ];
          foreach ($tabLabels as $status => $meta):
        ?>
          <a href="?status=<?= $status ?>"
             class="dispute-tab <?= $filterStatus === $status ? 'active' : '' ?>">
            <span><?= $meta['icon'] ?></span>
            <span><?= $meta['label'] ?></span>
            <?php if ($counts[$status] > 0): ?>
              <span class="dispute-tab-count
                <?= $status === 'pending' ? 'count-pending' : 'count-neutral' ?>">
                <?= $counts[$status] ?>
              </span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>

      <!-- Disputes list -->
      <?php if (empty($disputes)): ?>
        <div class="empty-state animate-fade-in">
          <span class="empty-icon">
            <?= $filterStatus === 'pending' ? '✅' : '📋' ?>
          </span>
          <p class="empty-title">
            <?= $filterStatus === 'pending'
                ? 'No pending disputes'
                : 'No disputes found' ?>
          </p>
          <p class="empty-text">
            <?= $filterStatus === 'pending'
                ? 'All attendance disputes have been reviewed.'
                : 'No disputes match the selected filter.' ?>
          </p>
        </div>

      <?php else: ?>
        <div class="disputes-list animate-fade-in">
          <?php foreach ($disputes as $d): ?>
            <div class="dispute-card <?= $d['status'] === 'pending' ? 'dispute-pending' : '' ?>"
                 id="dispute-<?= $d['id'] ?>">

              <!-- Card header -->
              <div class="dispute-card-header">
                <div class="dispute-student-info">
                  <div class="dispute-avatar">
                    <?= strtoupper(substr($d['student_name'], 0, 1)) ?>
                  </div>
                  <div>
                    <div class="dispute-student-name">
                      <?= htmlspecialchars($d['student_name']) ?>
                    </div>
                    <div class="text-xs text-muted font-mono">
                      <?= htmlspecialchars($d['student_reg']) ?>
                    </div>
                  </div>
                </div>

                <div class="dispute-session-info">
                  <span class="badge badge-info font-mono text-xs">
                    <?= htmlspecialchars($d['unit_code']) ?>
                  </span>
                  <span class="text-sm text-muted">
                    <?= date('D d M Y, H:i', strtotime($d['session_date'])) ?>
                  </span>
                </div>

                <div class="dispute-status-wrap">
                  <?php
                    $badgeClass = match($d['status']) {
                      'pending'  => 'badge-warning',
                      'approved' => 'badge-success',
                      'rejected' => 'badge-danger',
                      default    => 'badge-neutral',
                    };
                  ?>
                  <span class="badge <?= $badgeClass ?>" id="status-badge-<?= $d['id'] ?>">
                    <?= ucfirst($d['status']) ?>
                  </span>
                </div>
              </div>

              <!-- Student's reason -->
              <div class="dispute-reason">
                <div class="dispute-reason-label">Student's Reason:</div>
                <p class="dispute-reason-text">
                  <?= htmlspecialchars($d['reason']) ?>
                </p>
              </div>

              <!-- Reviewer note (if already reviewed) -->
              <?php if ($d['reviewer_note']): ?>
                <div class="dispute-reviewer-note">
                  <div class="dispute-reason-label">
                    Your Note
                    <?php if ($d['reviewed_at']): ?>
                      <span class="text-muted">
                        · <?= date('d M Y', strtotime($d['reviewed_at'])) ?>
                      </span>
                    <?php endif; ?>
                  </div>
                  <p class="text-sm"><?= htmlspecialchars($d['reviewer_note']) ?></p>
                </div>
              <?php endif; ?>

              <!-- Submitted date -->
              <div class="dispute-meta text-xs text-muted">
                Submitted <?= date('d M Y, H:i', strtotime($d['created_at'])) ?>
                &nbsp;·&nbsp;
                Session: <?= htmlspecialchars($d['unit_name']) ?>
              </div>

              <!-- Actions (only shown for pending disputes) -->
              <?php if ($d['status'] === 'pending'): ?>
                <div class="dispute-actions" id="actions-<?= $d['id'] ?>">
                  <div class="form-group" style="margin-bottom:var(--space-3)">
                    <label class="form-label" for="note-<?= $d['id'] ?>">
                      Review Note (optional)
                    </label>
                    <input type="text"
                           id="note-<?= $d['id'] ?>"
                           class="form-control form-control-sm"
                           placeholder="Add a note for the student...">
                  </div>
                  <div style="display:flex;gap:var(--space-3)">
                    <button class="btn btn-primary btn-sm"
                            onclick="reviewDispute(<?= $d['id'] ?>, 'approved')">
                      ✅ Approve
                    </button>
                    <button class="btn btn-danger btn-sm"
                            onclick="reviewDispute(<?= $d['id'] ?>, 'rejected')">
                      ❌ Reject
                    </button>
                  </div>
                </div>
              <?php endif; ?>

            </div><!-- /dispute-card -->
          <?php endforeach; ?>
        </div>

      <?php endif; ?>

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->

<nav class="mobile-nav">
  <a href="<?= BASE_URL ?>/lecturer/dashboard" class="mobile-nav-item">
    <span class="nav-icon">🏠</span><span>Home</span>
  </a>
  <a href="<?= BASE_URL ?>/lecturer/sessions" class="mobile-nav-item">
    <span class="nav-icon">📋</span><span>Sessions</span>
  </a>
  <a href="<?= BASE_URL ?>/lecturer/marks" class="mobile-nav-item">
    <span class="nav-icon">📝</span><span>Marks</span>
  </a>
  <a href="<?= BASE_URL ?>/lecturer/disputes" class="mobile-nav-item active">
    <span class="nav-icon">⚠️</span><span>Disputes</span>
  </a>
</nav>

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
<script>
const BASE_URL = <?= json_encode(BASE_URL) ?>;

async function reviewDispute(disputeId, decision) {
  const note    = document.getElementById(`note-${disputeId}`)?.value?.trim() || '';
  const actionsEl = document.getElementById(`actions-${disputeId}`);
  const cardEl    = document.getElementById(`dispute-${disputeId}`);
  const badgeEl   = document.getElementById(`status-badge-${disputeId}`);

  const label   = decision === 'approved' ? 'Approve' : 'Reject';
  const confirmMsg = `${label} this dispute?` +
    (note ? `\n\nNote: "${note}"` : '');

  if (!confirm(confirmMsg)) return;

  try {
    const data = await Api.post(`${BASE_URL}/api/attendance/dispute_review.php`, {
      dispute_id: disputeId,
      decision:   decision,
      note:       note,
    });

    Toast.show('success', data.message);

    // Update badge in place
    badgeEl.className = 'badge ' +
      (decision === 'approved' ? 'badge-success' : 'badge-danger');
    badgeEl.textContent = decision.charAt(0).toUpperCase() + decision.slice(1);

    // Hide the action buttons with a fade
    if (actionsEl) {
      actionsEl.style.opacity = '0';
      actionsEl.style.transition = 'opacity 0.3s ease';
      setTimeout(() => actionsEl.remove(), 300);
    }

    // Remove pending highlight from card
    cardEl.classList.remove('dispute-pending');

    // Update pending count badge in topbar
    const pendingBadge = document.querySelector('.badge-danger');
    if (pendingBadge) {
      const currentCount = parseInt(pendingBadge.textContent);
      if (currentCount <= 1) {
        pendingBadge.remove();
      } else {
        pendingBadge.textContent = `${currentCount - 1} pending`;
      }
    }

  } catch (err) {
    Api.showError(err);
  }
}
</script>

<?php include __DIR__ . '/../partials/lecturer_ai_widget.php'; ?>
</body>
</html>