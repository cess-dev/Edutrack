<?php
/**
 * EduTrack — Admin Disputes Overview
 *
 * Shows attendance disputes across the school. Admins can view statuses
 * but should refer to lecturers for review actions.
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

Auth::startSession();
Auth::requireRole('admin');

$user = Auth::user();

$validStatuses = ['pending', 'approved', 'rejected', 'all'];
$filterStatus = in_array($_GET['status'] ?? '', $validStatuses, true)
    ? $_GET['status']
    : 'pending';

$where = '';
$params = [];
if ($filterStatus !== 'all') {
    $where = ' AND d.status = ?';
    $params[] = $filterStatus;
}

$disputes = DB::rows(
    "SELECT d.id, d.status, d.reason, d.reviewed_at, d.created_at,
            d.reviewer_note, stu.full_name AS student_name,
            stu.reg_number AS student_reg, u.code AS unit_code,
            u.name AS unit_name, lec.full_name AS lecturer_name,
            s.started_at AS session_started_at
     FROM disputes d
     JOIN users stu ON stu.id = d.student_id
     JOIN attendance_sessions s ON s.id = d.session_id
     JOIN units u ON u.id = s.unit_id
     JOIN users lec ON lec.id = s.lecturer_id
     WHERE 1=1 {$where}
     ORDER BY d.created_at DESC",
    $params
);

$counts = [];
foreach (['pending', 'approved', 'rejected', 'all'] as $status) {
    $statusWhere = $status === 'all' ? '' : ' AND d.status = ?';
    $statusParams = $status === 'all' ? [] : [$status];
    $counts[$status] = (int)(DB::row(
        "SELECT COUNT(*) AS cnt FROM disputes d WHERE 1=1{$statusWhere}",
        $statusParams
    )['cnt'] ?? 0);
}

$csrfToken = Auth::csrfToken();
$pageTitle = 'Disputes Overview';
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
      <span class="topbar-title">Disputes</span>
      <div class="topbar-actions">
        <span class="text-sm text-muted hidden-mobile">Review status for attendance disputes.</span>
      </div>
    </header>

    <div class="page-content">
      <div class="admin-welcome animate-fade-in">
        <div>
          <h1 class="welcome-name">Attendance Disputes</h1>
          <p class="text-muted text-sm">
            View all dispute submissions and their current review status.
          </p>
        </div>
      </div>

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

      <?php if (empty($disputes)): ?>
        <div class="empty-state animate-fade-in" style="margin-top:var(--space-6)">
          <span class="empty-icon">
            <?= $filterStatus === 'pending' ? '✅' : '📋' ?>
          </span>
          <p class="empty-title">
            <?= $filterStatus === 'pending' ? 'No pending disputes' : 'No disputes found' ?>
          </p>
          <p class="empty-text">
            <?= $filterStatus === 'pending'
                ? 'All attendance disputes have been processed.'
                : 'No disputes match the selected filter.' ?>
          </p>
        </div>
      <?php else: ?>
        <div class="disputes-list animate-fade-in" style="margin-top:var(--space-6)">
          <?php foreach ($disputes as $d): ?>
            <div class="dispute-card <?= $d['status'] === 'pending' ? 'dispute-pending' : '' ?>"
                 id="dispute-<?= $d['id'] ?>">

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
                    <?= date('D d M Y, H:i', strtotime($d['session_started_at'])) ?>
                  </span>
                </div>

                <div class="dispute-status-wrap">
                  <?php
                    if ($d['status'] === 'pending') {
                        $badgeClass = 'badge-warning';
                    } elseif ($d['status'] === 'approved') {
                        $badgeClass = 'badge-success';
                    } elseif ($d['status'] === 'rejected') {
                        $badgeClass = 'badge-danger';
                    } else {
                        $badgeClass = 'badge-neutral';
                    }
                  ?>
                  <span class="badge <?= $badgeClass ?>" id="status-badge-<?= $d['id'] ?>">
                    <?= ucfirst($d['status']) ?>
                  </span>
                </div>
              </div>

              <div class="dispute-reason">
                <div class="dispute-reason-label">Reason</div>
                <p class="dispute-reason-text">
                  <?= htmlspecialchars($d['reason']) ?>
                </p>
              </div>

              <?php if ($d['reviewer_note']): ?>
                <div class="dispute-reviewer-note">
                  <div class="dispute-reason-label">
                    Reviewer Note
                    <?php if ($d['reviewed_at']): ?>
                      <span class="text-muted">
                        · <?= date('d M Y', strtotime($d['reviewed_at'])) ?>
                      </span>
                    <?php endif; ?>
                  </div>
                  <p class="text-sm"><?= htmlspecialchars($d['reviewer_note']) ?></p>
                </div>
              <?php endif; ?>

              <div class="dispute-meta text-xs text-muted">
                Submitted <?= date('d M Y, H:i', strtotime($d['created_at'])) ?>
                &nbsp;·&nbsp;
                Lecturer: <?= htmlspecialchars($d['lecturer_name']) ?>
              </div>

              <div class="dispute-bottom-row">
                <div>
                  <span class="text-xs text-muted">Unit: <?= htmlspecialchars($d['unit_name']) ?></span>
                </div>
                <?php if ($d['status'] === 'pending'): ?>
                  <div class="text-xs text-warning">
                    Admins can view disputes here; lecturers must complete review.
                  </div>
                <?php endif; ?>
              </div>

            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->

</body>
</html>
