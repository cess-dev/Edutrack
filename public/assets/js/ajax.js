/**
 * EduTrack — Shared AJAX / Fetch Wrapper
 *
 * Provides a consistent interface for all API calls across every portal.
 * Handles:
 *   - Automatic CSRF token injection on POST/PUT/DELETE requests
 *   - Session expiry detection (401 → redirect to login)
 *   - Centralised error handling and user-facing error messages
 *   - JSON and multipart/form-data request support
 *   - Request loading state management
 *   - Response normalisation
 *
 * Usage:
 *   // GET request
 *   const data = await Api.get('/api/attendance/history.php?view=summary');
 *
 *   // POST JSON
 *   const data = await Api.post('/api/attendance/scan.php', { qr_data: '...' });
 *
 *   // POST form data (file upload)
 *   const data = await Api.upload('/api/marks/upload.php', formElement);
 *
 *   // Handle errors gracefully
 *   const data = await Api.get('/api/marks/view.php').catch(Api.silent);
 *
 * All methods return the parsed JSON response body.
 * On session expiry (401) the page automatically redirects to login.
 * On server errors (500) a generic toast is shown.
 */

const Api = (() => {
  // ── Configuration ──────────────────────────────────────────────────────────
  const BASE_URL            = document.documentElement.dataset.baseUrl || '';
  const SESSION_CHECK_URL   = `${BASE_URL}/api/auth/session_check.php`;
  const SESSION_CHECK_EVERY = 5 * 60 * 1000;   // 5 minutes in ms
  const WARN_BEFORE_EXPIRY  = 10 * 60;          // Warn when 10 minutes remain (seconds)

  // ── Internal state ─────────────────────────────────────────────────────────
  let csrfToken          = null;   // Refreshed from session_check on each poll
  let sessionCheckTimer  = null;
  let sessionWarnShown   = false;

  // ── Bootstrap ──────────────────────────────────────────────────────────────
  /**
   * Initialise the AJAX module.
   * Call once on DOMContentLoaded for any authenticated page.
   *
   * - Reads initial CSRF token from a meta tag if present
   * - Starts the session keep-alive polling loop
   * - Attaches a logout handler to elements with [data-logout]
   */
  function init() {
    // Read CSRF token from meta tag:
    // <meta name="csrf-token" content="<?= Auth::csrfToken() ?>">
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) {
      csrfToken = meta.getAttribute('content');
    }

    _startSessionCheck();
    _bindLogout();
  }

  // ── Core fetch wrapper ─────────────────────────────────────────────────────
  /**
   * Central fetch function used by all public methods.
   * Adds the CSRF token header automatically on mutating requests.
   *
   * @param  {string} url
   * @param  {object} options  Standard fetch options
   * @return {Promise<object>} Parsed JSON response
   * @throws {ApiError}        On non-2xx responses
   */
  async function _fetch(url, options = {}) {
    const fullUrl = url.startsWith('http') ? url : `${BASE_URL}${url}`;

    // Inject CSRF token on state-mutating methods
    const mutating = ['POST', 'PUT', 'PATCH', 'DELETE'];
    if (mutating.includes((options.method || 'GET').toUpperCase())) {
      options.headers = options.headers || {};

      if (!(options.body instanceof FormData)) {
        // JSON requests: add header
        options.headers['X-CSRF-Token'] = csrfToken || '';
      } else {
        // Multipart: append to FormData body instead (header blocked by browser)
        options.body.append('csrf_token', csrfToken || '');
      }
    }

    // Always include credentials (session cookie)
    options.credentials = 'same-origin';

    let response;
    try {
      response = await fetch(fullUrl, options);
    } catch (networkError) {
      throw new ApiError(0, 'Network error. Please check your connection.', null);
    }

    // Session expired — redirect to login
    if (response.status === 401) {
      _handleSessionExpiry();
      throw new ApiError(401, 'Session expired. Redirecting to login...', null);
    }

    // Parse JSON body
    let body;
    try {
      body = await response.json();
    } catch {
      throw new ApiError(response.status, 'Invalid response from server.', null);
    }

    // Treat non-2xx as errors
    if (!response.ok) {
      const message = body?.message || `Server error (${response.status})`;
      throw new ApiError(response.status, message, body);
    }

    return body;
  }

  // ── Public methods ─────────────────────────────────────────────────────────

  /**
   * GET request.
   * @param  {string} url     Path or full URL
   * @param  {object} params  Optional query parameters as key-value pairs
   * @return {Promise<object>}
   */
  async function get(url, params = {}) {
    const qs = new URLSearchParams(params).toString();
    const fullUrl = qs ? `${url}${url.includes('?') ? '&' : '?'}${qs}` : url;

    return _fetch(fullUrl, {
      method:  'GET',
      headers: { 'Accept': 'application/json' },
    });
  }

  /**
   * POST request with a JSON body.
   * @param  {string} url
   * @param  {object} data
   * @return {Promise<object>}
   */
  async function post(url, data = {}) {
    return _fetch(url, {
      method:  'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept':       'application/json',
      },
      body: JSON.stringify(data),
    });
  }

  /**
   * POST a FormData object (used for file uploads and HTML forms).
   * CSRF token is appended to the FormData automatically.
   *
   * @param  {string}          url
   * @param  {FormData|HTMLFormElement} formOrData
   * @return {Promise<object>}
   */
  async function upload(url, formOrData) {
    const formData = formOrData instanceof FormData
      ? formOrData
      : new FormData(formOrData);

    return _fetch(url, {
      method:  'POST',
      headers: { 'Accept': 'application/json' },
      body:    formData,
      // No Content-Type header — browser sets it with boundary automatically
    });
  }

  // ── Loading state helpers ──────────────────────────────────────────────────

  /**
   * Wrap an async API call with automatic loading state on a button.
   * Disables the button and shows a spinner while the request is in flight.
   *
   * Usage:
   *   await Api.withLoading(submitBtn, () => Api.post('/api/...', data));
   *
   * @param  {HTMLElement}  button
   * @param  {Function}     fn       Async function returning a Promise
   * @return {Promise<any>}
   */
  async function withLoading(button, fn) {
    const originalText    = button.innerHTML;
    const originalDisabled = button.disabled;

    button.disabled   = true;
    button.innerHTML  = `<span class="spinner" aria-hidden="true"></span> Please wait...`;

    try {
      return await fn();
    } finally {
      button.disabled  = originalDisabled;
      button.innerHTML = originalText;
    }
  }

  // ── Response helpers ───────────────────────────────────────────────────────

  /**
   * Silence an ApiError — use as a .catch() handler when failure is acceptable.
   * Logs to console in development, swallows in production.
   *
   * Usage:
   *   const data = await Api.get('/api/...').catch(Api.silent);
   *   // data is undefined if the request failed
   */
  function silent(err) {
    if (err instanceof ApiError) {
      console.warn('[EduTrack] Silent API error:', err.status, err.message);
    } else {
      console.error('[EduTrack] Unexpected error:', err);
    }
    return undefined;
  }

  /**
   * Show a standardised error message for an ApiError.
   * Renders into an element with [data-error-container] on the page,
   * or falls back to a toast if no container exists.
   *
   * @param {ApiError|Error} err
   */
  function showError(err) {
    const message = err instanceof ApiError
      ? err.message
      : 'An unexpected error occurred. Please try again.';

    const container = document.querySelector('[data-error-container]');
    if (container) {
      container.textContent = message;
      container.hidden      = false;
      container.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } else {
      Toast.show('error', message);
    }
  }

  // ── Session management ─────────────────────────────────────────────────────

  /**
   * Poll session_check.php every SESSION_CHECK_EVERY ms.
   * On each response:
   *   - Refresh the stored CSRF token
   *   - Show a warning if the session expires within WARN_BEFORE_EXPIRY seconds
   *   - Redirect to login if logged_in is false
   */
  function _startSessionCheck() {
    sessionCheckTimer = setInterval(_checkSession, SESSION_CHECK_EVERY);
  }

  async function _checkSession() {
    try {
      const data = await get(SESSION_CHECK_URL);

      if (!data.logged_in) {
        _handleSessionExpiry();
        return;
      }

      // Refresh CSRF token
      if (data.csrf_token) {
        csrfToken = data.csrf_token;
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) meta.setAttribute('content', csrfToken);
      }

      // Warn if expiring soon
      if (data.expires_in <= WARN_BEFORE_EXPIRY && !sessionWarnShown) {
        sessionWarnShown = true;
        const minutes    = Math.ceil(data.expires_in / 60);
        Toast.show('warning',
          `Your session expires in ${minutes} minute(s). ` +
          `<a href="#" onclick="Api.extendSession()">Stay logged in</a>`
        );
      }

    } catch {
      // Network hiccup — silently ignore, will retry next interval
    }
  }

  /**
   * Extend the session by making a lightweight authenticated request.
   * Called when the user clicks "Stay logged in" in the expiry warning.
   */
  async function extendSession() {
    await get(SESSION_CHECK_URL).catch(silent);
    sessionWarnShown = false;
    Toast.show('success', 'Session extended.');
  }

  function _handleSessionExpiry() {
    clearInterval(sessionCheckTimer);
    Toast.show('error', 'Your session has expired. Redirecting to login...');
    setTimeout(() => {
      window.location.href = `${BASE_URL}/index.php?expired=1`;
    }, 2000);
  }

  // ── Logout binding ─────────────────────────────────────────────────────────

  /**
   * Bind click handlers to any element with [data-logout] attribute.
   * Sends POST to /api/auth/logout.php with CSRF token, then redirects.
   */
  function _bindLogout() {
    document.querySelectorAll('[data-logout]').forEach(el => {
      el.addEventListener('click', async (e) => {
        e.preventDefault();

        try {
          const data = await post(`${BASE_URL}/api/auth/logout.php`);
          if (data.redirect) {
            window.location.href = data.redirect;
          }
        } catch {
          // If logout API fails, force a page reload to clear the session UI
          window.location.reload();
        }
      });
    });
  }

  // ── Public API ─────────────────────────────────────────────────────────────
  return {
    init,
    get,
    post,
    upload,
    withLoading,
    silent,
    showError,
    extendSession,
    get csrfToken() { return csrfToken; },
  };

})();


