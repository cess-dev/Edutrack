/**
 * EduTrack — Parent AI Chat Widget
 *
 * Extends the base chat pattern with parent-specific features:
 *   - Action confirmation banners (absence logged, incident escalated)
 *   - "Contact school office" persistent footer link
 *   - Proactive alert pre-fill when opened via badge click
 */

const ParentAiChat = (() => {

  let history    = [];
  let isOpen     = false;
  let isThinking = false;

  let btn, panel, feed, input, sendBtn, chips;

  // ── Init ────────────────────────────────────────────────────────────────────
  function init() {
    btn     = document.getElementById('pai-btn');
    panel   = document.getElementById('pai-panel');
    feed    = document.getElementById('pai-messages');
    input   = document.getElementById('pai-input');
    sendBtn = document.getElementById('pai-send');
    chips   = document.getElementById('pai-chips');

    if (!btn || !panel) return;

    btn.addEventListener('click', toggle);
    sendBtn.addEventListener('click', send);
    input.addEventListener('keydown', e => {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
    });
    input.addEventListener('input', autoResize);

    chips.querySelectorAll('.pai-chip').forEach(c => {
      c.addEventListener('click', () => {
        input.value = c.dataset.msg || c.textContent.trim();
        chips.hidden = true;
        send();
      });
    });

    // If opened via the proactive badge, pre-fill the alert message
    const alert = btn.dataset.proactiveMessage;
    if (alert) {
      btn.addEventListener('click', () => {
        if (history.length === 0 && input.value === '') {
          chips.hidden = true;
          input.value = alert;
          autoResize();
        }
      }, { once: true });
    }

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
    // Clear badge
    const badge = btn.querySelector('.pai-badge');
    if (badge) badge.remove();
    requestAnimationFrame(() => panel.classList.add('pai-open'));
    if (history.length === 0) chips.hidden = false;
    setTimeout(() => input.focus(), 200);
  }

  function close() {
    isOpen = false;
    panel.classList.remove('pai-open');
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
      const res  = await fetch(`${BASE}/api/ai/parent_chat.php`, {
        method:      'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify({ message: text, history: history.slice(0, -1) }),
      });

      const data = await res.json();
      hideTyping();

      if (data.success) {
        appendBubble('model', data.reply);
        history.push({ role: 'model', text: data.reply });
        if (history.length > 20) history = history.slice(-20);

        // Show action confirmation banners
        if (data.actions && data.actions.length > 0) {
          data.actions.forEach(action => {
            if (action.success) appendActionBanner(action);
          });
        }
      } else {
        appendError(data.message || 'Something went wrong. Please try again.');
        history.pop();
      }
    } catch {
      hideTyping();
      appendError('Could not reach the assistant. Make sure LM Studio is running.');
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
    div.className = `pai-msg ${isUser ? 'pai-msg-user' : 'pai-msg-model'}`;
    div.innerHTML = isUser
      ? `<div class="pai-bubble pai-user">${esc(text)}</div>`
      : `<div class="pai-avatar">✦</div><div class="pai-bubble pai-model">${fmt(text)}</div>`;
    feed.appendChild(div);
    scrollDown();
  }

  function appendActionBanner(action) {
    const div = document.createElement('div');
    div.className = 'pai-action-banner';

    if (action.student_name && action.date) {
      // Absence confirmation
      div.innerHTML = `
        <span class="pai-action-icon">✅</span>
        <div>
          <strong>${esc(action.student_name)}</strong> flagged absent on ${esc(action.date)}.<br>
          <span class="pai-action-detail">${esc(action.sessions || '')}</span>
        </div>`;
    } else if (action.type) {
      // Incident logged
      const isUrgent = ['missing_child', 'medical_emergency'].includes(action.type);
      div.innerHTML = `
        <span class="pai-action-icon">${isUrgent ? '🚨' : '📋'}</span>
        <div>
          Concern logged — ${esc(action.urgency || 'admin will follow up')}.
        </div>`;
      if (isUrgent) div.classList.add('pai-action-urgent');
    }

    feed.appendChild(div);
    scrollDown();
  }

  function appendError(msg) {
    const div = document.createElement('div');
    div.className = 'pai-err';
    div.textContent = msg;
    feed.appendChild(div);
    scrollDown();
  }

  function showTyping() {
    const div = document.createElement('div');
    div.id = 'pai-typing';
    div.className = 'pai-msg pai-msg-model';
    div.innerHTML = `<div class="pai-avatar">✦</div>
      <div class="pai-bubble pai-model pai-typing">
        <span></span><span></span><span></span>
      </div>`;
    feed.appendChild(div);
    scrollDown();
  }

  function hideTyping()   { document.getElementById('pai-typing')?.remove(); }
  function scrollDown()   { feed.scrollTop = feed.scrollHeight; }

  function esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  function fmt(s) {
    let h = esc(s);
    h = h.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    h = h.replace(/`([^`]+)`/g,    '<code>$1</code>');
    h = h.replace(/\n/g, '<br>');
    return h;
  }

  function autoResize() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 120) + 'px';
  }

  function chatSvg() {
    return `<svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor">
      <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
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
