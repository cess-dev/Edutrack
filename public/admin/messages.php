<?php
/**
 * EduTrack — Admin: Parent Messages Inbox
 *
 * Shows all messages sent from parents to the school administration,
 * ordered by urgency (1 = highest) then date descending.
 * Admins can mark messages as read.
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

Auth::startSession();
Auth::requireRole('admin');

$user = Auth::user();

// ── Filters ───────────────────────────────────────────────────────────────────
$validStatus   = ['all', 'unread', 'read'];
$validUrgency  = ['all', '1', '2', '3', '4', '5'];

$filterStatus  = in_array($_GET['status']  ?? '', $validStatus,  true) ? $_GET['status']  : 'all';
$filterUrgency = in_array($_GET['urgency'] ?? '', $validUrgency, true) ? $_GET['urgency'] : 'all';

$where  = [];
$params = [];

if ($filterStatus !== 'all') {
    $where[]  = 'm.status = ?';
    $params[] = $filterStatus;
}
if ($filterUrgency !== 'all') {
    $where[]  = 'm.urgency = ?';
    $params[] = (int)$filterUrgency;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$messages = DB::rows(
    "SELECT m.id, m.subject, m.body, m.urgency, m.status,
            m.created_at, m.read_at,
            p.full_name  AS parent_name,
            p.email      AS parent_email,
            r.full_name  AS read_by_name
     FROM parent_messages m
     JOIN users p ON p.id = m.parent_id
     LEFT JOIN users r ON r.id = m.read_by
     {$whereClause}
     ORDER BY COALESCE(m.urgency, 6) ASC, m.created_at DESC",
    $params
);

$unrankedCount = (int)(DB::row(
    "SELECT COUNT(*) AS cnt FROM parent_messages WHERE urgency IS NULL"
)['cnt'] ?? 0);

// ── Counts for tabs ───────────────────────────────────────────────────────────
$counts = [];
foreach (['all', 'unread', 'read'] as $st) {
    $w = $st === 'all' ? '' : 'WHERE status = ?';
    $p = $st === 'all' ? [] : [$st];
    $counts[$st] = (int)(DB::row("SELECT COUNT(*) AS cnt FROM parent_messages {$w}", $p)['cnt'] ?? 0);
}
$unreadCount = $counts['unread'];

$csrfToken = Auth::csrfToken();
$pageTitle = 'Parent Messages';

$urgencyMeta = [
    1 => ['label' => 'Critical',       'class' => 'badge-danger'],
    2 => ['label' => 'Illness',        'class' => 'badge-warning'],
    3 => ['label' => 'Misconduct',     'class' => 'badge-info'],
    4 => ['label' => 'General query',  'class' => 'badge-neutral'],
    5 => ['label' => 'No reply needed','class' => 'badge-neutral'],
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
      <span class="topbar-title">Parent Messages</span>
      <div class="topbar-actions">
        <?php if ($unreadCount > 0): ?>
          <span class="badge badge-danger"><?= $unreadCount ?> unread</span>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/admin/autoreplies" class="btn btn-primary btn-sm">
          Manage Autoreplies
        </a>
      </div>
    </header>

    <div class="page-content">

      <div class="admin-welcome animate-fade-in">
        <div>
          <h1 class="welcome-name">Parent Messages</h1>
          <p class="text-muted text-sm">
            Messages are ranked by AI priority (1 = highest urgency) then by date.
          </p>
        </div>
        <?php if ($unrankedCount > 0): ?>
          <div id="ranking-status" class="badge badge-warning" style="align-self:flex-start">
            Ranking <?= $unrankedCount ?> message<?= $unrankedCount !== 1 ? 's' : '' ?>…
          </div>
        <?php endif; ?>
      </div>

      <!-- Status filter tabs -->
      <div class="dispute-tabs animate-fade-in">
        <?php foreach (['all' => 'All', 'unread' => 'Unread', 'read' => 'Read'] as $st => $label): ?>
          <a href="?status=<?= $st ?>&urgency=<?= urlencode($filterUrgency) ?>"
             class="dispute-tab <?= $filterStatus === $st ? 'active' : '' ?>">
            <?= $label ?>
            <?php if ($counts[$st] > 0): ?>
              <span class="dispute-tab-count <?= $st === 'unread' ? 'count-pending' : 'count-neutral' ?>">
                <?= $counts[$st] ?>
              </span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>

        <span style="flex:1"></span>

        <!-- Priority filter -->
        <div style="display:flex;align-items:center;gap:var(--space-2)">
          <span class="text-xs text-muted">Priority:</span>
          <?php foreach (['all' => 'All', '1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5'] as $u => $lbl): ?>
            <a href="?status=<?= urlencode($filterStatus) ?>&urgency=<?= $u ?>"
               class="priority-filter-btn <?= $filterUrgency === (string)$u ? 'active' : '' ?>"
               <?php if ($u !== 'all'): ?>
                 style="<?= $u === $filterUrgency ? '' : '' ?>"
               <?php endif; ?>>
              <?= $lbl ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if (empty($messages)): ?>
        <div class="empty-state animate-fade-in">
          <p class="empty-title">No messages found</p>
          <p class="empty-text">
            <?= $filterStatus === 'unread' ? 'All messages have been read.' : 'No messages match the selected filters.' ?>
          </p>
        </div>

      <?php else: ?>
        <div class="msg-list animate-fade-in">
          <?php foreach ($messages as $m):
            $ranked = $m['urgency'] !== null;
            $meta   = $ranked ? ($urgencyMeta[$m['urgency']] ?? $urgencyMeta[5]) : null;
            $isNew  = $m['status'] === 'unread';
          ?>
            <div class="msg-row <?= $isNew ? 'msg-unread' : '' ?>" id="msg-<?= $m['id'] ?>">

              <!-- Priority indicator + sender -->
              <div class="msg-row-left">
                <span class="msg-priority-bar urgency-<?= $m['urgency'] ?? 'pending' ?>"
                      id="bar-<?= $m['id'] ?>"></span>
                <div class="msg-sender">
                  <div class="msg-sender-name <?= $isNew ? 'font-semibold' : '' ?>">
                    <?= htmlspecialchars($m['parent_name']) ?>
                  </div>
                  <div class="text-xs text-muted"><?= htmlspecialchars($m['parent_email'] ?? '') ?></div>
                </div>
              </div>

              <!-- Subject + preview -->
              <div class="msg-center" onclick="toggleMessage(<?= $m['id'] ?>)">
                <div class="msg-subject <?= $isNew ? 'font-semibold' : '' ?>">
                  <?= htmlspecialchars($m['subject']) ?>
                </div>
                <div class="msg-preview text-muted">
                  <?= htmlspecialchars(mb_substr($m['body'], 0, 100)) . (mb_strlen($m['body']) > 100 ? '…' : '') ?>
                </div>
              </div>

              <!-- Priority badge + date -->
              <div class="msg-row-right">
                <?php if ($ranked): ?>
                  <span class="badge <?= $meta['class'] ?>" id="badge-<?= $m['id'] ?>">
                    <?= $m['urgency'] ?> — <?= $meta['label'] ?>
                  </span>
                <?php else: ?>
                  <span class="badge badge-neutral" id="badge-<?= $m['id'] ?>">Ranking…</span>
                <?php endif; ?>
                <div class="text-xs text-muted" style="margin-top:var(--space-1);white-space:nowrap">
                  <?= date('d M Y', strtotime($m['created_at'])) ?>
                </div>
                <div class="text-xs text-muted" style="white-space:nowrap">
                  <?= date('H:i', strtotime($m['created_at'])) ?>
                </div>
              </div>

            </div>

            <!-- Expanded message body -->
            <div class="msg-body-expanded" id="msg-body-<?= $m['id'] ?>" hidden>
              <div class="msg-body-text">
                <?= nl2br(htmlspecialchars($m['body'])) ?>
              </div>
              <div class="msg-body-footer">
                <div class="text-xs text-muted">
                  Sent <?= date('d M Y \a\t H:i', strtotime($m['created_at'])) ?>
                  <?php if ($m['read_at']): ?>
                    · Read <?= date('d M Y', strtotime($m['read_at'])) ?>
                    <?php if ($m['read_by_name']): ?>
                      by <?= htmlspecialchars($m['read_by_name']) ?>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
                <?php if ($isNew): ?>
                  <button class="btn btn-sm btn-primary"
                          onclick="markRead(<?= $m['id'] ?>)">
                    Mark as Read
                  </button>
                <?php else: ?>
                  <span class="badge badge-success">Read</span>
                <?php endif; ?>
              </div>
            </div>

          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
<script>
const BASE_URL = <?= json_encode(BASE_URL) ?>;

function toggleMessage(id) {
  const body = document.getElementById(`msg-body-${id}`);
  if (!body) return;
  body.hidden = !body.hidden;
  if (!body.hidden) {
    const row = document.getElementById(`msg-${id}`);
    if (row?.classList.contains('msg-unread')) markRead(id, true);
  }
}

async function markRead(id, silent = false) {
  try {
    await Api.post(`${BASE_URL}/api/admin/message_update.php`, { id });

    const row  = document.getElementById(`msg-${id}`);
    const body = document.getElementById(`msg-body-${id}`);

    row?.classList.remove('msg-unread');
    row?.querySelector('.msg-sender-name')?.classList.remove('font-semibold');
    row?.querySelector('.msg-subject')?.classList.remove('font-semibold');

    // Swap button for badge in expanded body
    const btn = body?.querySelector('button');
    if (btn) {
      const badge = document.createElement('span');
      badge.className   = 'badge badge-success';
      badge.textContent = 'Read';
      btn.replaceWith(badge);
    }

    if (!silent) Toast.show('success', 'Message marked as read.');
  } catch (err) {
    if (!silent) Api.showError(err);
  }
}

// ── Auto-rank unranked messages after Api.init() has set the CSRF token ────────
const URGENCY_LABELS = {
  1: { label: '1 — Critical',        cls: 'badge-danger'  },
  2: { label: '2 — Illness',         cls: 'badge-warning' },
  3: { label: '3 — Misconduct',      cls: 'badge-info'    },
  4: { label: '4 — General query',   cls: 'badge-neutral' },
  5: { label: '5 — No reply needed', cls: 'badge-neutral' },
};

const URGENCY_BAR_CLASSES = {
  1: 'urgency-1', 2: 'urgency-2', 3: 'urgency-3',
  4: 'urgency-4', 5: 'urgency-5',
};

document.addEventListener('DOMContentLoaded', async function autoRank() {
  const statusEl = document.getElementById('ranking-status');
  if (!statusEl) return; // no unranked messages — nothing to do

  try {
    const data = await Api.post(`${BASE_URL}/api/admin/rank_messages.php`, {});

    if (data.count === 0) {
      statusEl.remove();
      return;
    }

    // Update each ranked badge and priority bar in place without a reload
    for (const [id, urgency] of Object.entries(data.ranked)) {
      const meta  = URGENCY_LABELS[urgency];
      const badge = document.getElementById(`badge-${id}`);
      const bar   = document.getElementById(`bar-${id}`);

      if (badge && meta) {
        badge.textContent = meta.label;
        badge.className   = `badge ${meta.cls}`;
      }
      if (bar) {
        bar.className = `msg-priority-bar ${URGENCY_BAR_CLASSES[urgency] ?? 'urgency-5'}`;
      }
    }

    statusEl.textContent = `${data.count} message${data.count !== 1 ? 's' : ''} ranked`;
    statusEl.className   = 'badge badge-success';
    setTimeout(() => statusEl.remove(), 3000);

  } catch (err) {
    if (statusEl) {
      statusEl.textContent = 'Ranking unavailable — will retry on next visit';
      statusEl.className   = 'badge badge-neutral';
    }
  }
});
</script>

<style>
/* ── Message list ──────────────────────────────────────────────────────────── */
.msg-list {
  display: flex;
  flex-direction: column;
  border: 1px solid var(--color-border-light);
  border-radius: var(--radius-lg);
  overflow: hidden;
  background: var(--color-bg-card);
  box-shadow: var(--shadow-sm);
}

