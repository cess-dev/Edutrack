<?php
/**
 * Lecturer AI Teaching Assistant Widget
 * Include before </body> on lecturer portal pages.
 * Renders nothing if AI_ENABLED is false.
 */
if (!defined('EDUTRACK_LOADED') || !defined('AI_ENABLED') || !AI_ENABLED) return;
$_lecFirstName = htmlspecialchars(explode(' ', $user['full_name'] ?? 'there')[0]);
?>

<button id="ai-chat-btn"
        class="ai-chat-btn"
        aria-label="Open teaching assistant"
        aria-expanded="false"
        title="Teaching assistant">
  <svg width="21" height="21" viewBox="0 0 24 24" fill="currentColor">
    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/>
  </svg>
</button>

<div id="ai-chat-panel"
     class="ai-chat-panel"
     role="dialog"
     aria-label="Teaching assistant"
     aria-hidden="true"
     hidden>

  <div class="ai-chat-header">
    <div class="ai-chat-avatar">⚡</div>
    <div class="ai-chat-head-info">
      <div class="ai-chat-title">Teaching Assistant</div>
      <div class="ai-chat-subtitle">Local AI · Your data stays on this device</div>
    </div>
    <button class="ai-chat-close" onclick="AiChat.close()" aria-label="Close">✕</button>
  </div>

  <div id="ai-chat-messages" class="ai-chat-messages" aria-live="polite">
    <div class="ai-welcome">
      <span class="ai-welcome-icon">📊</span>
      <div class="ai-welcome-title">Hi <?= $_lecFirstName ?>!</div>
      <div class="ai-welcome-body">
        I can summarise at-risk students, triage pending disputes,
        walk you through system workflows, and flag unusual patterns in your data.
      </div>
    </div>
  </div>

  <div id="ai-chat-suggestions" class="ai-chips">
    <button class="ai-chip">Which students are at risk this semester?</button>
    <button class="ai-chip">Summarise my pending disputes</button>
    <button class="ai-chip">How do I bulk upload marks via CSV?</button>
    <button class="ai-chip">Show marks upload status for all units</button>
  </div>

  <div class="ai-chat-footer">
    <textarea id="ai-chat-input"
              class="ai-chat-input"
              placeholder="Ask about students, marks, disputes, or workflows…"
              rows="1"
              maxlength="2000"
              aria-label="Message"></textarea>
    <button id="ai-chat-send" class="ai-send-btn" aria-label="Send">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <line x1="22" y1="2" x2="11" y2="13"/>
        <polygon points="22 2 15 22 11 13 2 9 22 2"/>
      </svg>
    </button>
  </div>

  <div class="ai-disclaimer">Summaries are informational. All professional decisions remain yours.</div>
</div>

<script src="<?= BASE_URL ?>/public/assets/js/lecturer-ai-chat.js"></script>
