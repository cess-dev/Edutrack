/**
 * EduTrack — QR Scanner
 *
 * Accesses the device camera, continuously decodes video frames using jsQR,
 * and POSTs the decoded payload to the scan API endpoint.
 *
 * Designed to run on the student portal's scan page.
 * Requires jsQR (loaded via vendor/jsQR.min.js) and a page structure
 * containing the elements referenced by SELECTORS below.
 *
 * Usage — include this file on the scan page, then call:
 *   QRScanner.init({
 *     scanEndpoint: '/edutrack/api/attendance/scan.php',
 *     onSuccess:    (data) => { ... },
 *     onError:      (data) => { ... },
 *   });
 *
 * Required HTML elements (IDs configurable via options):
 *   #qr-video        — <video> element for camera stream
 *   #qr-canvas       — <canvas> element (can be hidden) for frame capture
 *   #scanner-status  — element where status messages are rendered
 *   #start-btn       — button to start the scanner
 *   #stop-btn        — button to stop the scanner
 */

const QRScanner = (() => {
  // ── Default configuration ──────────────────────────────────────────────────
  const DEFAULTS = {
    scanEndpoint:    '/edutrack/api/attendance/scan.php',
    videoId:         'qr-video',
    canvasId:        'qr-canvas',
    statusId:        'scanner-status',
    startBtnId:      'start-btn',
    stopBtnId:       'stop-btn',
    overlayId:       'qr-overlay',
    scanIntervalMs:  200,      // How often to sample a video frame (ms)
    cooldownMs:      3000,     // Pause after a scan attempt before re-scanning
    onSuccess:       null,     // Callback(data) on successful attendance record
    onError:         null,     // Callback(data) on validation/server failure
  };

  // ── Internal state ─────────────────────────────────────────────────────────
  let config       = {};
  let stream       = null;    // MediaStream from getUserMedia
  let rafId        = null;    // requestAnimationFrame ID
  let scanning     = false;   // True while the decode loop is running
  let inCooldown   = false;   // True during the post-scan pause
  let lastCode     = null;    // Last decoded QR string (prevents re-posting same code)

  // ── DOM references ─────────────────────────────────────────────────────────
  let video    = null;
  let canvas   = null;
  let ctx      = null;
  let statusEl = null;
  let startBtn = null;
  let stopBtn  = null;
  let overlay  = null;

  // ── Public: initialise ─────────────────────────────────────────────────────
  /**
   * Set up the scanner with the given options and bind button events.
   * Does NOT start the camera — user must click the start button.
   *
   * @param {object} options  Merged with DEFAULTS
   */
  function init(options = {}) {
    config = { ...DEFAULTS, ...options };

    video    = document.getElementById(config.videoId);
    canvas   = document.getElementById(config.canvasId);
    statusEl = document.getElementById(config.statusId);
    startBtn = document.getElementById(config.startBtnId);
    stopBtn  = document.getElementById(config.stopBtnId);
    overlay  = document.getElementById(config.overlayId);
    ctx      = canvas ? canvas.getContext('2d') : null;

    if (!video || !canvas || !ctx) {
      _setStatus('error', 'Scanner elements not found on this page.');
      return;
    }

    // Check browser support
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      _setStatus('error',
        'Your browser does not support camera access. ' +
        'Please use Chrome or Firefox on a mobile device.');
      return;
    }

    if (typeof jsQR === 'undefined') {
      _setStatus('error', 'QR library failed to load. Please refresh the page.');
      return;
    }

    // Bind buttons
    if (startBtn) startBtn.addEventListener('click', start);
    if (stopBtn)  stopBtn.addEventListener('click', stop);

    _setStatus('idle', 'Press "Start Scanner" to activate your camera.');
    _updateButtons(false);
  }

  // ── Public: start ──────────────────────────────────────────────────────────
  /**
   * Request camera permission and begin the decode loop.
   * Uses rear camera on mobile (facingMode: environment).
   */
  async function start() {
    if (scanning) return;

    _setStatus('loading', 'Requesting camera access...');
    _updateButtons(false);

    try {
      stream = await navigator.mediaDevices.getUserMedia({
        video: {
          facingMode:  { ideal: 'environment' },  // Rear camera
          width:       { ideal: 1280 },
          height:      { ideal: 720 },
        },
        audio: false,
      });

      video.srcObject = stream;

      // Wait for video metadata to load before starting loop
      video.addEventListener('loadedmetadata', () => {
        video.play();
        scanning = true;
        inCooldown = false;
        lastCode   = null;
        _setStatus('scanning', 'Scanning... Hold the QR code steady in front of your camera.');
        _updateButtons(true);
        _showOverlay(true);
        _decodeLoop();
      }, { once: true });

    } catch (err) {
      _handleCameraError(err);
    }
  }

  // ── Public: stop ───────────────────────────────────────────────────────────
  /**
   * Stop the camera stream and decode loop.
   */
  function stop() {
    scanning = false;

    if (rafId) {
      cancelAnimationFrame(rafId);
      rafId = null;
    }

    if (stream) {
      stream.getTracks().forEach(track => track.stop());
      stream = null;
    }

    if (video) {
      video.srcObject = null;
    }

    lastCode   = null;
    inCooldown = false;

    _setStatus('idle', 'Scanner stopped. Press "Start Scanner" to scan again.');
    _updateButtons(false);
    _showOverlay(false);
  }

  // ── Private: decode loop ───────────────────────────────────────────────────
  /**
   * Continuously capture frames from the video element and attempt
   * to decode a QR code using jsQR.
   *
   * Uses requestAnimationFrame for smooth, battery-friendly looping.
   * Only attempts a decode every scanIntervalMs milliseconds to avoid
   * hammering the CPU on each frame.
   */
  let lastScanTime = 0;

  function _decodeLoop() {
    if (!scanning) return;

    rafId = requestAnimationFrame((timestamp) => {
      if (timestamp - lastScanTime >= config.scanIntervalMs) {
        lastScanTime = timestamp;
        _captureAndDecode();
      }
      _decodeLoop();
    });
  }

  function _captureAndDecode() {
    if (!video || video.readyState !== video.HAVE_ENOUGH_DATA) return;
    if (inCooldown) return;

    // Size the canvas to match current video dimensions
    canvas.width  = video.videoWidth;
    canvas.height = video.videoHeight;
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);

    // jsQR returns null if no QR code is found in the frame
    const decoded = jsQR(imageData.data, imageData.width, imageData.height, {
      inversionAttempts: 'dontInvert',
    });

    if (!decoded) return;  // No QR in this frame — keep looping

    const qrData = decoded.data.trim();

    // Skip if this is the same code we just submitted
    if (qrData === lastCode) return;

    lastCode   = qrData;
    inCooldown = true;

    _setStatus('submitting', 'QR code detected. Recording attendance...');
    _submitScan(qrData);
  }

  // ── Private: submit scan ───────────────────────────────────────────────────
  /**
   * POST the decoded QR string to the scan API endpoint.
   * On success or failure, shows a toast and resets the cooldown.
   *
   * @param {string} qrData
   */
  async function _submitScan(qrData) {
    try {
      const response = await fetch(config.scanEndpoint, {
        method:      'POST',
        headers:     { 'Content-Type': 'application/json' },
        credentials: 'same-origin',  // Include session cookie
        body:        JSON.stringify({ qr_data: qrData }),
      });

      const data = await response.json();

      if (data.success) {
        _setStatus('success',
          `✓ Attendance recorded for ${data.unit_name || 'your unit'}.`
        );
        _showToast('success', data.message);
        stop();  // Stop scanner on success — no need to keep camera running

        if (typeof config.onSuccess === 'function') {
          config.onSuccess(data);
        }

      } else {
        // Determine whether to stop or continue scanning based on error type
        const fatalCodes = ['NOT_ENROLLED', 'HMAC_INVALID'];
        const isFatal    = fatalCodes.includes(data.error_code);

        _setStatus('error', data.message);
        _showToast('error', data.message);

        if (isFatal) {
          stop();
        } else {
          // Non-fatal: reset cooldown and allow re-scan after a pause
          setTimeout(() => {
            if (scanning) {
              inCooldown = false;
              lastCode   = null;
              _setStatus('scanning', 'Ready to scan. Hold the QR code in front of your camera.');
            }
          }, config.cooldownMs);
        }

        if (typeof config.onError === 'function') {
          config.onError(data);
        }
      }

    } catch (networkErr) {
      _setStatus('error', 'Network error. Please check your connection and try again.');
      _showToast('error', 'Connection failed. Please try again.');

      setTimeout(() => {
        if (scanning) {
          inCooldown = false;
          lastCode   = null;
          _setStatus('scanning', 'Ready to scan again.');
        }
      }, config.cooldownMs);
    }
  }

  // ── Private: camera error handler ─────────────────────────────────────────
  function _handleCameraError(err) {
    let message = '';

    switch (err.name) {
      case 'NotAllowedError':
      case 'PermissionDeniedError':
        message =
          'Camera permission denied. ' +
          'Please allow camera access in your browser settings and refresh the page.';
        break;

      case 'NotFoundError':
      case 'DevicesNotFoundError':
        message = 'No camera found on this device.';
        break;

      case 'NotReadableError':
      case 'TrackStartError':
        message = 'Camera is in use by another application. Please close it and try again.';
        break;

      case 'OverconstrainedError':
        // Retry without the ideal constraints
        _retryWithBasicConstraints();
        return;

      case 'NotSupportedError':
        message =
          'Camera access requires HTTPS. ' +
          'Please access the student portal via https:// or ask your administrator to ' +
          'enable HTTPS on the school server.';
        break;

      default:
        message = `Camera error: ${err.message || err.name}. Please refresh and try again.`;
    }

    _setStatus('error', message);
    _updateButtons(false);
  }

  /**
   * Fallback: retry getUserMedia with minimal constraints if the
   * ideal rear-camera constraints failed (common on some Android devices).
   */
  async function _retryWithBasicConstraints() {
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
      video.srcObject = stream;
      video.addEventListener('loadedmetadata', () => {
        video.play();
        scanning   = true;
        inCooldown = false;
        lastCode   = null;
        _setStatus('scanning', 'Scanning (basic mode)...');
        _updateButtons(true);
        _decodeLoop();
      }, { once: true });
    } catch (fallbackErr) {
      _setStatus('error', 'Could not access camera. Please try a different browser.');
      _updateButtons(false);
    }
  }

  // ── Private: UI helpers ────────────────────────────────────────────────────

  /**
   * Update the status element with an appropriate icon and message.
   * @param {'idle'|'loading'|'scanning'|'submitting'|'success'|'error'} state
   * @param {string} message
   */
  function _setStatus(state, message) {
    if (!statusEl) return;

    const icons = {
      idle:       '📷',
      loading:    '⏳',
      scanning:   '🔍',
      submitting: '📡',
      success:    '✅',
      error:      '❌',
    };

    const colours = {
      idle:       'var(--color-text-secondary)',
      loading:    'var(--color-warning)',
      scanning:   'var(--color-accent)',
      submitting: 'var(--color-accent)',
      success:    'var(--color-success)',
      error:      'var(--color-error)',
    };

    statusEl.textContent = `${icons[state] || ''} ${message}`;
    statusEl.style.color = colours[state] || 'inherit';
    statusEl.setAttribute('data-state', state);
  }

  /**
   * Toggle start/stop button visibility.
   * @param {boolean} isScanning
   */
  function _updateButtons(isScanning) {
    if (startBtn) {
      startBtn.disabled = isScanning;
      startBtn.style.display = isScanning ? 'none' : 'inline-flex';
    }
    if (stopBtn) {
      stopBtn.style.display = isScanning ? 'inline-flex' : 'none';
    }
  }

  /**
   * Show or hide the scanner overlay frame (the animated corners).
   * @param {boolean} visible
   */
  function _showOverlay(visible) {
    if (overlay) {
      overlay.style.display = visible ? 'block' : 'none';
    }
  }

  /**
   * Show a brief toast notification at the bottom of the screen.
   * Removes itself after 4 seconds.
   *
   * @param {'success'|'error'|'info'} type
   * @param {string} message
   */
  function _showToast(type, message) {
    // Remove any existing toast
    const existing = document.getElementById('qr-toast');
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.id    = 'qr-toast';
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');

    const bgMap = {
      success: 'var(--color-success)',
      error:   'var(--color-error)',
      info:    'var(--color-accent)',
    };

    Object.assign(toast.style, {
      position:     'fixed',
      bottom:       '24px',
      left:         '50%',
      transform:    'translateX(-50%)',
      background:   bgMap[type] || bgMap.info,
      color:        '#ffffff',
      padding:      '12px 24px',
      borderRadius: '8px',
      fontSize:     '14px',
      fontWeight:   '500',
      maxWidth:     '90vw',
      textAlign:    'center',
      zIndex:       '9999',
      boxShadow:    '0 4px 16px rgba(0,0,0,0.2)',
      transition:   'opacity 0.3s ease',
      opacity:      '0',
    });

    toast.textContent = message;
    document.body.appendChild(toast);

    // Fade in
    requestAnimationFrame(() => { toast.style.opacity = '1'; });

    // Fade out and remove after 4 seconds
    setTimeout(() => {
      toast.style.opacity = '0';
      setTimeout(() => toast.remove(), 300);
    }, 4000);
  }

  // ── Public API ─────────────────────────────────────────────────────────────
  return { init, start, stop };

})();