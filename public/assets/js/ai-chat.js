/**
 * EduTrack — Student AI Chat Widget
 * Talks to the local LM Studio server via /api/ai/chat.php.
 * Conversation history is kept in memory for multi-turn context.
 */

const AiChat = (() => {

  let history    = [];   // [{ role:'user'|'model', text:'...' }]
  let isOpen     = false;
  let isThinking = false;

  const SUGGESTIONS = [
    'What do I need to pass my failing units?',
    'Why is my attendance lower than I expected?',
    'Can I still dispute any absent sessions?',
    'Explain my current GPA.',
  ];

  let btn, panel, feed, input, sendBtn, chips;

  // ── Init ────────────────────────────────────────────────────────────────────
  function init() {
    btn     = document.getElementById('ai-chat-btn');
    panel   = document.getElementById('ai-chat-panel');
    feed    = document.getElementById('ai-chat-messages');
    input   = document.getElementById('ai-chat-input');
    sendBtn = document.getElementById('ai-chat-send');
    chips   = document.getElementById('ai-chat-suggestions');

    if (!btn || !panel) return;

    btn.addEventListener('click', toggle);
    sendBtn.addEventListener('click', send);
    input.addEventListener('keydown', e => {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
    });
    input.addEventListener('input', autoResize);

    chips.querySelectorAll('.ai-chip').forEach(c => {
      c.addEventListener('click', () => {
        input.value = c.textContent.trim();
        chips.hidden = true;
        send();
      });
    });

    document.addEventListener('click', e => {
      if (isOpen && !panel.contains(e.target) && e.target !== btn) close();
    });
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && isOpen) close();
    });
  }

  // ── Open / close ─────────────────────────────────────────────────────────────
  function toggle() { isOpen ? close() : open(); }

  function open() {
    isOpen = true;
    panel.hidden = false;
    btn.setAttribute('aria-expanded', 'true');
    btn.innerHTML = closeSvg();
    requestAnimationFrame(() => panel.classList.add('ai-open'));
    setTimeout(() => input.focus(), 200);
  }

  function close() {
    isOpen = false;
    panel.classList.remove('ai-open');
    btn.setAttribute('aria-expanded', 'false');
    btn.innerHTML = chatSvg();
    setTimeout(() => { panel.hidden = true; }, 260);
  }

  // ── Send ─────────────────────────────────────────────────────────────────────
  async function send() {
    const text = input.value.trim();
    if (!text || isThinking) return;

    chips.hidden = true;
    input.value  = '';
    autoResize();

    appendBubble('user', text);
    history.push({ role: 'user', text });

    showTyping();
    isThinking       = true;
    sendBtn.disabled = true;
    input.disabled   = true;

    try {
      const BASE = document.documentElement.dataset.baseUrl || '';
      const res  = await fetch(`${BASE}/api/ai/chat.php`, {
        method:      'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify({
          message: text,
          history: history.slice(0, -1),
        }),
      });

      const data = await res.json();
      hideTyping();

      if (data.success) {
        appendBubble('model', data.reply);
        history.push({ role: 'model', text: data.reply });
        if (history.length > 20) history = history.slice(-20);
      } else {
        appendError(data.message || 'Something went wrong. Please try again.');
        history.pop();
      }
    } catch {
      hideTyping();
      appendError('Could not reach the AI. Make sure LM Studio is running.');
      history.pop();
    } finally {
      isThinking       = false;
      sendBtn.disabled = false;
      input.disabled   = false;
      input.focus();
    }
  }

  // ── Render ────────────────────────────────────────────────────────────────────
  function appendBubble(role, text) {
    const isUser = role === 'user';
    const div    = document.createElement('div');
    div.className = `ai-msg ${isUser ? 'ai-msg-user' : 'ai-msg-model'}`;
    div.innerHTML = isUser
      ? `<div class="ai-bubble ai-bubble-user">${esc(text)}</div>`
      : `<div class="ai-avatar-sm">✦</div><div class="ai-bubble ai-bubble-model">${fmt(text)}</div>`;
    feed.appendChild(div);
    scrollDown();
  }

  function appendError(msg) {
    const div = document.createElement('div');
    div.className = 'ai-err';
    div.textContent = msg;
    feed.appendChild(div);
    scrollDown();
  }

  function showTyping() {
    const div = document.createElement('div');
    div.id = 'ai-typing';
    div.className = 'ai-msg ai-msg-model';
    div.innerHTML = `<div class="ai-avatar-sm">✦</div>
      <div class="ai-bubble ai-bubble-model ai-typing">
        <span></span><span></span><span></span>
      </div>`;
    feed.appendChild(div);
    scrollDown();
  }

  function hideTyping()   { document.getElementById('ai-typing')?.remove(); }
  function scrollDown()   { feed.scrollTop = feed.scrollHeight; }

  // ── Formatting ────────────────────────────────────────────────────────────────
  function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  function fmt(s) {
    let h = esc(s);
    h = h.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    h = h.replace(/`([^`]+)`/g, '<code>$1</code>');
    h = h.replace(/\n/g, '<br>');
    return h;
  }

  function autoResize() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 120) + 'px';
  }

  // ── Icons ─────────────────────────────────────────────────────────────────────
  function chatSvg() {
    return `<svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor">
      <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 12H6v-2h12v2zm0-3H6V9h12v2zm0-3H6V6h12v2z"/>
    </svg>`;
  }
  function closeSvg() {
    return `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
      <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
    </svg>`;
  }

  document.addEventListener('DOMContentLoaded', init);

  return { open, close, toggle };
})();
