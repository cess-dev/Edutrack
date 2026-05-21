<?php
/**
 * EduTrack — Parent Attendance Detail Page
 *
 * Shows a linked child's full attendance record.
 * Read-only — parents can view but not modify anything.
 *
 * Features:
 *   - Child selector (if parent has multiple children)
 *   - Per-unit summary cards with alert indicators
 *   - Filterable paginated attendance log
 *   - Dispute status visibility (read-only)
 */

define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/UserModel.php';
require_once __DIR__ . '/../../backend/models/AttendanceModel.php';

Auth::startSession();
Auth::requireRole('parent');

$user = Auth::user();

// ── Linked children ───────────────────────────────────────────────────────────
$children = UserModel::getLinkedStudents($user['id']);

if (empty($children)) {
    header('Location: ' . BASE_URL . '/public/parent/dashboard.php');
    exit;
}

// ── Resolve which child to show ───────────────────────────────────────────────
$requestedId = (int)($_GET['student_id'] ?? $children[0]['id']);

// Verify parent is linked to the requested student
$child = null;
foreach ($children as $c) {
    if ($c['id'] === $requestedId) {
        $child = $c;
        break;
    }
}

if (!$child) {
    // Fallback to first child
    $child = $children[0];
}

// ── Academic context ──────────────────────────────────────────────────────────
$academicYear = DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year'"
)['setting_value'] ?? ACADEMIC_YEAR;

$semester = (int)(DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'active_semester'"
)['setting_value'] ?? ACTIVE_SEMESTER);

$threshold = (int)(DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'attendance_threshold'"
)['setting_value'] ?? ATTENDANCE_ALERT_THRESHOLD);

// ── Unit summary ──────────────────────────────────────────────────────────────
$summary = AttendanceModel::getStudentSummary($child['id'], $academicYear, $semester);

