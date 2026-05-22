<?php
/**
 * EduTrack — Admin Audit Log
 *
 * Displays the full system audit trail with:
 *   - Filter by action type
 *   - Filter by user role
 *   - Date range filter
 *   - Search by actor name
 *   - Paginated results
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

Auth::startSession();
Auth::requireRole('admin');

$user = Auth::user();

// ── Filters ───────────────────────────────────────────────────────────────────
$filterAction = trim($_GET['action'] ?? '');
$filterRole   = trim($_GET['role']   ?? '');
$filterFrom   = trim($_GET['from']   ?? '');
$filterTo     = trim($_GET['to']     ?? '');
$search       = trim($_GET['search'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = ROWS_PER_PAGE;
$offset       = ($page - 1) * $perPage;

// ── Build query ───────────────────────────────────────────────────────────────
$conditions = [];
$params     = [];

if (!empty($filterAction)) {
    $conditions[] = "al.action = ?";
    $params[]     = $filterAction;
}

if (!empty($filterRole)) {
    $conditions[] = "u.role = ?";
    $params[]     = $filterRole;
}

if (!empty($filterFrom)) {
    $conditions[] = "al.created_at >= ?";
    $params[]     = $filterFrom . ' 00:00:00';
}

if (!empty($filterTo)) {
    $conditions[] = "al.created_at <= ?";
    $params[]     = $filterTo . ' 23:59:59';
}

if (!empty($search)) {
    $conditions[] = "u.full_name LIKE ?";
    $params[]     = '%' . $search . '%';
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$totalRows = (int)(DB::row(
    "SELECT COUNT(*) AS cnt
     FROM audit_logs al
     LEFT JOIN users u ON u.id = al.user_id
     {$where}",
    $params
)['cnt'] ?? 0);

$totalPages = max(1, (int)ceil($totalRows / $perPage));

$logs = DB::rows(
    "SELECT
        al.id,
        al.action,
        al.target_type,
        al.target_id,
        al.detail,
        al.ip_address,
        al.created_at,
        u.full_name AS actor_name,
        u.reg_number AS actor_reg,
        u.role       AS actor_role
     FROM audit_logs al
     LEFT JOIN users u ON u.id = al.user_id
     {$where}
     ORDER BY al.created_at DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);

// ── Distinct actions for filter dropdown ──────────────────────────────────────
$distinctActions = DB::rows(
    "SELECT DISTINCT action FROM audit_logs ORDER BY action ASC"
);

$csrfToken = Auth::csrfToken();
$pageTitle = 'Audit Log';

// ── Action icon map ───────────────────────────────────────────────────────────
$actionIcons = [
    'user_login'              => ['icon' => '🔑', 'color' => 'badge-info'],
    'user_logout'             => ['icon' => '🚪', 'color' => 'badge-neutral'],
    'user_created'            => ['icon' => '👤', 'color' => 'badge-success'],
    'user_updated'            => ['icon' => '✏️', 'color' => 'badge-info'],
    'user_activated'          => ['icon' => '✅', 'color' => 'badge-success'],
    'user_deactivated'        => ['icon' => '🚫', 'color' => 'badge-danger'],
    'password_reset'          => ['icon' => '🔐', 'color' => 'badge-warning'],
    'session_created'         => ['icon' => '▶️', 'color' => 'badge-success'],
    'session_closed'          => ['icon' => '⏹',  'color' => 'badge-neutral'],
    'all_sessions_closed'     => ['icon' => '⏹',  'color' => 'badge-danger'],
    'attendance_scanned'      => ['icon' => '📱', 'color' => 'badge-success'],
    'attendance_manual_mark'  => ['icon' => '✏️', 'color' => 'badge-warning'],
    'dispute_submitted'       => ['icon' => '🔔', 'color' => 'badge-warning'],
    'dispute_approved'        => ['icon' => '✅', 'color' => 'badge-success'],
    'dispute_rejected'        => ['icon' => '❌', 'color' => 'badge-danger'],
    'mark_saved'              => ['icon' => '📝', 'color' => 'badge-info'],
    'marks_bulk_uploaded'     => ['icon' => '📂', 'color' => 'badge-info'],
    'assessment_created'      => ['icon' => '📋', 'color' => 'badge-info'],
    'assessment_published'    => ['icon' => '📣', 'color' => 'badge-success'],
    'assessment_unpublished'  => ['icon' => '🔒', 'color' => 'badge-neutral'],
    'course_created'          => ['icon' => '📚', 'color' => 'badge-success'],
    'course_updated'          => ['icon' => '✏️', 'color' => 'badge-info'],
    'unit_created'            => ['icon' => '📖', 'color' => 'badge-success'],
    'unit_updated'            => ['icon' => '✏️', 'color' => 'badge-info'],
    'student_enrolled'        => ['icon' => '📋', 'color' => 'badge-success'],
    'student_unenrolled'      => ['icon' => '📋', 'color' => 'badge-danger'],
    'bulk_enrollment'         => ['icon' => '📋', 'color' => 'badge-info'],
    'parent_linked'           => ['icon' => '🔗', 'color' => 'badge-info'],
    'settings_updated'        => ['icon' => '⚙️', 'color' => 'badge-warning'],
];
?>
<!DOCTYPE html>
<html lang="en" data-base-url="<?= htmlspecialchars(BASE_URL) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
  <title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/base.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/admin.css">
</head>
<body>
<div class="layout">

  <?php include __DIR__ . '/../partials/sidebar_admin.php'; ?>

  <div class="main">
    <header class="topbar">
      <span class="topbar-title">Audit Log</span>
      <div class="topbar-actions">
        <span class="text-sm text-muted">
          <?= number_format($totalRows) ?> total record<?= $totalRows !== 1 ? 's' : '' ?>
        </span>
      </div>
    </header>

    <div class="page-content">

      <!-- Filter form -->
      <div class="card animate-fade-in" style="margin-bottom:var(--space-5)">
        <form method="GET" action="">
          <div style="display:grid;
                      grid-template-columns:1fr 1fr 1fr 1fr;
                      gap:var(--space-4);
                      margin-bottom:var(--space-4)">

            <!-- Search by actor -->
            <div class="form-group" style="margin-bottom:0">
              <label class="form-label">Actor Name</label>
              <input type="text"
                     name="search"
                     class="form-control"
                     placeholder="Search by user name..."
                     value="<?= htmlspecialchars($search) ?>">
            </div>

            <!-- Action filter -->
            <div class="form-group" style="margin-bottom:0">
              <label class="form-label">Action</label>
              <select name="action" class="form-control">
                <option value="">All actions</option>
                <?php foreach ($distinctActions as $a): ?>
                  <option value="<?= htmlspecialchars($a['action']) ?>"
                          <?= $filterAction === $a['action'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars(ucwords(str_replace('_',' ',$a['action']))) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Role filter -->
            <div class="form-group" style="margin-bottom:0">
              <label class="form-label">Role</label>
              <select name="role" class="form-control">
                <option value="">All roles</option>
                <?php foreach (['admin','lecturer','student','parent'] as $r): ?>
                  <option value="<?= $r ?>"
                          <?= $filterRole === $r ? 'selected' : '' ?>>
                    <?= ucfirst($r) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Date from -->
            <div class="form-group" style="margin-bottom:0">
              <label class="form-label">Date Range</label>
              <div style="display:flex;gap:var(--space-2);align-items:center">
                <input type="date" name="from" class="form-control"
                       value="<?= htmlspecialchars($filterFrom) ?>"
                       style="flex:1">
                <span class="text-muted text-xs">to</span>
                <input type="date" name="to" class="form-control"
                       value="<?= htmlspecialchars($filterTo) ?>"
                       style="flex:1">
              </div>
            </div>

          </div>

          <div style="display:flex;gap:var(--space-3);align-items:center">
            <button type="submit" class="btn btn-primary btn-sm">
              Apply Filters
            </button>
            <?php if ($filterAction||$filterRole||$filterFrom||$filterTo||$search): ?>
              <a href="?" class="btn btn-ghost btn-sm">Clear All</a>
              <span class="text-xs text-muted">
                Filters active — showing <?= number_format($totalRows) ?> of all records
              </span>
            <?php endif; ?>
          </div>
        </form>
      </div>

      <!-- Log table -->
      <div class="card animate-fade-in" style="animation-delay:0.05s">
        <?php if (empty($logs)): ?>
          <div class="empty-state" style="padding:var(--space-12) 0">
            <span class="empty-icon">🔍</span>
            <p class="empty-title">No records found</p>
            <p class="empty-text">
              <?= ($filterAction||$filterRole||$filterFrom||$filterTo||$search)
                  ? 'No audit entries match the current filters. Try clearing them.'
                  : 'No activity has been recorded yet.' ?>
            </p>
          </div>

        <?php else: ?>
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Action</th>
                  <th>Actor</th>
                  <th>Target</th>
                  <th>Details</th>
                  <th>IP Address</th>
                  <th>Date &amp; Time</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($logs as $log):
                  $meta        = $actionIcons[$log['action']] ?? ['icon'=>'📋','color'=>'badge-neutral'];
                  $actionLabel = ucwords(str_replace('_', ' ', $log['action']));

                  // Parse detail JSON
                  $detail = [];
                  if ($log['detail']) {
                      $detail = json_decode($log['detail'], true) ?? [];
                  }
                ?>
                  <tr>
                    <td class="text-xs text-muted font-mono">
                      <?= $log['id'] ?>
                    </td>
                    <td>
                      <div style="display:flex;align-items:center;gap:var(--space-2)">
                        <span style="font-size:1.1em"><?= $meta['icon'] ?></span>
                        <span class="badge <?= $meta['color'] ?>" style="font-size:10px">
                          <?= htmlspecialchars($actionLabel) ?>
                        </span>
                      </div>
                    </td>
                    <td>
                      <?php if ($log['actor_name']): ?>
                        <div class="font-medium text-sm">
                          <?= htmlspecialchars($log['actor_name']) ?>
                        </div>
                        <div class="text-xs text-muted">
                          <span class="font-mono"><?= htmlspecialchars($log['actor_reg'] ?? '') ?></span>
                          <?php if ($log['actor_role']): ?>
                            · <span class="badge badge-neutral" style="font-size:9px">
                                <?= $log['actor_role'] ?>
                              </span>
                          <?php endif; ?>
                        </div>
                      <?php else: ?>
                        <span class="text-muted text-sm">System</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-xs text-muted">
                      <?php if ($log['target_type']): ?>
                        <span class="font-mono">
                          <?= htmlspecialchars($log['target_type']) ?>
                          <?= $log['target_id'] ? '#' . $log['target_id'] : '' ?>
                        </span>
                      <?php else: ?>
                        —
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if (!empty($detail)): ?>
                        <div class="audit-detail-pills">
                          <?php
                            // Show up to 3 key-value pairs from the detail JSON
                            $count = 0;
                            foreach ($detail as $k => $v):
                              if ($count >= 3) break;
                              $count++;
                              $label = htmlspecialchars(ucwords(str_replace('_',' ',$k)));
                              $val   = is_array($v) ? implode(', ', $v) : htmlspecialchars((string)$v);
                          ?>
                            <span class="audit-detail-pill">
                              <span class="audit-detail-key"><?= $label ?>:</span>
                              <span class="audit-detail-val"><?= $val ?></span>
                            </span>
                          <?php endforeach; ?>
                          <?php if (count($detail) > 3): ?>
                            <span class="text-xs text-muted">
                              +<?= count($detail) - 3 ?> more
                            </span>
                          <?php endif; ?>
                        </div>
                      <?php else: ?>
                        <span class="text-muted text-xs">—</span>
                      <?php endif; ?>
                    </td>
                    <td class="font-mono text-xs text-muted">
                      <?= htmlspecialchars($log['ip_address'] ?? '—') ?>
                    </td>
                    <td class="text-xs">
                      <div class="font-medium">
                        <?= date('d M Y', strtotime($log['created_at'])) ?>
                      </div>
                      <div class="text-muted font-mono">
                        <?= date('H:i:s', strtotime($log['created_at'])) ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Pagination -->
          <?php if ($totalPages > 1):
            $baseQs = http_build_query(array_filter([
                'search' => $search       ?: null,
                'action' => $filterAction ?: null,
                'role'   => $filterRole   ?: null,
                'from'   => $filterFrom   ?: null,
                'to'     => $filterTo     ?: null,
            ]));
            $sep = $baseQs ? '&' : '';
          ?>
            <div class="pagination">
              <a href="?<?= $baseQs.$sep ?>page=<?= max(1,$page-1) ?>"
                 class="page-btn <?= $page<=1?'disabled':'' ?>">‹</a>
              <?php for($p=max(1,$page-2);$p<=min($totalPages,$page+2);$p++): ?>
                <a href="?<?= $baseQs.$sep ?>page=<?= $p ?>"
                   class="page-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a>
              <?php endfor; ?>
              <a href="?<?= $baseQs.$sep ?>page=<?= min($totalPages,$page+1) ?>"
                 class="page-btn <?= $page>=$totalPages?'disabled':'' ?>">›</a>
              <span class="page-info">
                Page <?= $page ?> of <?= $totalPages ?>
                · <?= number_format($totalRows) ?> records
              </span>
            </div>
          <?php endif; ?>

        <?php endif; ?>
      </div>

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>

<style>
.audit-detail-pills {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  max-width: 260px;
}

.audit-detail-pill {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  background: var(--color-bg-subtle);
  border: 1px solid var(--color-border-light);
  border-radius: var(--radius-full);
  padding: 2px 8px;
  font-size: 10px;
  white-space: nowrap;
  max-width: 200px;
  overflow: hidden;
  text-overflow: ellipsis;
}

.audit-detail-key {
  color: var(--color-text-muted);
  font-weight: var(--weight-semibold);
}

.audit-detail-val {
  color: var(--color-text);
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 120px;
}

@media (max-width: 1024px) {
  /* Hide details and IP on tablet */
  .table th:nth-child(5),
  .table td:nth-child(5),
  .table th:nth-child(6),
  .table td:nth-child(6) { display: none; }
}
</style>

</body>
</html>