<?php
/**
 * EduTrack — Parent Contact School Page
 *
 * Lets parents compose and send a message to the school administration.
 * Messages are stored in parent_messages and visible to admins with
 * urgency-level prioritisation.
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/UserModel.php';

Auth::startSession();
Auth::requireRole('parent');

$user     = Auth::user();
$children = UserModel::getLinkedStudents($user['id']);

$schoolName  = DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'school_name'"
)['setting_value'] ?? SCHOOL_NAME;

$schoolEmail = DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'school_email'"
)['setting_value'] ?? (defined('SCHOOL_EMAIL') ? SCHOOL_EMAIL : '');

$sentMessages = DB::rows(
    "SELECT m.id, m.subject, m.status, m.created_at,
            r.reply_body, r.created_at AS replied_at
     FROM parent_messages m
     LEFT JOIN parent_message_replies r ON r.message_id = m.id
     WHERE m.parent_id = ?
     ORDER BY m.created_at DESC",
    [$user['id']]
);

$csrfToken = Auth::csrfToken();
$pageTitle = 'Contact School';
?>
<!DOCTYPE html>
<html lang="en" data-base-url="<?= htmlspecialchars(BASE_URL) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
  <title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/base.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/parent.css">
</head>
<body>
<div class="layout">

  <?php include __DIR__ . '/../partials/sidebar_parent.php'; ?>

  <div class="main">
    <header class="topbar">
      <span class="topbar-title">Contact School</span>
    </header>

    <div class="page-content">

      <div class="page-title animate-fade-in">
        <div>
          Contact <?= htmlspecialchars($schoolName) ?>
          <div class="text-sm text-muted" style="font-weight:var(--weight-regular);margin-top:var(--space-1)">
            Your message will be reviewed by the administration.
            <?php if ($schoolEmail): ?>
              You can also reach us at <a href="mailto:<?= htmlspecialchars($schoolEmail) ?>"><?= htmlspecialchars($schoolEmail) ?></a>.
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Compose form -->
      <div class="card animate-fade-in compose-card" style="animation-delay:0.05s">

        <div class="compose-header">
          New Message
        </div>

        <div id="send-success" class="alert alert-success" style="display:none">
          <div>Your message has been sent. The school administration will review it shortly.</div>
        </div>
        <div id="send-error" class="alert alert-error" style="display:none"></div>

        <!-- To field -->
        <div class="compose-field">
          <span class="compose-label">To</span>
          <span class="compose-to-value"><?= htmlspecialchars($schoolName) ?> Administration</span>
        </div>

        <div class="compose-divider"></div>

        <!-- From field -->
        <div class="compose-field">
          <span class="compose-label">From</span>
          <span class="compose-to-value"><?= htmlspecialchars($user['full_name']) ?></span>
        </div>

        <div class="compose-divider"></div>

        <!-- Subject field -->
        <div class="compose-field">
          <span class="compose-label">Subject</span>
          <input type="text"
                 id="msg-subject"
                 class="compose-input"
                 placeholder="Enter subject…"
                 maxlength="200"
                 autocomplete="off">
        </div>

        <div class="compose-divider"></div>

        <!-- Body -->
        <textarea id="msg-body"
                  class="compose-body"
                  placeholder="Write your message here…"
                  maxlength="5000"></textarea>

        <!-- Footer -->
        <div class="compose-footer">
          <button id="send-btn" class="btn btn-primary" onclick="sendMessage()">
            Send Message
          </button>
        </div>

      </div>

      <!-- Sent message history — always visible -->
      <div class="card animate-fade-in" style="animation-delay:0.1s">
        <div class="card-header">
          <div>
            <div class="card-title">Sent Messages</div>
            <div class="card-subtitle">
              <?= count($sentMessages) ?> message<?= count($sentMessages) !== 1 ? 's' : '' ?> sent
            </div>
          </div>
        </div>

        <?php if (empty($sentMessages)): ?>
          <div class="empty-state" style="padding:var(--space-8) 0">
            <p class="empty-title">No messages sent yet</p>
            <p class="empty-text">Messages you send will appear here with their review status.</p>
          </div>
        <?php else: ?>
          <div class="sent-msg-list">
            <?php foreach ($sentMessages as $m): ?>
              <div class="sent-msg-item <?= $m['reply_body'] ? 'has-reply' : '' ?>">

                <div class="sent-msg-header">
                  <div class="sent-msg-subject"><?= htmlspecialchars($m['subject']) ?></div>
                  <div class="sent-msg-meta">
                    <?= date('d M Y, H:i', strtotime($m['created_at'])) ?>
                  </div>
                  <div>
                    <?php if ($m['reply_body']): ?>
                      <span class="badge badge-success">Replied</span>
                    <?php elseif ($m['status'] === 'read'): ?>
                      <span class="badge badge-info">Reviewed</span>
                    <?php else: ?>
                      <span class="badge badge-neutral">Pending review</span>
                    <?php endif; ?>
                  </div>
                </div>

                <?php if ($m['reply_body']): ?>
                  <div class="sent-msg-reply">
                    <div class="sent-msg-reply-label">
                      Reply from school
                      <span class="text-muted" style="font-weight:var(--weight-regular)">
                        · <?= date('d M Y', strtotime($m['replied_at'])) ?>
                      </span>
                    </div>
                    <div class="sent-msg-reply-body">
                      <?= nl2br(htmlspecialchars($m['reply_body'])) ?>
                    </div>
                  </div>
                <?php endif; ?>

              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
<script>
const BASE_URL = <?= json_encode(BASE_URL) ?>;

async function sendMessage() {
  const subject = document.getElementById('msg-subject').value.trim();
  const body    = document.getElementById('msg-body').value.trim();
  const btn     = document.getElementById('send-btn');
  const errEl   = document.getElementById('send-error');
  const okEl    = document.getElementById('send-success');

  errEl.style.display = 'none';
  okEl.style.display  = 'none';

  if (!subject) {
    errEl.textContent   = 'Please enter a subject.';
    errEl.style.display = 'flex';
    document.getElementById('msg-subject').focus();
    return;
  }
  if (!body) {
    errEl.textContent   = 'Please write a message before sending.';
    errEl.style.display = 'flex';
    document.getElementById('msg-body').focus();
    return;
  }

  try {
    await Api.withLoading(btn, async () => {
      await Api.post(`${BASE_URL}/api/parent/send_message.php`, { subject, body });
      okEl.style.display = 'flex';
      document.getElementById('msg-subject').value = '';
      document.getElementById('msg-body').value    = '';
      setTimeout(() => location.reload(), 1800);
    });
  } catch (err) {
    errEl.textContent   = err.message || 'Failed to send. Please try again.';
    errEl.style.display = 'flex';
  }
}
</script>

<style>
/* ── Compose card ─────────────────────────────────────────────────────────── */
.compose-card {
  padding: 0;
  overflow: hidden;
  max-width: 680px;
  margin-bottom: var(--space-6);
}