$overallAvg = 0;
if (!empty($summary)) {
    $overallAvg = round(
        array_sum(array_column($summary, 'attendance_percent')) / count($summary),
        1
    );
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filterStatus = in_array($_GET['status'] ?? '', ['all','present','absent','excused'], true)
    ? $_GET['status'] : 'all';

$filterUnit = (int)($_GET['unit_id'] ?? 0);
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = ROWS_PER_PAGE;
$offset     = ($page - 1) * $perPage;

// ── Build attendance log query ────────────────────────────────────────────────
$conditions = ["al.student_id = ?"];
$params     = [$child['id']];

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
        al.status,
        al.method,
        al.scanned_at,
        s.started_at,
        u.code          AS unit_code,
        u.name          AS unit_name,
        lec.full_name   AS lecturer_name,
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

// ── Enrolled units for filter ─────────────────────────────────────────────────
$enrolledUnits = DB::rows(
    "SELECT u.id, u.code, u.name
     FROM enrollments e
     JOIN units u ON u.id = e.unit_id
     WHERE e.student_id = ? AND e.academic_year = ? AND e.semester = ?
     ORDER BY u.code ASC",
    [$child['id'], $academicYear, $semester]
);

$csrfToken = Auth::csrfToken();
$pageTitle = htmlspecialchars(explode(' ', $child['full_name'])[0]) . "'s Attendance";
?>
<!DOCTYPE html>
<html lang="en" data-base-url="<?= htmlspecialchars(BASE_URL) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
  <title><?= $pageTitle ?> — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/base.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/parent.css">
</head>
<body>
<div class="layout">

  <?php include __DIR__ . '/../partials/sidebar_parent.php'; ?>

  <div class="main">
    <header class="topbar">
      <span class="topbar-title">Attendance Record</span>
      <div class="topbar-actions">
        <span class="readonly-notice">
          👁 Read-only view
        </span>
      </div>
    </header>

    <div class="page-content">

      <!-- Child selector (shown when parent has multiple children) -->
      <?php if (count($children) > 1): ?>
        <div class="parent-child-selector animate-fade-in">
          <span class="child-selector-label">Viewing:</span>
          <?php foreach ($children as $c): ?>
            <a href="?student_id=<?= $c['id'] ?>"
               class="filter-chip <?= $c['id'] === $child['id'] ? 'active' : '' ?>">
              <?= htmlspecialchars(explode(' ', $c['full_name'])[0]) ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <!-- Child identity card -->
      <div class="child-identity-card animate-fade-in">
        <div class="child-avatar-lg">
          <?= strtoupper(substr($child['full_name'], 0, 1)) ?>
        </div>
        <div class="child-identity-info">
          <div class="child-full-name"><?= htmlspecialchars($child['full_name']) ?></div>
          <div class="child-meta text-sm text-muted">
            <span class="font-mono"><?= htmlspecialchars($child['reg_number']) ?></span>
            &nbsp;·&nbsp; <?= htmlspecialchars(ucfirst($child['relationship'])) ?>
            &nbsp;·&nbsp; <?= $academicYear ?> Sem <?= $semester ?>
          </div>
        </div>
        <div class="child-overall-badge <?= $overallAvg >= $threshold ? 'badge-ok' : 'badge-alert' ?>">
          <div class="child-overall-pct"><?= $overallAvg ?>%</div>
          <div class="child-overall-label">Overall</div>
        </div>
      </div>

      <!-- Unit summary cards -->
      <?php if (!empty($summary)): ?>
        <div class="grid-stats animate-fade-in" style="margin-bottom:var(--space-6)">
          <?php foreach ($summary as $unit):
            $pct    = (float)$unit['attendance_percent'];
            $col    = $pct >= $threshold
                ? 'var(--color-success)'
                : ($pct >= 60 ? 'var(--color-amber)' : 'var(--color-error)');
            $bg     = $pct >= $threshold
                ? 'var(--color-success-light)'
                : ($pct >= 60 ? 'var(--color-amber-light)' : 'var(--color-error-light)');
            $status = $pct >= $threshold ? 'high' : ($pct >= 60 ? 'medium' : 'low');
          ?>
            <a href="?student_id=<?= $child['id'] ?>&unit_id=<?= $unit['unit_id'] ?>"
               class="stat-card"
               style="text-decoration:none;
                      <?= $filterUnit === (int)$unit['unit_id']
                          ? 'border-color:var(--color-accent);box-shadow:0 0 0 3px rgba(15,123,108,0.12);'
                          : '' ?>">
              <div class="stat-icon" style="background:<?= $bg ?>;color:<?= $col ?>">
                📚
              </div>
              <div class="stat-body">
                <div class="stat-value"
                     style="font-size:var(--text-2xl);color:<?= $col ?>">
                  <?= $pct ?>%
                </div>
                <div class="stat-label font-mono" style="font-size:var(--text-xs)">
                  <?= htmlspecialchars($unit['unit_code']) ?>
                </div>
                <div class="text-xs text-muted">
                  <?= $unit['attended'] ?>/<?= $unit['total_sessions'] ?> sessions
                </div>
                <?php if ($pct < $threshold): ?>
                  <div class="text-xs" style="color:var(--color-error);margin-top:2px">
                    ⚠ Below <?= $threshold ?>%
                  </div>
                <?php endif; ?>
              </div>
            </a>
          <?php endforeach; ?>

          <?php if ($filterUnit > 0): ?>
            <a href="?student_id=<?= $child['id'] ?>"
               class="stat-card"
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

      <!-- Attendance alert banner -->
      <?php
        $atRiskUnits = array_filter($summary, fn($u) => (float)$u['attendance_percent'] < $threshold);
        if (!empty($atRiskUnits)):
      ?>
        <div class="alert alert-error animate-fade-in" style="margin-bottom:var(--space-6)">
          <span class="alert-icon">⚠️</span>
          <div>
            <strong>Attendance Warning:</strong>
            <?= htmlspecialchars(explode(' ', $child['full_name'])[0]) ?>
            has attendance below <?= $threshold ?>% in
            <?= count($atRiskUnits) ?> unit<?= count($atRiskUnits) !== 1 ? 's' : '' ?>:
            <strong>
              <?= implode(', ', array_map(
                  fn($u) => htmlspecialchars($u['unit_code']),
                  $atRiskUnits
              )) ?>
            </strong>.
            Please contact the school if you need clarification.
          </div>
        </div>
      <?php endif; ?>

      <!-- Filter bar -->
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
                'student_id' => $child['id'],
                'status'     => $s,
                'unit_id'    => $filterUnit ?: null,
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
                  onchange="location.href='?student_id=<?= $child['id'] ?>&status=<?= $filterStatus ?>&unit_id='+this.value">
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

      <!-- Log table -->
      <div class="card animate-fade-in" style="animation-delay:0.1s">
        <?php if (empty($logs)): ?>
          <div class="empty-state" style="padding:var(--space-12) 0">
            <span class="empty-icon">📋</span>
            <p class="empty-title">No records found</p>
            <p class="empty-text">
              <?= $filterStatus !== 'all' || $filterUnit > 0
                  ? 'Try clearing the filters to see all records.'
                  : 'No attendance sessions have been recorded yet.' ?>
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
                  <th>Status</th>
                  <th>Recorded At</th>
                  <th>Dispute</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($logs as $log):
                  $methodLabel = [
                      'qr_scan'     => '📱 QR Scan',
                      'manual'      => '✏️ Manual',
                      'auto_absent' => '🤖 Auto',
                  ][$log['method']] ?? $log['method'];
                ?>
                  <tr>
                    <td>
                      <div class="font-medium text-sm">
                        <?= date('D d M Y', strtotime($log['started_at'])) ?>
                      </div>
                      <div class="text-xs text-muted font-mono">
                        <?= date('H:i', strtotime($log['started_at'])) ?>
                      </div>
                    </td>
                    <td>
                      <div class="font-mono text-xs font-semibold text-accent">
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
                    <td class="text-xs text-muted font-mono">
                      <?= $log['scanned_at']
                          ? date('H:i:s', strtotime($log['scanned_at']))
                          : '—' ?>
                      <div class="text-xs" style="color:var(--color-text-muted)">
                        <?= $methodLabel ?>
                      </div>
                    </td>
                    <td>
                      <?php if ($log['dispute_status']): ?>
                        <?php
                          $dBadge = match($log['dispute_status']) {
                            'pending'  => 'badge-warning',
                            'approved' => 'badge-success',
                            'rejected' => 'badge-danger',
                            default    => 'badge-neutral',
                          };
                        ?>
                        <span class="badge <?= $dBadge ?>">
                          <?= ucfirst($log['dispute_status']) ?>
                        </span>
                      <?php else: ?>
                        <span class="text-xs text-muted">—</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Pagination -->
          <?php if ($totalPages > 1):
            $baseQs = http_build_query(array_filter([
                'student_id' => $child['id'],
                'status'     => $filterStatus !== 'all' ? $filterStatus : null,
                'unit_id'    => $filterUnit ?: null,
            ]));
            $sep = $baseQs ? '&' : '';
          ?>
            <div class="pagination">
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

<nav class="mobile-nav">
  <a href="<?= BASE_URL ?>/public/parent/dashboard.php" class="mobile-nav-item">
    <span class="nav-icon">🏠</span><span>Home</span>
  </a>
  <a href="<?= BASE_URL ?>/public/parent/attendance.php?student_id=<?= $child['id'] ?>"
     class="mobile-nav-item active">
    <span class="nav-icon">📋</span><span>Attendance</span>
  </a>
  <a href="<?= BASE_URL ?>/public/parent/marks.php?student_id=<?= $child['id'] ?>"
     class="mobile-nav-item">
    <span class="nav-icon">📝</span><span>Marks</span>
  </a>
  <a href="<?= BASE_URL ?>/public/parent/profile.php" class="mobile-nav-item">
    <span class="nav-icon">👤</span><span>Profile</span>
  </a>
</nav>

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>

</body>
</html>