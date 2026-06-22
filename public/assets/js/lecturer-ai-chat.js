/**
 * EduTrack — Lecturer AI Teaching Assistant Widget
 * Talks to the local LM Studio server via /api/ai/lecturer_chat.php.
 * Conversation history kept in memory for multi-turn context.
 */

const AiChat = (() => {

  let history    = [];
  let isOpen     = false;
  let isThinking = false;

  let btn, panel, feed, input, sendBtn, chips;

  // ── Init ──────────────────────────────────────────────────────────────────────
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
        input.value  = c.textContent.trim();
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

  // ── Open / close ──────────────────────────────────────────────────────────────
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
    btn.innerHTML = infoSvg();
    setTimeout(() => { panel.hidden = true; }, 260);
  }

  // ── Send ──────────────────────────────────────────────────────────────────────
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
      const res  = await fetch(`${BASE}/api/ai/lecturer_chat.php`, {
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
    } catch (err) {
      hideTyping();
      appendError('Could not reach the AI service. Check that LM Studio is running and accessible. (' + err.message + ')');
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
      : `<div class="ai-avatar-sm">⚡</div><div class="ai-bubble ai-bubble-model">${fmt(text)}</div>`;
    feed.appendChild(div);
    scrollDown();
  }

  function appendError(msg) {
    const div = document.createElement('div');
    div.className   = 'ai-err';
    div.textContent = msg;
    feed.appendChild(div);
    scrollDown();
  }

  function showTyping() {
    const div = document.createElement('div');
    div.id        = 'ai-typing';
    div.className = 'ai-msg ai-msg-model';
    div.innerHTML = `<div class="ai-avatar-sm">⚡</div>
      <div class="ai-bubble ai-bubble-model ai-typing">
        <span></span><span></span><span></span>
      </div>`;
    feed.appendChild(div);
    scrollDown();
  }

  function hideTyping() { document.getElementById('ai-typing')?.remove(); }
  function scrollDown()  { feed.scrollTop = feed.scrollHeight; }

  // ── Formatting ────────────────────────────────────────────────────────────────
  function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  function fmt(s) {
    let h = esc(s);
    h = h.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    h = h.replace(/`([^`]+)`/g,     '<code>$1</code>');
    h = h.replace(/\n/g, '<br>');
    return h;
  }

  function autoResize() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 120) + 'px';
  }

  // ── Icons ─────────────────────────────────────────────────────────────────────
  function infoSvg() {
    return `<svg width="21" height="21" viewBox="0 0 24 24" fill="currentColor">
      <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/>
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
