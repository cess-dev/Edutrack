<?php
/**
 * EduTrack — Lecturer Session History
 *
 * Lists all attendance sessions created by this lecturer
 * with scan counts, status, and links to registers and reports.
 */

define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/AttendanceModel.php';

Auth::startSession();
Auth::requireRole('lecturer');

$user = Auth::user();

// ── Filters ───────────────────────────────────────────────────────────────────
$filterUnit = (int)($_GET['unit_id'] ?? 0);
$filterStatus = in_array($_GET['status'] ?? '', ['all','open','closed'], true)
    ? $_GET['status'] : 'all';
$page = max(1, (int)($_GET['page'] ?? 1));

// ── Units taught by this lecturer ─────────────────────────────────────────────
$units = DB::rows(
    "SELECT id, code, name FROM units
     WHERE lecturer_id = ? AND is_active = 1
     ORDER BY code ASC",
    [$user['id']]
);

// ── Build session query ───────────────────────────────────────────────────────
$perPage = ROWS_PER_PAGE;
$offset  = ($page - 1) * $perPage;

$conditions = ["s.lecturer_id = ?"];
$params     = [$user['id']];

if ($filterUnit > 0) {
    $conditions[] = "s.unit_id = ?";
    $params[]     = $filterUnit;
}

if ($filterStatus === 'open') {
    $conditions[] = "s.is_active = 1";
} elseif ($filterStatus === 'closed') {
    $conditions[] = "s.is_active = 0";
}

$where = 'WHERE ' . implode(' AND ', $conditions);

$totalRows = (int)(DB::row(
    "SELECT COUNT(*) AS cnt FROM attendance_sessions s {$where}",
    $params
)['cnt'] ?? 0);

$totalPages = max(1, (int)ceil($totalRows / $perPage));

$sessions = DB::rows(
    "SELECT
        s.id, s.started_at, s.closed_at, s.is_active,
        s.note, s.academic_year, s.semester,
        u.code AS unit_code, u.name AS unit_name,
        COUNT(CASE WHEN al.status = 'present' THEN 1 END) AS present_count,
        COUNT(CASE WHEN al.status = 'absent'  THEN 1 END) AS absent_count,
        COUNT(al.id) AS total_logged
     FROM attendance_sessions s
     JOIN units u ON u.id = s.unit_id
     LEFT JOIN attendance_logs al ON al.session_id = s.id
     {$where}
     GROUP BY s.id, s.started_at, s.closed_at, s.is_active,
              s.note, s.academic_year, s.semester, u.code, u.name
     ORDER BY s.started_at DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);

