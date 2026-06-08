<?php
/**
 * EduTrack — Admin Parent Reports
 *
 * Shows two sections:
 *   - Escalated incidents logged by the parent AI (bullying, missing child, etc.)
 *   - Absence reports submitted by parents via AI chat
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

Auth::startSession();
Auth::requireRole('admin');

$user = Auth::user();

$validTabs = ['incidents', 'absences'];
$tab = in_array($_GET['tab'] ?? '', $validTabs, true) ? $_GET['tab'] : 'incidents';

$validStatuses = ['open', 'reviewed', 'closed', 'all'];
$filterStatus  = in_array($_GET['status'] ?? '', $validStatuses, true)
    ? $_GET['status']
    : ($tab === 'incidents' ? 'open' : 'all');

// ── Incidents ─────────────────────────────────────────────────────────────────
$incidentWhere  = $filterStatus !== 'all' ? ' AND i.status = ?' : '';
$incidentParams = $filterStatus !== 'all' ? [$filterStatus] : [];

$incidents = DB::rows(
    "SELECT i.id, i.type, i.description, i.status,
            i.created_at, i.reviewed_at,
            p.full_name  AS parent_name,
            s.full_name  AS student_name,
            s.reg_number AS student_reg,
            r.full_name  AS reviewer_name
     FROM parent_ai_incidents i
     JOIN users p ON p.id = i.parent_id
     LEFT JOIN users s ON s.id = i.student_id
     LEFT JOIN users r ON r.id = i.reviewed_by
     WHERE 1=1 {$incidentWhere}
     ORDER BY i.created_at DESC",
    $incidentParams
);

$incidentCounts = [];
foreach (['open', 'reviewed', 'closed', 'all'] as $st) {
    $w = $st === 'all' ? '' : ' AND status = ?';
    $p = $st === 'all' ? [] : [$st];
    $incidentCounts[$st] = (int)(DB::row(
        "SELECT COUNT(*) AS cnt FROM parent_ai_incidents WHERE 1=1{$w}", $p
    )['cnt'] ?? 0);
}

// ── Absence reports ───────────────────────────────────────────────────────────
$absenceWhere  = $filterStatus !== 'all' ? ' AND ar.status = ?' : '';
$absenceParams = $filterStatus !== 'all' ? [$filterStatus] : [];

$absenceStatuses = ['pending', 'acknowledged', 'all'];
$absenceFilter   = in_array($_GET['status'] ?? '', $absenceStatuses, true)
    ? ($_GET['status'] ?? 'all')
    : 'all';

$absenceWhere2  = $absenceFilter !== 'all' ? ' AND ar.status = ?' : '';
$absenceParams2 = $absenceFilter !== 'all' ? [$absenceFilter] : [];

$absences = DB::rows(
    "SELECT ar.id, ar.report_date, ar.reason, ar.proof_description,
            ar.notes, ar.status, ar.created_at,
            p.full_name  AS parent_name,
            s.full_name  AS student_name,
            s.reg_number AS student_reg
     FROM parent_absence_reports ar
     JOIN users p ON p.id = ar.parent_id
     JOIN users s ON s.id = ar.student_id
     WHERE 1=1 {$absenceWhere2}
     ORDER BY ar.created_at DESC",
    $absenceParams2
);

$absenceCounts = [];
foreach (['pending', 'acknowledged', 'all'] as $st) {
    $w = $st === 'all' ? '' : ' AND status = ?';
    $p = $st === 'all' ? [] : [$st];
    $absenceCounts[$st] = (int)(DB::row(
        "SELECT COUNT(*) AS cnt FROM parent_absence_reports WHERE 1=1{$w}", $p
    )['cnt'] ?? 0);
}

$csrfToken = Auth::csrfToken();
$pageTitle  = 'Parent Reports';

$typeLabels = [
    'missing_child'      => ['label' => 'Missing Child',     'icon' => '🔍', 'class' => 'badge-danger'],
    'medical_emergency'  => ['label' => 'Medical Emergency', 'icon' => '🏥', 'class' => 'badge-danger'],
    'bullying'           => ['label' => 'Bullying',          'icon' => '⚠️', 'class' => 'badge-warning'],
    'staff_complaint'    => ['label' => 'Staff Complaint',   'icon' => '📋', 'class' => 'badge-warning'],
    'general_concern'    => ['label' => 'General Concern',   'icon' => '💬', 'class' => 'badge-info'],
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
      <span class="topbar-title">Parent Reports</span>
      <div class="topbar-actions">
        <span class="text-sm text-muted hidden-mobile">
          Incidents and absence reports submitted via parent AI chat.
        </span>
      </div>
    </header>

    <div class="page-content">

      <!-- Page header -->
      <div class="admin-welcome animate-fade-in">
        <div>
          <h1 class="welcome-name">Parent Reports</h1>
          <p class="text-muted text-sm">
            Review escalated concerns and absence notifications from parents.
          </p>
        </div>
      </div>

      <!-- Tab switcher -->
      <div class="dispute-tabs animate-fade-in" style="margin-bottom:var(--space-4)">
        <button type="button"
                class="dispute-tab <?= $tab === 'incidents' ? 'active' : '' ?>"
                onclick="location.href='?tab=incidents&amp;status=open'">
          <span>🚨</span>
          <span>Incidents</span>
          <?php if ($incidentCounts['open'] > 0): ?>
            <span class="dispute-tab-count count-pending"><?= $incidentCounts['open'] ?></span>
          <?php endif; ?>
        </button>
        <button type="button"
                class="dispute-tab <?= $tab === 'absences' ? 'active' : '' ?>"
                onclick="location.href='?tab=absences&amp;status=all'">
          <span>📅</span>
          <span>Absence Reports</span>
          <?php if ($absenceCounts['pending'] > 0): ?>
            <span class="dispute-tab-count count-pending"><?= $absenceCounts['pending'] ?></span>
          <?php endif; ?>
        </button>
      </div>

      <?php if ($tab === 'incidents'): ?>

        <!-- Status filter tabs -->
        <div class="dispute-tabs animate-fade-in" style="margin-bottom:var(--space-6)">
          <?php
            $statusMeta = [
              'open'     => ['label' => 'Open',     'icon' => '🔴'],
              'reviewed' => ['label' => 'Reviewed', 'icon' => '🟡'],
              'closed'   => ['label' => 'Closed',   'icon' => '✅'],
              'all'      => ['label' => 'All',      'icon' => '📋'],
            ];
            foreach ($statusMeta as $st => $meta):
          ?>
            <button type="button"
                    class="dispute-tab <?= $filterStatus === $st ? 'active' : '' ?>"
                    style="font-size:var(--text-sm)"
                    onclick="location.href='?tab=incidents&amp;status=<?= $st ?>'">
              <span><?= $meta['icon'] ?></span>
              <span><?= $meta['label'] ?></span>
              <?php if ($incidentCounts[$st] > 0): ?>
                <span class="dispute-tab-count <?= $st === 'open' ? 'count-pending' : 'count-neutral' ?>">
                  <?= $incidentCounts[$st] ?>
                </span>
              <?php endif; ?>
            </button>
          <?php endforeach; ?>
        </div>

        <?php if (empty($incidents)): ?>
          <div class="empty-state animate-fade-in">
            <span class="empty-icon"><?= $filterStatus === 'open' ? '✅' : '📋' ?></span>
            <p class="empty-title">
              <?= $filterStatus === 'open' ? 'No open incidents' : 'No incidents found' ?>
            </p>
            <p class="empty-text">
              <?= $filterStatus === 'open'
                  ? 'All parent incidents have been reviewed or closed.'
                  : 'No incidents match the selected filter.' ?>
            </p>
          </div>
        <?php else: ?>
          <div class="disputes-list animate-fade-in">
            <?php foreach ($incidents as $inc):
              $typeMeta = $typeLabels[$inc['type']] ?? ['label' => ucfirst($inc['type']), 'icon' => '📋', 'class' => 'badge-info'];
            ?>
              <div class="dispute-card <?= $inc['status'] === 'open' ? 'dispute-pending' : '' ?>"
                   id="incident-<?= $inc['id'] ?>">

                <div class="dispute-card-header">
                  <div class="dispute-student-info">
                    <div class="dispute-avatar" style="background:var(--color-primary-light)">
                      <?= strtoupper(substr($inc['parent_name'], 0, 1)) ?>
                    </div>
                    <div>
                      <div class="dispute-student-name">
                        <?= htmlspecialchars($inc['parent_name']) ?>
                        <span class="text-muted text-xs"> (parent)</span>
                      </div>
                      <?php if ($inc['student_name']): ?>
                        <div class="text-xs text-muted">
                          Re: <?= htmlspecialchars($inc['student_name']) ?>
                          <?php if ($inc['student_reg']): ?>
                            · <span class="font-mono"><?= htmlspecialchars($inc['student_reg']) ?></span>
                          <?php endif; ?>
                        </div>
                      <?php else: ?>
                        <div class="text-xs text-muted">Student not specified</div>
                      <?php endif; ?>
                    </div>
                  </div>

                  <div class="dispute-session-info">
                    <span class="badge <?= $typeMeta['class'] ?>">
                      <?= $typeMeta['icon'] ?> <?= $typeMeta['label'] ?>
                    </span>
                  </div>

                  <div class="dispute-status-wrap">
                    <?php
                      $sBadge = match($inc['status']) {
                          'open'     => 'badge-warning',
                          'reviewed' => 'badge-info',
                          'closed'   => 'badge-success',
                          default    => 'badge-neutral',
                      };
                    ?>
                    <span class="badge <?= $sBadge ?>" id="inc-status-<?= $inc['id'] ?>">
                      <?= ucfirst($inc['status']) ?>
                    </span>
                  </div>
                </div>

                <div class="dispute-reason">
                  <div class="dispute-reason-label">Description</div>
                  <p class="dispute-reason-text"><?= htmlspecialchars($inc['description']) ?></p>
                </div>

                <?php if ($inc['reviewer_name']): ?>
                  <div class="dispute-reviewer-note">
                    <div class="dispute-reason-label">
                      Reviewed by <?= htmlspecialchars($inc['reviewer_name']) ?>
                      <?php if ($inc['reviewed_at']): ?>
                        · <span class="text-muted"><?= date('d M Y, H:i', strtotime($inc['reviewed_at'])) ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endif; ?>

                <div class="dispute-meta text-xs text-muted">
                  Reported <?= date('d M Y, H:i', strtotime($inc['created_at'])) ?>
                </div>

                <?php if ($inc['status'] !== 'closed'): ?>
                  <div class="dispute-bottom-row" style="justify-content:flex-end">
                    <div style="display:flex;gap:var(--space-2)">
                      <?php if ($inc['status'] === 'open'): ?>
                        <button class="btn btn-sm btn-secondary"
                                onclick="updateIncident(<?= $inc['id'] ?>, 'reviewed')">
                          Mark Reviewed
                        </button>
                      <?php endif; ?>
                      <button class="btn btn-sm btn-primary"
                              onclick="updateIncident(<?= $inc['id'] ?>, 'closed')">
                        Close
                      </button>
                    </div>
                  </div>
                <?php endif; ?>

              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

      <?php else: /* absences tab */ ?>

        <!-- Absence status filter -->
        <div class="dispute-tabs animate-fade-in" style="margin-bottom:var(--space-6)">
          <?php
            $absMeta = [
              'pending'      => ['label' => 'Pending',      'icon' => '⏳'],
              'acknowledged' => ['label' => 'Acknowledged', 'icon' => '✅'],
              'all'          => ['label' => 'All',          'icon' => '📋'],
            ];
            foreach ($absMeta as $st => $meta):
          ?>
            <button type="button"
                    class="dispute-tab <?= $absenceFilter === $st ? 'active' : '' ?>"
                    style="font-size:var(--text-sm)"
                    onclick="location.href='?tab=absences&amp;status=<?= $st ?>'">
              <span><?= $meta['icon'] ?></span>
              <span><?= $meta['label'] ?></span>
              <?php if ($absenceCounts[$st] > 0): ?>
                <span class="dispute-tab-count <?= $st === 'pending' ? 'count-pending' : 'count-neutral' ?>">
                  <?= $absenceCounts[$st] ?>
                </span>
              <?php endif; ?>
            </button>
          <?php endforeach; ?>
        </div>

        <?php if (empty($absences)): ?>
          <div class="empty-state animate-fade-in">
            <span class="empty-icon">📅</span>
            <p class="empty-title">No absence reports found</p>
            <p class="empty-text">
              Absence reports submitted via the parent AI chat will appear here.
            </p>
          </div>
        <?php else: ?>
          <div class="disputes-list animate-fade-in">
            <?php foreach ($absences as $ab): ?>
              <div class="dispute-card <?= $ab['status'] === 'pending' ? 'dispute-pending' : '' ?>"
                   id="absence-<?= $ab['id'] ?>">

                <div class="dispute-card-header">
                  <div class="dispute-student-info">
                    <div class="dispute-avatar">
                      <?= strtoupper(substr($ab['student_name'], 0, 1)) ?>
                    </div>
                    <div>
                      <div class="dispute-student-name">
                        <?= htmlspecialchars($ab['student_name']) ?>
                      </div>
                      <div class="text-xs text-muted font-mono">
                        <?= htmlspecialchars($ab['student_reg']) ?>
                      </div>
                    </div>
                  </div>

                  <div class="dispute-session-info">
                    <span class="badge badge-info">
                      📅 <?= date('D d M Y', strtotime($ab['report_date'])) ?>
                    </span>
                  </div>

                  <div class="dispute-status-wrap">
                    <?php $abBadge = $ab['status'] === 'acknowledged' ? 'badge-success' : 'badge-warning'; ?>
                    <span class="badge <?= $abBadge ?>" id="abs-status-<?= $ab['id'] ?>">
                      <?= ucfirst($ab['status']) ?>
                    </span>
                  </div>
                </div>

                <div class="dispute-reason">
                  <div class="dispute-reason-label">Reason</div>
                  <p class="dispute-reason-text"><?= htmlspecialchars($ab['reason']) ?></p>
                </div>

                <?php if (!empty($ab['proof_description'])): ?>
                  <div class="dispute-reviewer-note">
                    <div class="dispute-reason-label">🗂️ Proof / Evidence</div>
                    <p class="text-sm"><?= htmlspecialchars($ab['proof_description']) ?></p>
                  </div>
                <?php else: ?>
                  <div class="dispute-reviewer-note" style="border-left-color:var(--color-warning)">
                    <div class="dispute-reason-label" style="color:var(--color-warning)">⚠️ No proof provided</div>
                  </div>
                <?php endif; ?>

                <?php if ($ab['notes']): ?>
                  <div class="dispute-reviewer-note">
                    <div class="dispute-reason-label">Additional notes</div>
                    <p class="text-sm"><?= htmlspecialchars($ab['notes']) ?></p>
                  </div>
                <?php endif; ?>

                <div class="dispute-meta text-xs text-muted">
                  Reported by <?= htmlspecialchars($ab['parent_name']) ?>
                  · <?= date('d M Y, H:i', strtotime($ab['created_at'])) ?>
                </div>

                <?php if ($ab['status'] === 'pending'): ?>
                  <div class="dispute-bottom-row" style="justify-content:flex-end">
                    <button class="btn btn-sm btn-primary"
                            onclick="acknowledgeAbsence(<?= $ab['id'] ?>)">
                      Acknowledge
                    </button>
                  </div>
                <?php endif; ?>

              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

      <?php endif; ?>

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
<script>
const BASE_URL = <?= json_encode(BASE_URL) ?>;