.compose-header {
  padding: var(--space-4) var(--space-6);
  font-size: var(--text-base);
  font-weight: var(--weight-semibold);
  color: var(--color-primary);
  border-bottom: 1px solid var(--color-border-light);
  background: var(--color-bg-subtle);
}

.compose-field {
  display: flex;
  align-items: center;
  padding: var(--space-3) var(--space-6);
  gap: var(--space-4);
  min-height: 46px;
}

.compose-label {
  font-size: var(--text-sm);
  color: var(--color-text-muted);
  min-width: 52px;
  flex-shrink: 0;
}

.compose-to-value {
  font-size: var(--text-sm);
  color: var(--color-text);
}

.compose-input {
  flex: 1;
  border: none;
  outline: none;
  font-size: var(--text-sm);
  font-family: var(--font-body);
  color: var(--color-text);
  background: transparent;
  padding: 0;
}

.compose-input::placeholder { color: var(--color-text-muted); }

.compose-divider {
  height: 1px;
  background: var(--color-border-light);
  margin: 0 var(--space-6);
}

.compose-body {
  display: block;
  width: 100%;
  min-height: 220px;
  border: none;
  outline: none;
  resize: vertical;
  padding: var(--space-4) var(--space-6);
  font-size: var(--text-sm);
  font-family: var(--font-body);
  color: var(--color-text);
  line-height: var(--leading-relaxed);
  background: transparent;
  box-sizing: border-box;
}

.compose-body::placeholder { color: var(--color-text-muted); }

.compose-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-4) var(--space-6);
  border-top: 1px solid var(--color-border-light);
  background: var(--color-bg-subtle);
  gap: var(--space-4);
  flex-wrap: wrap;
}

.compose-urgency-wrap {
  display: flex;
  align-items: center;
  gap: var(--space-3);
}

.compose-urgency-select {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: var(--space-2) var(--space-3);
  font-size: var(--text-sm);
  font-family: var(--font-body);
  color: var(--color-text);
  background: var(--color-bg-card);
  cursor: pointer;
}

/* Alert spacing in compose card */
.compose-card .alert {
  margin: var(--space-4) var(--space-6) 0;
  border-radius: var(--radius-md);
}

@media (max-width: 640px) {
  .compose-field  { padding: var(--space-3) var(--space-4); }
  .compose-divider { margin: 0 var(--space-4); }
  .compose-body   { padding: var(--space-3) var(--space-4); }
  .compose-footer { padding: var(--space-4); }
  .compose-header { padding: var(--space-4); }
}

/* ── Sent message history list ────────────────────────────────────────────── */
.sent-msg-list {
  display: flex;
  flex-direction: column;
}

.sent-msg-item {
  padding: var(--space-4) var(--space-5);
  border-bottom: 1px solid var(--color-border-light);
}

.sent-msg-item:last-child { border-bottom: none; }

.sent-msg-header {
  display: flex;
  align-items: center;
  gap: var(--space-4);
  flex-wrap: wrap;
}

.sent-msg-subject {
  flex: 1;
  font-size: var(--text-sm);
  font-weight: var(--weight-medium);
  color: var(--color-text);
}

.sent-msg-meta {
  font-size: var(--text-xs);
  color: var(--color-text-muted);
  white-space: nowrap;
}

.sent-msg-reply {
  margin-top: var(--space-3);
  background: var(--color-accent-light);
  border-left: 3px solid var(--color-accent);
  border-radius: var(--radius-md);
  padding: var(--space-3) var(--space-4);
}

.sent-msg-reply-label {
  font-size: var(--text-xs);
  font-weight: var(--weight-semibold);
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--color-accent-dark);
  margin-bottom: var(--space-2);
}

.sent-msg-reply-body {
  font-size: var(--text-sm);
  color: var(--color-text);
  line-height: var(--leading-relaxed);
}
</style>

</body>
</html>
