<?php
/**
 * EduTrack — Admin Autoreply Templates
 *
 * Admins define reply templates for common parent queries.
 * The AI matches score-4 (General query) messages against these
 * templates and automatically sends the matching reply to the parent.
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

Auth::startSession();
Auth::requireRole('admin');

$user = Auth::user();

$templates = DB::rows(
    "SELECT t.id, t.title, t.description, t.reply_body, t.is_active,
            t.created_at, t.updated_at, u.full_name AS created_by_name,
            COUNT(r.id) AS times_used
     FROM autoreply_templates t
     JOIN users u ON u.id = t.created_by
     LEFT JOIN parent_message_replies r ON r.template_id = t.id
     GROUP BY t.id
     ORDER BY t.id ASC",
    []
);

$csrfToken = Auth::csrfToken();
$pageTitle = 'Autoreply Templates';
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
      <span class="topbar-title">Autoreply Templates</span>
      <div class="topbar-actions">
        <button class="btn btn-primary btn-sm" onclick="openNewModal()">
          New Template
        </button>
      </div>
    </header>

    <div class="page-content">

      <div class="admin-welcome animate-fade-in">
        <div>
          <h1 class="welcome-name">Autoreply Templates</h1>
          <p class="text-muted text-sm">
            When the AI ranks a parent message as a General Query (priority 4)
            and it matches a template topic, the reply is sent to the parent automatically.
          </p>
        </div>
        <a href="<?= BASE_URL ?>/admin/messages" class="btn btn-secondary btn-sm">
          Back to Messages
        </a>
      </div>

      <?php if (empty($templates)): ?>
        <div class="empty-state animate-fade-in">
          <p class="empty-title">No templates yet</p>
          <p class="empty-text">
            Create a template for common questions like open dates, fee structure,
            or uniform policy. The AI will match and reply automatically.
          </p>
          <button class="btn btn-primary btn-sm" onclick="openNewModal()">
            Create First Template
          </button>
        </div>

      <?php else: ?>
        <div class="disputes-list animate-fade-in">
          <?php foreach ($templates as $t): ?>
            <div class="dispute-card" id="tpl-<?= $t['id'] ?>">

              <div class="dispute-card-header">
                <div style="flex:1">
                  <div class="dispute-student-name">
                    <?= htmlspecialchars($t['title']) ?>
                  </div>
                  <div class="text-xs text-muted" style="margin-top:var(--space-1)">
                    <?= htmlspecialchars($t['description']) ?>
                  </div>
                </div>

                <div class="dispute-session-info">
                  <span class="badge badge-neutral text-xs">
                    Used <?= $t['times_used'] ?> time<?= $t['times_used'] != 1 ? 's' : '' ?>
                  </span>
                </div>

                <div class="dispute-status-wrap">
                  <span class="badge <?= $t['is_active'] ? 'badge-success' : 'badge-neutral' ?>"
                        id="status-badge-<?= $t['id'] ?>">
                    <?= $t['is_active'] ? 'Active' : 'Inactive' ?>
                  </span>
                </div>
              </div>

              <div class="dispute-reason">
                <div class="dispute-reason-label">Reply text sent to parent</div>
                <p class="dispute-reason-text"><?= nl2br(htmlspecialchars($t['reply_body'])) ?></p>
              </div>

              <div class="dispute-meta text-xs text-muted">
                Created by <?= htmlspecialchars($t['created_by_name']) ?>
                on <?= date('d M Y', strtotime($t['created_at'])) ?>
                <?php if ($t['updated_at'] !== $t['created_at']): ?>
                  · Updated <?= date('d M Y', strtotime($t['updated_at'])) ?>
                <?php endif; ?>
              </div>

              <div class="dispute-bottom-row">
                <div style="display:flex;gap:var(--space-2)">
                  <button class="btn btn-sm btn-secondary"
                          onclick="toggleActive(<?= $t['id'] ?>, <?= $t['is_active'] ? 0 : 1 ?>)">
                    <?= $t['is_active'] ? 'Deactivate' : 'Activate' ?>
                  </button>
                  <button class="btn btn-sm btn-outline"
                          onclick="openEditModal(
                            <?= $t['id'] ?>,
                            <?= htmlspecialchars(json_encode($t['title'])) ?>,
                            <?= htmlspecialchars(json_encode($t['description'])) ?>,
                            <?= htmlspecialchars(json_encode($t['reply_body'])) ?>
                          )">
                    Edit
                  </button>
                </div>
                <button class="btn btn-sm btn-danger"
                        onclick="deleteTemplate(<?= $t['id'] ?>, '<?= htmlspecialchars($t['title'], ENT_QUOTES) ?>')">
                  Delete
                </button>
              </div>

            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->


<!-- ── New / Edit Template Modal ─────────────────────────────────────────── -->
<div class="modal-backdrop" id="tpl-modal" hidden>
  <div class="modal" style="max-width:560px">
    <div class="modal-header">
      <h2 class="modal-title" id="modal-title">New Autoreply Template</h2>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body">
      <div data-error-container class="alert alert-error"></div>
      <input type="hidden" id="tpl-id">

      <div class="form-group">
        <label class="form-label">Template Title <span class="required">*</span></label>
        <input type="text" id="tpl-title" class="form-control"
               placeholder="e.g. Closing Dates" maxlength="150">
        <div class="form-hint">Short name shown in the admin list.</div>
      </div>

      <div class="form-group">
        <label class="form-label">What does this answer? <span class="required">*</span></label>
        <input type="text" id="tpl-description" class="form-control"
               placeholder="e.g. Questions about end-of-term dates and school holidays"
               maxlength="300">
        <div class="form-hint">
          The AI uses this description to decide whether an incoming message matches.
          Be specific — the more precise, the better the match.
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Reply text <span class="required">*</span></label>
        <textarea id="tpl-body" class="form-control" rows="6"
                  placeholder="Type the reply that will be sent to the parent automatically…"
                  maxlength="2000"></textarea>
        <div class="form-hint">
          This is the exact message the parent will see in their sent-messages history.
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
      <button class="btn btn-primary" id="save-btn" onclick="saveTemplate()">
        Save Template
      </button>
    </div>
  </div>
</div>


<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
<script>
const BASE_URL = <?= json_encode(BASE_URL) ?>;

function openNewModal() {
  document.getElementById('tpl-id').value          = '';
  document.getElementById('tpl-title').value       = '';
  document.getElementById('tpl-description').value = '';
  document.getElementById('tpl-body').value        = '';
  document.getElementById('modal-title').textContent = 'New Autoreply Template';
  document.querySelector('[data-error-container]').textContent = '';
  document.getElementById('tpl-modal').hidden = false;
  document.body.style.overflow = 'hidden';
}

function openEditModal(id, title, description, body) {
  document.getElementById('tpl-id').value          = id;
  document.getElementById('tpl-title').value       = title;
  document.getElementById('tpl-description').value = description;
  document.getElementById('tpl-body').value        = body;
  document.getElementById('modal-title').textContent = 'Edit Template';
  document.querySelector('[data-error-container]').textContent = '';
  document.getElementById('tpl-modal').hidden = false;
  document.body.style.overflow = 'hidden';
}

function closeModal() {
  document.getElementById('tpl-modal').hidden = true;
  document.body.style.overflow = '';
}

async function saveTemplate() {
  const id          = document.getElementById('tpl-id').value;
  const title       = document.getElementById('tpl-title').value.trim();
  const description = document.getElementById('tpl-description').value.trim();
  const reply_body  = document.getElementById('tpl-body').value.trim();
  const btn         = document.getElementById('save-btn');
  const errEl       = document.querySelector('[data-error-container]');

  errEl.textContent = '';

  if (!title || !description || !reply_body) {
    errEl.textContent = 'All fields are required.';
    return;
  }

  try {
    await Api.withLoading(btn, async () => {
      await Api.post(`${BASE_URL}/api/admin/autoreply_save.php`, {
        id: id ? parseInt(id) : null,
        title, description, reply_body,
      });
      closeModal();
      location.reload();
    });
  } catch (err) {
    errEl.textContent = err.message || 'Failed to save template.';
  }
}

async function toggleActive(id, newState) {
  try {
    await Api.post(`${BASE_URL}/api/admin/autoreply_save.php`, {
      id, is_active: newState,
    });
    const badge = document.getElementById(`status-badge-${id}`);
    const btn   = document.querySelector(`#tpl-${id} .dispute-bottom-row button`);
    if (newState === 1) {
      if (badge) { badge.textContent = 'Active';   badge.className = 'badge badge-success'; }
      if (btn)   btn.textContent = 'Deactivate';
      btn?.setAttribute('onclick', `toggleActive(${id}, 0)`);
    } else {
      if (badge) { badge.textContent = 'Inactive'; badge.className = 'badge badge-neutral'; }
      if (btn)   btn.textContent = 'Activate';
      btn?.setAttribute('onclick', `toggleActive(${id}, 1)`);
    }
    Toast.show('success', newState ? 'Template activated.' : 'Template deactivated.');
  } catch (err) {
    Api.showError(err);
  }
}

async function deleteTemplate(id, title) {
  if (!confirm(`Delete the template "${title}"?\n\nThis cannot be undone.`)) return;
  try {
    await Api.post(`${BASE_URL}/api/admin/autoreply_delete.php`, { id });
    document.getElementById(`tpl-${id}`)?.remove();
    Toast.show('success', 'Template deleted.');
  } catch (err) {
    Api.showError(err);
  }
}
</script>

<style>
.modal-backdrop { z-index: 9999; }
</style>

</body>
</html>