async function updateIncident(id, status) {
  const label = status === 'closed' ? 'close' : 'mark as reviewed';
  if (!confirm(`${label.charAt(0).toUpperCase() + label.slice(1)} this incident?`)) return;

  try {
    await Api.post(`${BASE_URL}/api/admin/incident_update.php`, { id, status });

    const card   = document.getElementById(`incident-${id}`);
    const badge  = document.getElementById(`inc-status-${id}`);
    const row    = card?.querySelector('.dispute-bottom-row');

    if (badge) {
      badge.textContent = status.charAt(0).toUpperCase() + status.slice(1);
      badge.className   = `badge ${status === 'closed' ? 'badge-success' : 'badge-info'}`;
    }
    if (card)  card.classList.remove('dispute-pending');
    if (row)   row.remove();

    Toast.show('success', `Incident ${status}.`);
  } catch (err) {
    Api.showError(err);
  }
}

async function acknowledgeAbsence(id) {
  try {
    await Api.post(`${BASE_URL}/api/admin/absence_acknowledge.php`, { id });

    const card  = document.getElementById(`absence-${id}`);
    const badge = document.getElementById(`abs-status-${id}`);
    const row   = card?.querySelector('.dispute-bottom-row');

    if (badge) {
      badge.textContent = 'Acknowledged';
      badge.className   = 'badge badge-success';
    }
    if (card)  card.classList.remove('dispute-pending');
    if (row)   row.remove();

    Toast.show('success', 'Absence report acknowledged.');
  } catch (err) {
    Api.showError(err);
  }
}
</script>
</body>
</html>
