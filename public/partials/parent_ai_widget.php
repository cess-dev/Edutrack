<?php
/**
 * Parent AI Chat Widget Partial
 * Include before </body> on every parent page.
 * Renders nothing if AI_ENABLED is false.
 */
if (!defined('EDUTRACK_LOADED') || !defined('AI_ENABLED') || !AI_ENABLED) return;

$_parentId = $user['id'] ?? 0;
$_today    = date('Y-m-d');

// ── Proactive badge: any linked child absent today with no parent report? ─────
$_proactiveAlerts = [];
if ($_parentId) {
    $_proactiveAlerts = DB::rows(
        "SELECT DISTINCT u.full_name, u.id AS student_id
         FROM parent_student_links psl
         JOIN users u ON u.id = psl.student_id
         JOIN attendance_logs al ON al.student_id = u.id
         JOIN attendance_sessions s ON s.id = al.session_id
         LEFT JOIN parent_absence_reports par
               ON par.student_id = u.id AND par.report_date = ?
         WHERE psl.parent_id = ?
           AND al.status = 'absent'
           AND DATE(s.started_at) = ?
           AND par.id IS NULL",
        [$_today, $_parentId, $_today]
    );
}

$_hasBadge      = !empty($_proactiveAlerts);
$_badgeCount    = count($_proactiveAlerts);
$_proactiveMsg  = '';
if ($_hasBadge) {
    $names = implode(' and ', array_column($_proactiveAlerts, 'full_name'));
    $_proactiveMsg = "{$names} " . ($_badgeCount === 1 ? 'has' : 'have') . " been marked absent today. Would you like to report the reason?";
}

$_firstName = htmlspecialchars(explode(' ', $user['full_name'] ?? 'there')[0]);
?>

<!-- ── Floating trigger button ──────────────────────────────────────────────── -->
<button id="pai-btn"
        class="pai-btn"
        aria-label="Open parent assistant"
        aria-expanded="false"
        title="Parent support assistant"
        <?= $_hasBadge ? 'data-proactive-message="' . htmlspecialchars($_proactiveMsg, ENT_QUOTES) . '"' : '' ?>>
  <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor">
    <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
  </svg>
  <?php if ($_hasBadge): ?>
    <span class="pai-badge"><?= $_badgeCount ?></span>
  <?php endif; ?>
</button>

<!-- ── Chat panel ───────────────────────────────────────────────────────────── -->
<div id="pai-panel"
     class="pai-panel"
     role="dialog"
     aria-label="Parent support assistant"
     aria-hidden="true"
     hidden>

  <!-- Header -->
  <div class="pai-header">
    <div class="pai-header-avatar">✦</div>
    <div class="pai-header-info">
      <div class="pai-header-title">School Support</div>
      <div class="pai-header-subtitle">Local AI · Reports go to the school admin</div>
    </div>
    <button class="pai-header-close" onclick="ParentAiChat.close()" aria-label="Close">✕</button>
  </div>

  <!-- Messages -->
  <div id="pai-messages" class="pai-messages" aria-live="polite">
    <div class="pai-welcome">
      <span class="pai-welcome-icon">👋</span>
      <div class="pai-welcome-title">Hello, <?= $_firstName ?>!</div>
      <div class="pai-welcome-body">
        I can help you report an absence, ask about school info, or escalate a concern.
        All reports are sent directly to the school admin.
      </div>
    </div>

    <?php if ($_hasBadge): ?>
      <!-- Proactive alert — shows inside the chat before user types anything -->
      <div class="pai-action-banner" style="animation:none">
        <span class="pai-action-icon">⚠️</span>
        <div>
          <?= htmlspecialchars($_proactiveMsg) ?><br>
          <span class="pai-action-detail">Tap a suggestion below or type your message.</span>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Suggestion chips -->
  <div id="pai-chips" class="pai-chips">
    <?php if ($_hasBadge): ?>
      <?php foreach ($_proactiveAlerts as $_alert): ?>
        <button class="pai-chip"
                data-msg="<?= htmlspecialchars("{$_alert['full_name']} is sick today.", ENT_QUOTES) ?>">
          🤒 <?= htmlspecialchars($_alert['full_name']) ?> is sick today
        </button>
      <?php endforeach; ?>
    <?php endif; ?>
    <button class="pai-chip" data-msg="My child will not be coming to school today.">📅 Report absence</button>
    <button class="pai-chip" data-msg="I have a concern I need to report.">⚠️ Report a concern</button>
    <button class="pai-chip" data-msg="When are the school fees due?">💳 Fee deadline</button>
    <button class="pai-chip" data-msg="When are the exams?">📝 Exam dates</button>
  </div>

  <!-- Input -->
  <div class="pai-footer">
    <textarea id="pai-input"
              class="pai-input"
              placeholder="Type your message…"
              rows="1"
              maxlength="2000"
              aria-label="Message"></textarea>
    <button id="pai-send" class="pai-send" aria-label="Send">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <line x1="22" y1="2" x2="11" y2="13"/>
        <polygon points="22 2 15 22 11 13 2 9 22 2"/>
      </svg>
    </button>
  </div>

  <!-- Persistent human fallback -->
  <div class="pai-office-link">
    Need to speak to someone directly?
    <a href="tel:">Call the school office</a>
  </div>

</div>

<script src="<?= BASE_URL ?>/public/assets/js/parent-ai-chat.js"></script>