.msg-row {
  display: flex;
  align-items: center;
  gap: var(--space-4);
  padding: var(--space-4) var(--space-5);
  border-bottom: 1px solid var(--color-border-light);
  transition: background var(--transition-fast);
  cursor: pointer;
}

.msg-row:last-of-type { border-bottom: none; }
.msg-row:hover        { background: var(--color-bg-subtle); }
.msg-unread           { background: #fefefe; }

.msg-row-left {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  flex-shrink: 0;
  width: 180px;
}

.msg-priority-bar {
  width: 4px;
  height: 36px;
  border-radius: 2px;
  flex-shrink: 0;
}

.urgency-1       { background: var(--color-error); }
.urgency-2       { background: var(--color-amber); }
.urgency-3       { background: var(--color-accent); }
.urgency-4       { background: var(--color-border); }
.urgency-5       { background: var(--color-bg-inset); }
.urgency-pending { background: repeating-linear-gradient(
    45deg,
    var(--color-border-light),
    var(--color-border-light) 3px,
    var(--color-bg-subtle) 3px,
    var(--color-bg-subtle) 6px
  ); }

.msg-sender { overflow: hidden; }
.msg-sender-name {
  font-size: var(--text-sm);
  color: var(--color-text);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.msg-center {
  flex: 1;
  overflow: hidden;
  min-width: 0;
}

.msg-subject {
  font-size: var(--text-sm);
  color: var(--color-text);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.msg-preview {
  font-size: var(--text-xs);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-top: 2px;
}

.msg-row-right {
  flex-shrink: 0;
  text-align: right;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 2px;
}

/* Expanded body */
.msg-body-expanded {
  padding: var(--space-5) var(--space-5) var(--space-5) calc(var(--space-5) + 4px + var(--space-3));
  background: var(--color-bg-subtle);
  border-bottom: 1px solid var(--color-border-light);
}

.msg-body-text {
  font-size: var(--text-sm);
  color: var(--color-text);
  line-height: var(--leading-relaxed);
  white-space: pre-wrap;
  margin-bottom: var(--space-4);
  max-width: 640px;
}

.msg-body-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-4);
  flex-wrap: wrap;
}

/* Priority filter pills */
.priority-filter-btn {
  padding: 3px 10px;
  border-radius: var(--radius-full);
  border: 1.5px solid var(--color-border);
  font-size: var(--text-xs);
  font-weight: var(--weight-medium);
  color: var(--color-text-secondary);
  text-decoration: none;
  transition: all var(--transition-fast);
  white-space: nowrap;
}

.priority-filter-btn:hover,
.priority-filter-btn.active {
  border-color: var(--color-accent);
  color: var(--color-accent);
  text-decoration: none;
}

@media (max-width: 768px) {
  .msg-row-left { width: 140px; }
  .msg-row-right .badge { display: none; }
}

@media (max-width: 640px) {
  .msg-row       { flex-wrap: wrap; gap: var(--space-2); }
  .msg-row-left  { width: 100%; }
  .msg-center    { width: 100%; }
  .msg-row-right { width: 100%; flex-direction: row; justify-content: space-between; }
}
</style>

</body>
</html>
