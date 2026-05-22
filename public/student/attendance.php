<?php
/**
 * EduTrack — Student Attendance History Page
 *
 * Shows the student's complete attendance log with:
 *   - Per-unit summary cards at the top
 *   - Filterable, paginated session-by-session log
 *   - Dispute submission for absent sessions within the window
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/AttendanceModel.php';

Auth::startSession();
Auth::requireRole('student');

$user = Auth::user();

$academicYear = DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year'"
)['setting_value'] ?? ACADEMIC_YEAR;

$semester = (int)(DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'active_semester'"
)['setting_value'] ?? ACTIVE_SEMESTER);

$threshold = (int)(DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'attendance_threshold'"
)['setting_value'] ?? ATTENDANCE_ALERT_THRESHOLD);

$disputeWindow = (int)(DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'dispute_window_hours'"
)['setting_value'] ?? DISPUTE_WINDOW_HOURS);

// ── Filters ───────────────────────────────────────────────────────────────────
$filterStatus = in_array($_GET['status'] ?? '', ['all','present','absent','excused'], true)
    ? $_GET['status'] : 'all';

$filterUnit = (int)($_GET['unit_id'] ?? 0);
$page       = max(1, (int)($_GET['page'] ?? 1));

// ── Unit summary ──────────────────────────────────────────────────────────────
$summary = AttendanceModel::getStudentSummary($user['id'], $academicYear, $semester);

// ── Enrolled units for filter dropdown ───────────────────────────────────────
$enrolledUnits = DB::rows(
    "SELECT u.id, u.code, u.name
     FROM enrollments e
     JOIN units u ON u.id = e.unit_id
     WHERE e.student_id = ? AND e.academic_year = ? AND e.semester = ?
     ORDER BY u.code ASC",
    [$user['id'], $academicYear, $semester]
);

// ── Attendance log with filters ───────────────────────────────────────────────
$perPage = ROWS_PER_PAGE;
$offset  = ($page - 1) * $perPage;

$conditions = ["al.student_id = ?"];
$params     = [$user['id']];

if ($filterStatus !== 'all') {
    $conditions[] = "al.status = ?";
    $params[]     = $filterStatus;
}

if ($filterUnit > 0) {
    $conditions[] = "s.unit_id = ?";
    $params[]     = $filterUnit;
}

$where = 'WHERE ' . implode(' AND ', $conditions);

$totalRows = (int)(DB::row(
    "SELECT COUNT(*) AS cnt
     FROM attendance_logs al
     JOIN attendance_sessions s ON s.id = al.session_id
     {$where}",
    $params
)['cnt'] ?? 0);

$totalPages = max(1, (int)ceil($totalRows / $perPage));

$logs = DB::rows(
    "SELECT
        al.id           AS log_id,
        al.status,
        al.method,
        al.scanned_at,
        s.id            AS session_id,
        s.started_at,
        s.closed_at,
        u.id            AS unit_id,
        u.code          AS unit_code,
        u.name          AS unit_name,
        lec.full_name   AS lecturer_name,
        d.id            AS dispute_id,
        d.status        AS dispute_status
     FROM attendance_logs al
     JOIN attendance_sessions s ON s.id  = al.session_id
     JOIN units u               ON u.id  = s.unit_id
     JOIN users lec             ON lec.id = s.lecturer_id
     LEFT JOIN disputes d
         ON d.session_id = s.id AND d.student_id = al.student_id
     {$where}
     ORDER BY s.started_at DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);

$csrfToken = Auth::csrfToken();
$pageTitle = 'My Attendance';
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
      <span class="topbar-title">My Attendance</span>
      <div class="topbar-actions">
        <a href="<?= BASE_URL ?>/student/scan"
           class="btn btn-primary btn-sm">
          📷 Scan QR
        </a>
      </div>
    </header>

    <div class="page-content">

      <!-- Unit summary cards -->
      <?php if (!empty($summary)): ?>
        <div class="grid-stats animate-fade-in" style="margin-bottom:var(--space-6)">
          <?php foreach ($summary as $unit):
            $pct    = (float)$unit['attendance_percent'];
            $status = $pct >= $threshold ? 'high' : ($pct >= 60 ? 'medium' : 'low');
            $col    = $pct >= $threshold
                ? 'var(--color-success)'
                : ($pct >= 60 ? 'var(--color-amber)' : 'var(--color-error)');
            $bg     = $pct >= $threshold
                ? 'var(--color-success-light)'
                : ($pct >= 60 ? 'var(--color-amber-light)' : 'var(--color-error-light)');
          ?>
            <a href="?unit_id=<?= $unit['unit_id'] ?>"
               class="stat-card"
               style="text-decoration:none;
                      <?= $filterUnit === (int)$unit['unit_id']
                          ? 'border-color:var(--color-accent);box-shadow:0 0 0 3px rgba(15,123,108,0.12);'
                          : '' ?>">
              <div class="stat-icon" style="background:<?= $bg ?>;color:<?= $col ?>">
                📚
              </div>
              <div class="stat-body">
                <div class="stat-value" style="font-size:var(--text-2xl);color:<?= $col ?>">
                  <?= $pct !== null ? $pct . '%' : 'N/A' ?>
                </div>
                <div class="stat-label font-mono" style="font-size:var(--text-xs)">
                  <?= htmlspecialchars($unit['unit_code']) ?>
                </div>
                <div class="text-xs text-muted">
                  <?= $unit['attended'] ?>/<?= $unit['total_sessions'] ?> sessions
                </div>
                <?php if ($pct < $threshold): ?>
                  <div class="text-xs" style="color:var(--color-error);margin-top:2px">
                    ⚠ Below <?= $threshold ?>% threshold
                  </div>
                <?php endif; ?>
              </div>
            </a>
          <?php endforeach; ?>

          <?php if ($filterUnit > 0): ?>
            <a href="?" class="stat-card"
               style="text-decoration:none;justify-content:center;
                      border-style:dashed;color:var(--color-text-muted)">
              <div style="text-align:center">
                <div style="font-size:1.5rem">✕</div>
                <div class="text-xs">Clear filter</div>
              </div>
            </a>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <!-- Filters bar -->
      <div class="attendance-filter-bar animate-fade-in">
        <span class="text-sm text-muted font-medium">Filter:</span>

        <?php
          $statusFilters = [
            'all'     => ['label' => 'All',     'icon' => '📋'],
            'present' => ['label' => 'Present', 'icon' => '✅'],
            'absent'  => ['label' => 'Absent',  'icon' => '❌'],
            'excused' => ['label' => 'Excused', 'icon' => '🟡'],
          ];
          foreach ($statusFilters as $s => $meta):
            $qs = http_build_query(array_filter([
                'status'  => $s,
                'unit_id' => $filterUnit ?: null,
            ]));
        ?>
          <a href="?<?= $qs ?>"
             class="filter-chip <?= $filterStatus === $s ? 'active' : '' ?>">
            <?= $meta['icon'] ?> <?= $meta['label'] ?>
          </a>
        <?php endforeach; ?>

        <?php if (!empty($enrolledUnits)): ?>
          <select class="form-control"
                  style="width:auto;font-size:var(--text-sm);padding:var(--space-2) var(--space-3)"
                  onchange="location.href='?status=<?= $filterStatus ?>&unit_id='+this.value">
            <option value="0">All Units</option>
            <?php foreach ($enrolledUnits as $u): ?>
              <option value="<?= $u['id'] ?>"
                      <?= $filterUnit === $u['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($u['code']) ?> — <?= htmlspecialchars($u['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>

        <span class="text-sm text-muted" style="margin-left:auto">
          <?= $totalRows ?> record<?= $totalRows !== 1 ? 's' : '' ?>
        </span>
      </div>

      <!-- Attendance log -->
      <div class="card animate-fade-in" style="animation-delay:0.1s">
        <?php if (empty($logs)): ?>
          <div class="empty-state" style="padding:var(--space-12) 0">
            <span class="empty-icon">📋</span>
            <p class="empty-title">No records found</p>
            <p class="empty-text">
              <?= $filterStatus !== 'all' || $filterUnit > 0
                  ? 'Try clearing the filters to see all records.'
                  : 'Scan a QR code in class to register your first attendance.' ?>
            </p>
          </div>

        <?php else: ?>
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>Date &amp; Time</th>
                  <th>Unit</th>
                  <th>Lecturer</th>
                  <th>Status</th>
                  <th>Method</th>
                  <th>Dispute</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($logs as $log):
                  // Can this absent record be disputed?
                  $canDispute = false;
                  if ($log['status'] === 'absent' && !$log['dispute_id'] && $log['closed_at']) {
                      $deadline    = strtotime($log['closed_at']) + ($disputeWindow * 3600);
                      $canDispute  = time() <= $deadline;
                  }

                  $methodLabel = [
                      'qr_scan'     => '📱 QR Scan',
                      'manual'      => '✏️ Manual',
                      'auto_absent' => '🤖 Auto',
                  ][$log['method']] ?? $log['method'];
                ?>
                  <tr>
                    <td class="text-sm">
                      <div class="font-medium">
                        <?= date('D d M Y', strtotime($log['started_at'])) ?>
                      </div>
                      <div class="text-xs text-muted font-mono">
                        <?= date('H:i', strtotime($log['started_at'])) ?>
                      </div>
                    </td>
                    <td>
                      <div class="font-mono text-xs text-accent font-semibold">
                        <?= htmlspecialchars($log['unit_code']) ?>
                      </div>
                      <div class="text-xs text-muted">
                        <?= htmlspecialchars($log['unit_name']) ?>
                      </div>
                    </td>
                    <td class="text-sm text-muted">
                      <?= htmlspecialchars($log['lecturer_name']) ?>
                    </td>
                    <td>
                      <span class="badge badge-<?= $log['status'] ?>">
                        <?= ucfirst($log['status']) ?>
                      </span>
                    </td>
                    <td class="text-xs text-muted"><?= $methodLabel ?></td>
                    <td>
                      <?php if ($log['dispute_id']): ?>
                        <!-- Dispute already submitted -->
                        <?php
                          $dBadge = match($log['dispute_status']) {
                            'pending'  => 'badge-warning',
                            'approved' => 'badge-success',
                            'rejected' => 'badge-danger',
                            default    => 'badge-neutral',
                          };
                        ?>
                        <span class="badge <?= $dBadge ?> text-xs">
                          <?= ucfirst($log['dispute_status']) ?>
                        </span>

                      <?php elseif ($canDispute): ?>
                        <!-- Eligible to dispute -->
                        <button class="btn btn-secondary btn-sm"
                                onclick="openDisputeModal(
                                  <?= $log['session_id'] ?>,
                                  '<?= htmlspecialchars($log['unit_code'], ENT_QUOTES) ?>',
                                  '<?= date('d M Y', strtotime($log['started_at'])) ?>'
                                )">
                          Submit Dispute
                        </button>

                      <?php elseif ($log['status'] === 'absent'): ?>
                        <!-- Window closed -->
                        <span class="text-xs text-muted">Window closed</span>

                      <?php else: ?>
                        <span class="text-muted text-xs">—</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Pagination -->
          <?php if ($totalPages > 1): ?>
            <div class="pagination">
              <?php
                $baseQs = http_build_query(array_filter([
                    'status'  => $filterStatus !== 'all' ? $filterStatus : null,
                    'unit_id' => $filterUnit ?: null,
                ]));
                $sep = $baseQs ? '&' : '';
              ?>
              <a href="?<?= $baseQs . $sep ?>page=<?= max(1, $page - 1) ?>"
                 class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">‹</a>

              <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                <a href="?<?= $baseQs . $sep ?>page=<?= $p ?>"
                   class="page-btn <?= $p === $page ? 'active' : '' ?>">
                  <?= $p ?>
                </a>
              <?php endfor; ?>

              <a href="?<?= $baseQs . $sep ?>page=<?= min($totalPages, $page + 1) ?>"
                 class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">›</a>

              <span class="page-info">
                Page <?= $page ?> of <?= $totalPages ?>
              </span>
            </div>
          <?php endif; ?>

        <?php endif; ?>
      </div>

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->


<!-- ── Dispute Modal ──────────────────────────────────────────────────────── -->
<div class="modal-backdrop" id="dispute-modal"
     hidden
     style="position:fixed;inset:0;background:rgba(14,42,66,0.55);
            backdrop-filter:blur(2px);z-index:200;display:flex;
            align-items:center;justify-content:center;padding:var(--space-4)">
  <div class="modal" style="max-width:480px">
    <div class="modal-header">
      <h2 class="modal-title">Submit Attendance Dispute</h2>
      <button class="modal-close" onclick="closeDisputeModal()">✕</button>
    </div>
    <div class="modal-body">
      <div class="alert alert-info" style="margin-bottom:var(--space-4)">
        <span class="alert-icon">ℹ</span>
        <div>
          Disputing attendance for
          <strong id="dispute-context"></strong>.
          Your lecturer will review your reason and approve or reject it.
        </div>
      </div>

      <div data-error-container class="alert alert-error"
           style="margin-bottom:var(--space-4)"></div>

      <div class="form-group">
        <label class="form-label" for="dispute-reason">
          Your Reason <span class="required">*</span>
        </label>
        <textarea id="dispute-reason"
                  class="form-control"
                  rows="4"
                  placeholder="Explain why you believe you were present for this session..."
                  style="resize:vertical"></textarea>
        <div class="form-hint">
          Be specific — mention any evidence (e.g. sat near the door,
          technical issue with phone camera, etc.)
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeDisputeModal()">Cancel</button>
      <button class="btn btn-primary" id="dispute-submit-btn"
              onclick="submitDispute()">
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
  <a href="<?= BASE_URL ?>/student/attendance" class="mobile-nav-item active">
    <span class="nav-icon">📋</span><span>Attendance</span>
  </a>
  <a href="<?= BASE_URL ?>/student/marks" class="mobile-nav-item">
    <span class="nav-icon">📝</span><span>Marks</span>
  </a>
</nav>

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
<script>
const BASE_URL = <?= json_encode(BASE_URL) ?>;
let activeSessionId = null;

// ── Dispute modal ─────────────────────────────────────────────────────────────
function openDisputeModal(sessionId, unitCode, sessionDate) {
  activeSessionId = sessionId;
  document.getElementById('dispute-context').textContent =
    `${unitCode} on ${sessionDate}`;
  document.getElementById('dispute-reason').value = '';
  const errEl = document.querySelector('#dispute-modal [data-error-container]');
  errEl.textContent = ''; errEl.hidden = true;

  document.getElementById('dispute-modal').hidden = false;
  document.body.style.overflow = 'hidden';
  setTimeout(() => document.getElementById('dispute-reason').focus(), 100);
}

function closeDisputeModal() {
  document.getElementById('dispute-modal').hidden = true;
  document.body.style.overflow = '';
  activeSessionId = null;
}

document.getElementById('dispute-modal').addEventListener('click', function(e) {
  if (e.target === this) closeDisputeModal();
});

async function submitDispute() {
  const reason = document.getElementById('dispute-reason').value.trim();
  const btn    = document.getElementById('dispute-submit-btn');
  const errEl  = document.querySelector('#dispute-modal [data-error-container]');

  errEl.textContent = ''; errEl.hidden = true;

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
        reason:     reason,
      });

      Toast.show('success', data.message);
      closeDisputeModal();

      // Reload page to reflect new dispute status in the table
      setTimeout(() => window.location.reload(), 1000);

    } catch (err) {
      errEl.textContent = err.message;
      errEl.hidden = false;
    }
  });
}
</script>

</body>
</html>