// =============================================================================
// ApiError — custom error class for non-2xx responses
// =============================================================================
class ApiError extends Error {
  /**
   * @param {number} status      HTTP status code
   * @param {string} message     Human-readable error message
   * @param {object|null} body   Full parsed response body (may be null)
   */
  constructor(status, message, body) {
    super(message);
    this.name    = 'ApiError';
    this.status  = status;
    this.body    = body;
  }
}


// =============================================================================
// Toast — lightweight notification system used by Api and other modules
// =============================================================================
const Toast = (() => {
  let container = null;

  function _getContainer() {
    if (container) return container;

    container = document.createElement('div');
    container.id = 'toast-container';
    Object.assign(container.style, {
      position:      'fixed',
      bottom:        '24px',
      right:         '24px',
      zIndex:        '99999',
      display:       'flex',
      flexDirection: 'column',
      gap:           '10px',
      maxWidth:      '360px',
    });
    document.body.appendChild(container);
    return container;
  }

  /**
   * Show a toast notification.
   *
   * @param {'success'|'error'|'warning'|'info'} type
   * @param {string} message  May contain safe HTML (e.g. links)
   * @param {number} duration ms before auto-dismiss (default 4000)
   */
  function show(type, message, duration = 4000) {
    const colours = {
      success: { bg: 'var(--color-success, #0F7B6C)', icon: '✓' },
      error:   { bg: 'var(--color-error,   #D85A30)', icon: '✕' },
      warning: { bg: 'var(--color-warning, #C47B12)', icon: '⚠' },
      info:    { bg: 'var(--color-accent,  #1A3C5E)', icon: 'ℹ' },
    };

    const { bg, icon } = colours[type] || colours.info;
    const toast = document.createElement('div');
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');

    Object.assign(toast.style, {
      background:   bg,
      color:        '#ffffff',
      padding:      '12px 16px',
      borderRadius: '8px',
      fontSize:     '14px',
      lineHeight:   '1.4',
      display:      'flex',
      gap:          '10px',
      alignItems:   'flex-start',
      boxShadow:    '0 4px 16px rgba(0,0,0,0.18)',
      opacity:      '0',
      transform:    'translateY(8px)',
      transition:   'opacity 0.25s ease, transform 0.25s ease',
      cursor:       'pointer',
    });

    toast.innerHTML = `<span style="font-size:16px;flex-shrink:0">${icon}</span>
                       <span>${message}</span>`;

    // Click to dismiss
    toast.addEventListener('click', () => _dismiss(toast));

    _getContainer().appendChild(toast);

    // Animate in
    requestAnimationFrame(() => {
      toast.style.opacity   = '1';
      toast.style.transform = 'translateY(0)';
    });

    // Auto-dismiss
    if (duration > 0) {
      setTimeout(() => _dismiss(toast), duration);
    }

    return toast;
  }

  function _dismiss(toast) {
    toast.style.opacity   = '0';
    toast.style.transform = 'translateY(8px)';
    setTimeout(() => toast.remove(), 300);
  }

  return { show };
})();


// =============================================================================
// Auto-initialise when the DOM is ready
// =============================================================================
document.addEventListener('DOMContentLoaded', () => {
  // Only init on authenticated pages (those with a CSRF meta tag)
  if (document.querySelector('meta[name="csrf-token"]')) {
    Api.init();
  }
});