$csrfToken = Auth::csrfToken();
$pageTitle = 'Session History';
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
      <span class="topbar-title">Session History</span>
      <div class="topbar-actions">
        <button class="btn btn-primary btn-sm"
                onclick="window.location='<?= BASE_URL ?>/public/lecturer/dashboard.php'">
          ▶ Start New Session
        </button>
      </div>
    </header>

    <div class="page-content">

      <!-- Filter bar -->
      <div class="user-toolbar animate-fade-in">
        <form method="GET" style="display:flex;gap:var(--space-3);flex-wrap:wrap;flex:1">

          <!-- Unit filter -->
          <select name="unit_id" class="form-control"
                  style="width:auto;min-width:180px"
                  onchange="this.form.submit()">
            <option value="0">All Units</option>
            <?php foreach ($units as $u): ?>
              <option value="<?= $u['id'] ?>"
                      <?= $filterUnit === $u['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($u['code']) ?> — <?= htmlspecialchars($u['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <!-- Status filter -->
          <?php foreach (['all' => 'All','open' => '● Live','closed' => 'Closed'] as $s => $label): ?>
            <a href="?unit_id=<?= $filterUnit ?>&status=<?= $s ?>"
               class="filter-chip <?= $filterStatus === $s ? 'active' : '' ?>"
               style="text-decoration:none">
              <?= $label ?>
            </a>
          <?php endforeach; ?>

        </form>
        <span class="text-sm text-muted">
          <?= $totalRows ?> session<?= $totalRows !== 1 ? 's' : '' ?>
        </span>
      </div>

      <!-- Sessions table -->
      <div class="card animate-fade-in" style="animation-delay:0.05s">
        <?php if (empty($sessions)): ?>
          <div class="empty-state" style="padding:var(--space-12) 0">
            <span class="empty-icon">📋</span>
            <p class="empty-title">No sessions found</p>
            <p class="empty-text">
              Start an attendance session from the dashboard.
            </p>
          </div>

        <?php else: ?>
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>Date &amp; Time</th>
                  <th>Unit</th>
                  <th>Year / Sem</th>
                  <th>Present</th>
                  <th>Absent</th>
                  <th>Attendance</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($sessions as $s):
                  $total = $s['total_logged'];
                  $pct   = $total > 0
                      ? round(($s['present_count'] / $total) * 100)
                      : 0;
                  $pctColor = $pct >= 75
                      ? 'var(--color-success)'
                      : ($pct >= 60 ? 'var(--color-amber)' : 'var(--color-error)');
                ?>
                  <tr>
                    <td>
                      <div class="font-medium text-sm">
                        <?= date('D d M Y', strtotime($s['started_at'])) ?>
                      </div>
                      <div class="text-xs text-muted font-mono">
                        <?= date('H:i', strtotime($s['started_at'])) ?>
                        <?php if ($s['closed_at']): ?>
                          → <?= date('H:i', strtotime($s['closed_at'])) ?>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td>
                      <span class="font-mono text-xs font-semibold text-accent">
                        <?= htmlspecialchars($s['unit_code']) ?>
                      </span>
                      <div class="text-xs text-muted">
                        <?= htmlspecialchars($s['unit_name']) ?>
                      </div>
                    </td>
                    <td class="text-xs text-muted">
                      <?= htmlspecialchars($s['academic_year']) ?><br>
                      Sem <?= $s['semester'] ?>
                    </td>
                    <td class="font-semibold" style="color:var(--color-success)">
                      <?= $s['present_count'] ?>
                    </td>
                    <td class="font-semibold" style="color:var(--color-error)">
                      <?= $s['absent_count'] ?>
                    </td>
                    <td>
                      <?php if ($total > 0): ?>
                        <div style="display:flex;align-items:center;gap:var(--space-2)">
                          <div style="width:60px;height:6px;background:var(--color-bg-inset);
                                      border-radius:3px;overflow:hidden">
                            <div style="width:<?= $pct ?>%;height:100%;
                                        background:<?= $pctColor ?>;
                                        border-radius:3px"></div>
                          </div>
                          <span class="font-mono text-xs"
                                style="color:<?= $pctColor ?>"><?= $pct ?>%</span>
                        </div>
                      <?php else: ?>
                        <span class="text-muted text-xs">—</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($s['is_active']): ?>
                        <span class="badge badge-success">
                          ● Live
                        </span>
                      <?php else: ?>
                        <span class="badge badge-neutral">Closed</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div style="display:flex;gap:var(--space-2)">
                        <?php if ($s['is_active']): ?>
                          <a href="<?= BASE_URL ?>/public/lecturer/session_live.php?id=<?= $s['id'] ?>"
                             class="btn btn-primary btn-sm">
                            View Live
                          </a>
                        <?php else: ?>
                          <a href="<?= BASE_URL ?>/public/lecturer/session_live.php?id=<?= $s['id'] ?>"
                             class="btn btn-secondary btn-sm">
                            Register
                          </a>
                          <a href="<?= BASE_URL ?>/api/reports/class_report.php?session_id=<?= $s['id'] ?>"
                             class="btn btn-ghost btn-sm"
                             target="_blank"
                             title="Download PDF">
                            🖨️
                          </a>
                        <?php endif; ?>
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
                'unit_id' => $filterUnit   ?: null,
                'status'  => $filterStatus !== 'all' ? $filterStatus : null,
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
              <span class="page-info">Page <?= $page ?> of <?= $totalPages ?></span>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->

<nav class="mobile-nav">
  <a href="<?= BASE_URL ?>/public/lecturer/dashboard.php" class="mobile-nav-item">
    <span class="nav-icon">🏠</span><span>Home</span>
  </a>
  <a href="<?= BASE_URL ?>/public/lecturer/sessions.php" class="mobile-nav-item active">
    <span class="nav-icon">📋</span><span>Sessions</span>
  </a>
  <a href="<?= BASE_URL ?>/public/lecturer/marks.php" class="mobile-nav-item">
    <span class="nav-icon">📝</span><span>Marks</span>
  </a>
  <a href="<?= BASE_URL ?>/public/lecturer/disputes.php" class="mobile-nav-item">
    <span class="nav-icon">⚠️</span><span>Disputes</span>
  </a>
</nav>

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
</body>
</html>