<?php
/**
 * AI Chat Widget Partial — student portal only.
 * Include before </body> on every student page.
 * Renders nothing if AI_ENABLED is false.
 */
if (!defined('EDUTRACK_LOADED') || !defined('AI_ENABLED') || !AI_ENABLED) return;
$_firstName = htmlspecialchars(explode(' ', $user['full_name'] ?? 'there')[0]);
?>

<button id="ai-chat-btn"
        class="ai-chat-btn"
        aria-label="Open academic assistant"
        aria-expanded="false"
        title="Ask your academic assistant">
  <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor">
    <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 12H6v-2h12v2zm0-3H6V9h12v2zm0-3H6V6h12v2z"/>
  </svg>
</button>

<div id="ai-chat-panel"
     class="ai-chat-panel"
     role="dialog"
     aria-label="Academic assistant"
     aria-hidden="true"
     hidden>

  <div class="ai-chat-header">
    <div class="ai-chat-avatar">✦</div>
    <div class="ai-chat-head-info">
      <div class="ai-chat-title">Academic Assistant</div>
      <div class="ai-chat-subtitle">Local AI · Your data stays on this device</div>
    </div>
    <button class="ai-chat-close" onclick="AiChat.close()" aria-label="Close">✕</button>
  </div>

  <div id="ai-chat-messages" class="ai-chat-messages" aria-live="polite">
    <div class="ai-welcome">
      <span class="ai-welcome-icon">🎓</span>
      <div class="ai-welcome-title">Hi <?= $_firstName ?>!</div>
      <div class="ai-welcome-body">
        I can help with grade calculations, attendance questions,
        dispute guidance, and understanding your GPA.
      </div>
    </div>
  </div>

  <div id="ai-chat-suggestions" class="ai-chips">
    <button class="ai-chip">What do I need to pass my failing units?</button>
    <button class="ai-chip">Why is my attendance lower than expected?</button>
    <button class="ai-chip">Can I dispute any absences?</button>
    <button class="ai-chip">Explain my GPA</button>
  </div>

  <div class="ai-chat-footer">
    <textarea id="ai-chat-input"
              class="ai-chat-input"
              placeholder="Ask about marks, attendance, or GPA…"
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

  <div class="ai-disclaimer">Responses may be inaccurate. Verify with your lecturer.</div>
</div>

<script src="<?= BASE_URL ?>/public/assets/js/ai-chat.js"></script>
