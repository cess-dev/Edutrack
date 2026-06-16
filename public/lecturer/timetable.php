<?php
/**
 * EduTrack — Lecturer Timetable Page
 *
 * Three-step flow:
 *   1. Upload  — lecturer uploads an image/PDF/CSV of their timetable
 *   2. Review  — AI-extracted slots shown in an editable table for confirmation
 *   3. Schedule — confirmed weekly grid view + notification toggle
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

Auth::startSession();
Auth::requireRole('lecturer');

$user = Auth::user();

// Check for confirmed timetable
$confirmedTimetable = DB::row(
    "SELECT id, original_filename, confirmed_at, academic_year, semester
     FROM timetables
     WHERE lecturer_id = ? AND extraction_status = 'confirmed'
     ORDER BY confirmed_at DESC LIMIT 1",
    [$user['id']]
);

$confirmedSchedule = [];
if ($confirmedTimetable) {
    $confirmedSchedule = DB::rows(
        "SELECT id, unit_code, unit_name, day_of_week, start_time, end_time, room
         FROM class_schedules
         WHERE timetable_id = ?
         ORDER BY day_of_week, start_time",
        [$confirmedTimetable['id']]
    );
}

// Notification preferences
$notifPrefs = DB::row(
    "SELECT email_enabled, notify_before_minutes FROM notification_preferences WHERE lecturer_id = ?",
    [$user['id']]
) ?? ['email_enabled' => 0, 'notify_before_minutes' => 30];

$lecturerEmail = $user['email'] ?? '';

$days = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

// Group schedule by day for the weekly grid
$byDay = [];
foreach ($confirmedSchedule as $slot) {
    $byDay[$slot['day_of_week']][] = $slot;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Timetable — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/base.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/lecturer.css">
  <style>
    .tt-section   { margin-bottom: var(--space-8); }
    .upload-zone  {
      border: 2px dashed var(--color-border);
      border-radius: var(--radius-lg);
      padding: var(--space-10);
      text-align: center;
      cursor: pointer;
      transition: border-color .2s, background .2s;
      background: var(--color-surface);
    }
    .upload-zone.drag-over {
      border-color: var(--color-accent);
      background: var(--color-accent-subtle, #f0fdf4);
    }
    .upload-zone input[type=file] { display: none; }
    .upload-icon  { font-size: 48px; margin-bottom: var(--space-3); }
    .upload-label { font-size: var(--text-lg); font-weight: 600; color: var(--color-text); }
    .upload-hint  { font-size: var(--text-sm); color: var(--color-text-muted); margin-top: var(--space-2); }
    .upload-btn   { margin-top: var(--space-4); }

    /* Review table */
    .review-table { width: 100%; border-collapse: collapse; font-size: var(--text-sm); }
    .review-table th {
      background: var(--color-surface-raised);
      padding: var(--space-2) var(--space-3);
      text-align: left;
      font-weight: 600;
      border-bottom: 2px solid var(--color-border);
    }
    .review-table td {
      padding: var(--space-2) var(--space-3);
      border-bottom: 1px solid var(--color-border);
      vertical-align: middle;
    }
    .review-table input[type=text], .review-table select {
      width: 100%;
      padding: var(--space-1) var(--space-2);
      border: 1px solid var(--color-border);
      border-radius: var(--radius-sm);
      font-size: var(--text-sm);
      background: var(--color-surface);
      color: var(--color-text);
    }
    .review-table tr:hover td { background: var(--color-surface-raised); }
    .btn-remove { background: none; border: none; color: var(--color-danger, #dc2626); cursor: pointer; font-size: 18px; padding: 0 4px; }

    /* Weekly grid */
    .week-grid   { display: grid; grid-template-columns: repeat(5, 1fr); gap: var(--space-3); }
    @media (max-width: 768px) { .week-grid { grid-template-columns: 1fr; } }
    .day-col     { min-width: 0; }
    .day-header  {
      font-weight: 700;
      font-size: var(--text-sm);
      text-transform: uppercase;
      letter-spacing: .05em;
      color: var(--color-text-muted);
      padding: var(--space-2) 0;
      border-bottom: 2px solid var(--color-border);
      margin-bottom: var(--space-2);
    }
    .class-card  {
      background: var(--color-accent-subtle, #f0fdf4);
      border-left: 3px solid var(--color-accent);
      border-radius: var(--radius-sm);
      padding: var(--space-2) var(--space-3);
      margin-bottom: var(--space-2);
      font-size: var(--text-sm);
    }
    .class-card .unit-code  { font-weight: 700; color: var(--color-accent); }
    .class-card .class-time { color: var(--color-text-muted); font-size: var(--text-xs); }
    .class-card .class-room { color: var(--color-text-muted); font-size: var(--text-xs); }
    .no-classes { color: var(--color-text-muted); font-size: var(--text-xs); padding: var(--space-2) 0; }

    /* Notification panel */
    .notif-panel {
      display: flex;
      align-items: center;
      gap: var(--space-4);
      flex-wrap: wrap;
      padding: var(--space-4);
      background: var(--color-surface-raised);
      border-radius: var(--radius-md);
      border: 1px solid var(--color-border);
    }
    .toggle-wrap { display: flex; align-items: center; gap: var(--space-2); }
    .toggle { position: relative; width: 44px; height: 24px; }
    .toggle input { opacity: 0; width: 0; height: 0; }
    .toggle-slider {
      position: absolute; inset: 0;
      background: var(--color-border);
      border-radius: 24px;
      cursor: pointer;
      transition: background .2s;
    }
    .toggle-slider:before {
      content: '';
      position: absolute;
      width: 18px; height: 18px;
      left: 3px; bottom: 3px;
      background: #fff;
      border-radius: 50%;
      transition: transform .2s;
    }
    .toggle input:checked + .toggle-slider { background: var(--color-accent); }
    .toggle input:checked + .toggle-slider:before { transform: translateX(20px); }
    .notif-label { font-weight: 600; font-size: var(--text-sm); }
    .notif-minutes {
      display: flex; align-items: center; gap: var(--space-2);
      font-size: var(--text-sm); color: var(--color-text-muted);
    }
    .notif-minutes input {
      width: 64px;
      padding: var(--space-1) var(--space-2);
      border: 1px solid var(--color-border);
      border-radius: var(--radius-sm);
      font-size: var(--text-sm);
      background: var(--color-surface);
      color: var(--color-text);
      text-align: center;
    }

    /* Step indicator */
    .steps { display: flex; gap: var(--space-2); margin-bottom: var(--space-6); flex-wrap: wrap; }
    .step  {
      display: flex; align-items: center; gap: var(--space-2);
      font-size: var(--text-sm); color: var(--color-text-muted);
    }
    .step-num {
      width: 24px; height: 24px;
      border-radius: 50%;
      background: var(--color-border);
      color: var(--color-text-muted);
      display: grid; place-items: center;
      font-size: var(--text-xs); font-weight: 700;
    }
    .step.active .step-num { background: var(--color-accent); color: #fff; }
    .step.active           { color: var(--color-text); font-weight: 600; }
    .step.done .step-num   { background: var(--color-success, #16a34a); color: #fff; }
    .step-sep { color: var(--color-border); }

    /* Progress bar */
    #upload-progress { display: none; margin-top: var(--space-4); }
    .progress-bar { height: 6px; background: var(--color-border); border-radius: 3px; overflow: hidden; }
    .progress-fill { height: 100%; background: var(--color-accent); width: 0; transition: width .3s; border-radius: 3px; }
  </style>
</head>
<body>
<div class="layout">
  <?php require_once __DIR__ . '/../partials/sidebar_lecturer.php'; ?>

  <main class="main-content">
    <div class="page-header">
      <h1 class="page-title">Timetable</h1>
      <p class="page-subtitle">Upload your class schedule and get email reminders before each class</p>
    </div>

    <!-- ── Notification preferences ──────────────────────────────────────── -->
    <?php if ($confirmedTimetable): ?>
    <div class="card tt-section">
      <div class="card-header">
        <h2 class="card-title">Class Reminders</h2>
      </div>
      <div class="card-body">
        <?php if (empty($lecturerEmail)): ?>
          <div class="alert alert-warning">
            Add an email address to your <a href="<?= BASE_URL ?>/lecturer/profile">profile</a> to enable class reminders.
          </div>
        <?php else: ?>
        <div class="notif-panel">
          <label class="toggle-wrap">
            <label class="toggle">
              <input type="checkbox" id="email-toggle"
                     <?= $notifPrefs['email_enabled'] ? 'checked' : '' ?>>
              <span class="toggle-slider"></span>
            </label>
            <span class="notif-label">Email me before class</span>
          </label>

          <div class="notif-minutes" id="notif-timing"
               style="<?= $notifPrefs['email_enabled'] ? '' : 'opacity:.4;pointer-events:none' ?>">
            <span>Notify me</span>
            <input type="number" id="notif-minutes" min="5" max="1440"
                   value="<?= (int)$notifPrefs['notify_before_minutes'] ?>">
            <span>minutes before class</span>
          </div>

          <button class="btn btn-sm btn-primary" id="save-prefs-btn" style="margin-left:auto">
            Save Preferences
          </button>
        </div>
        <p id="prefs-status" style="margin-top:var(--space-2);font-size:var(--text-sm)"></p>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Weekly schedule grid ───────────────────────────────────────────── -->
    <?php if ($confirmedTimetable): ?>
    <div class="card tt-section">
      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
        <div>
          <h2 class="card-title">Weekly Schedule</h2>
          <p style="font-size:var(--text-xs);color:var(--color-text-muted);margin:0">
            From: <?= htmlspecialchars($confirmedTimetable['original_filename']) ?>
            &nbsp;·&nbsp; Confirmed: <?= date('d M Y', strtotime($confirmedTimetable['confirmed_at'])) ?>
            &nbsp;·&nbsp; <?= htmlspecialchars($confirmedTimetable['academic_year']) ?> Sem <?= (int)$confirmedTimetable['semester'] ?>
          </p>
        </div>
        <button class="btn btn-sm btn-outline" id="replace-timetable-btn">Replace Timetable</button>
      </div>
      <div class="card-body">
        <div class="week-grid">
          <?php for ($d = 1; $d <= 5; $d++): ?>
          <div class="day-col">
            <div class="day-header"><?= $days[$d] ?></div>
            <?php if (!empty($byDay[$d])): ?>
              <?php foreach ($byDay[$d] as $slot): ?>
              <div class="class-card">
                <div class="unit-code"><?= htmlspecialchars($slot['unit_code']) ?></div>
                <?php if ($slot['unit_name']): ?>
                <div style="font-size:var(--text-xs);color:var(--color-text);margin:2px 0">
                  <?= htmlspecialchars($slot['unit_name']) ?>
                </div>
                <?php endif; ?>
                <div class="class-time">
                  <?= date('g:i A', strtotime($slot['start_time'])) ?>
                  – <?= date('g:i A', strtotime($slot['end_time'])) ?>
                </div>
                <?php if ($slot['room']): ?>
                <div class="class-room">📍 <?= htmlspecialchars($slot['room']) ?></div>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="no-classes">No classes</div>
            <?php endif; ?>
          </div>
          <?php endfor; ?>
        </div>
        <?php
        // Show Sat/Sun only if there are weekend classes
        $hasWeekend = !empty($byDay[6]) || !empty($byDay[7]);
        if ($hasWeekend): ?>
        <div class="week-grid" style="grid-template-columns:repeat(2,1fr);margin-top:var(--space-4)">
          <?php foreach ([6, 7] as $d): ?>
          <div class="day-col">
            <div class="day-header"><?= $days[$d] ?></div>
            <?php if (!empty($byDay[$d])): ?>
              <?php foreach ($byDay[$d] as $slot): ?>
              <div class="class-card">
                <div class="unit-code"><?= htmlspecialchars($slot['unit_code']) ?></div>
                <div class="class-time">
                  <?= date('g:i A', strtotime($slot['start_time'])) ?>
                  – <?= date('g:i A', strtotime($slot['end_time'])) ?>
                </div>
                <?php if ($slot['room']): ?>
                <div class="class-room">📍 <?= htmlspecialchars($slot['room']) ?></div>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="no-classes">No classes</div>
            <?php endif; ?>
          </div>
          <?php endfor; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Upload section ────────────────────────────────────────────────── -->
    <div class="card tt-section" id="upload-section"
         style="<?= $confirmedTimetable ? 'display:none' : '' ?>">
      <div class="card-header">
        <h2 class="card-title">
          <?= $confirmedTimetable ? 'Replace Timetable' : 'Upload Timetable' ?>
        </h2>
      </div>
      <div class="card-body">

        <!-- Step indicator -->
        <div class="steps">
          <div class="step active" id="step-upload">
            <div class="step-num">1</div>
            <span>Upload file</span>
          </div>
          <div class="step-sep">›</div>
          <div class="step" id="step-review">
            <div class="step-num">2</div>
            <span>Review extraction</span>
          </div>
          <div class="step-sep">›</div>
          <div class="step" id="step-done">
            <div class="step-num">3</div>
            <span>Confirm</span>
          </div>
        </div>

        <!-- Upload zone (Step 1) -->
        <div id="zone-upload">
          <div class="upload-zone" id="drop-zone">
            <input type="file" id="timetable-file"
                   accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.csv">
            <div class="upload-icon">📅</div>
            <div class="upload-label">Drop your timetable here or click to browse</div>
            <div class="upload-hint">
              Supported: JPG, PNG, WEBP (image) · PDF (text-based) · CSV<br>
              Maximum size: <?= round(MAX_TIMETABLE_SIZE_BYTES / 1048576) ?> MB
            </div>
            <?php if (!VISION_ENABLED): ?>
            <div class="alert alert-warning" style="margin-top:var(--space-4);text-align:left">
              Vision model is not enabled. CSV uploads will still work.<br>
              Set <code>VISION_ENABLED=true</code> and configure <code>VISION_MODEL_NAME</code> in your <code>.env</code> to enable image/PDF extraction.
            </div>
            <?php endif; ?>
          </div>

          <div id="upload-progress">
            <p id="upload-status" style="font-size:var(--text-sm);color:var(--color-text-muted);margin-bottom:var(--space-2)">
              Uploading and extracting…
            </p>
            <div class="progress-bar"><div class="progress-fill" id="progress-fill"></div></div>
          </div>
          <div id="upload-error" class="alert alert-danger" style="display:none;margin-top:var(--space-4)"></div>
        </div>

        <!-- Review zone (Step 2) -->
        <div id="zone-review" style="display:none">
          <div class="alert alert-info" style="margin-bottom:var(--space-4)">
            Review the extracted schedule below. You can edit any row before confirming.
            Rows with a unit code matching your assigned units will be highlighted.
          </div>

          <div style="overflow-x:auto">
            <table class="review-table" id="review-table">
              <thead>
                <tr>
                  <th>Day</th>
                  <th>Unit Code</th>
                  <th>Unit Name</th>
                  <th>Start</th>
                  <th>End</th>
                  <th>Room</th>
                  <th></th>
                </tr>
              </thead>
              <tbody id="review-body"></tbody>
            </table>
          </div>

          <div style="margin-top:var(--space-4);display:flex;gap:var(--space-3);align-items:center;flex-wrap:wrap">
            <button class="btn btn-sm btn-outline" id="add-row-btn">+ Add Row</button>
            <div style="margin-left:auto;display:flex;gap:var(--space-3)">
              <button class="btn btn-sm btn-outline" id="back-to-upload-btn">← Start Over</button>
              <button class="btn btn-primary" id="confirm-btn">Confirm &amp; Save Schedule</button>
            </div>
          </div>

          <div id="review-error" class="alert alert-danger" style="display:none;margin-top:var(--space-3)"></div>
          <div id="review-success" class="alert alert-success" style="display:none;margin-top:var(--space-3)"></div>
        </div>

      </div>
    </div>

  </main>
</div>

<?php require_once __DIR__ . '/../partials/lecturer_ai_widget.php'; ?>

<script>
(function () {
  const DAYS = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
  let currentTimetableId = null;

  // ── Drag-and-drop + file pick ───────────────────────────────────────────────
  const dropZone  = document.getElementById('drop-zone');
  const fileInput = document.getElementById('timetable-file');

  dropZone.addEventListener('click', () => fileInput.click());

  dropZone.addEventListener('dragover', e => {
    e.preventDefault();
    dropZone.classList.add('drag-over');
  });
  dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
  dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('drag-over');
    if (e.dataTransfer.files.length) handleFile(e.dataTransfer.files[0]);
  });

  fileInput.addEventListener('change', () => {
    if (fileInput.files.length) handleFile(fileInput.files[0]);
  });

  // ── Upload & extract ────────────────────────────────────────────────────────
  function handleFile(file) {
    const maxBytes = <?= MAX_TIMETABLE_SIZE_BYTES ?>;
    if (file.size > maxBytes) {
      showUploadError(`File too large. Maximum is ${Math.round(maxBytes / 1048576)} MB.`);
      return;
    }

    const formData = new FormData();
    formData.append('timetable', file);

    document.getElementById('upload-progress').style.display = 'block';
    document.getElementById('upload-error').style.display    = 'none';
    animateProgress();

    const statusEl = document.getElementById('upload-status');
    const mimeType = file.type;
    if (mimeType.startsWith('image/')) {
      statusEl.textContent = 'Sending to vision model — this may take 30–60 seconds…';
    } else if (mimeType === 'application/pdf') {
      statusEl.textContent = 'Extracting text from PDF…';
    } else {
      statusEl.textContent = 'Parsing CSV…';
    }

    fetch('<?= BASE_URL ?>/api/timetable/upload', {
      method: 'POST',
      body: formData,
    })
      .then(r => r.json())
      .then(data => {
        document.getElementById('upload-progress').style.display = 'none';
        if (!data.success) {
          showUploadError(data.error || 'Extraction failed.');
          return;
        }
        currentTimetableId = data.timetable_id;
        showReviewStep(data.slots);
      })
      .catch(() => {
        document.getElementById('upload-progress').style.display = 'none';
        showUploadError('Network error. Please try again.');
      });
  }

  function animateProgress() {
    const fill = document.getElementById('progress-fill');
    let pct = 0;
    const iv = setInterval(() => {
      pct = pct < 85 ? pct + (Math.random() * 3) : pct;
      fill.style.width = pct + '%';
    }, 400);
    fill._clearAnim = () => { clearInterval(iv); fill.style.width = '100%'; };
  }

  function showUploadError(msg) {
    const el = document.getElementById('upload-error');
    el.textContent = msg;
    el.style.display = 'block';
    const fill = document.getElementById('progress-fill');
    if (fill._clearAnim) fill._clearAnim();
  }

  // ── Review step ─────────────────────────────────────────────────────────────
  function showReviewStep(slots) {
    document.getElementById('zone-upload').style.display = 'none';
    document.getElementById('zone-review').style.display = 'block';
    setStep(2);
    renderReviewTable(slots);
  }

  function renderReviewTable(slots) {
    const tbody = document.getElementById('review-body');
    tbody.innerHTML = '';
    slots.forEach(slot => addReviewRow(slot));
  }

  function addReviewRow(slot) {
    const tbody = document.getElementById('review-body');
    const tr = document.createElement('tr');

    // Day select
    let dayOptions = DAYS.slice(1).map((d, i) =>
      `<option value="${i + 1}" ${slot.day_of_week == i + 1 ? 'selected' : ''}>${d}</option>`
    ).join('');

    tr.innerHTML = `
      <td><select name="day">${dayOptions}</select></td>
      <td><input type="text" name="unit_code" value="${esc(slot.unit_code)}" placeholder="CS101" required></td>
      <td><input type="text" name="unit_name" value="${esc(slot.unit_name || '')}" placeholder="Unit name (optional)"></td>
      <td><input type="text" name="start_time" value="${esc(slot.start_time)}" placeholder="08:00" pattern="\\d{2}:\\d{2}" required></td>
      <td><input type="text" name="end_time"   value="${esc(slot.end_time)}"   placeholder="10:00" pattern="\\d{2}:\\d{2}" required></td>
      <td><input type="text" name="room"       value="${esc(slot.room || '')}"  placeholder="Room (optional)"></td>
      <td><button type="button" class="btn-remove" title="Remove row">✕</button></td>
    `;

    tr.querySelector('.btn-remove').addEventListener('click', () => tr.remove());
    tbody.appendChild(tr);
  }

  // Add empty row
  document.getElementById('add-row-btn').addEventListener('click', () => {
    addReviewRow({ day_of_week: 1, unit_code: '', unit_name: '', start_time: '', end_time: '', room: '' });
  });

  // Back to upload
  document.getElementById('back-to-upload-btn').addEventListener('click', () => {
    document.getElementById('zone-upload').style.display = 'block';
    document.getElementById('zone-review').style.display = 'none';
    setStep(1);
    fileInput.value = '';
    currentTimetableId = null;
  });

  // ── Confirm & save ───────────────────────────────────────────────────────────
  document.getElementById('confirm-btn').addEventListener('click', function () {
    const rows  = document.querySelectorAll('#review-body tr');
    const slots = [];
    let   valid = true;

    rows.forEach(tr => {
      const code  = tr.querySelector('[name=unit_code]').value.trim();
      const start = tr.querySelector('[name=start_time]').value.trim();
      const end   = tr.querySelector('[name=end_time]').value.trim();
      const day   = parseInt(tr.querySelector('[name=day]').value, 10);

      if (!code || !start || !end || !day) { valid = false; return; }
      if (!/^\d{2}:\d{2}$/.test(start) || !/^\d{2}:\d{2}$/.test(end)) { valid = false; return; }

      slots.push({
        day_of_week: day,
        unit_code:   code,
        unit_name:   tr.querySelector('[name=unit_name]').value.trim() || null,
        start_time:  start,
        end_time:    end,
        room:        tr.querySelector('[name=room]').value.trim() || null,
      });
    });

    if (!valid || slots.length === 0) {
      showReviewError('Please fill all required fields (Day, Unit Code, Start, End) in HH:MM format.');
      return;
    }

    this.disabled = true;
    this.textContent = 'Saving…';

    fetch('<?= BASE_URL ?>/api/timetable/save', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ timetable_id: currentTimetableId, slots }),
    })
      .then(r => r.json())
      .then(data => {
        if (!data.success) {
          showReviewError(data.error || 'Save failed.');
          this.disabled = false;
          this.textContent = 'Confirm & Save Schedule';
          return;
        }
        // Reload to show the schedule grid
        window.location.reload();
      })
      .catch(() => {
        showReviewError('Network error. Please try again.');
        this.disabled = false;
        this.textContent = 'Confirm & Save Schedule';
      });
  });

  function showReviewError(msg) {
    const el = document.getElementById('review-error');
    el.textContent = msg;
    el.style.display = 'block';
    document.getElementById('review-success').style.display = 'none';
  }

  // ── Step indicator ───────────────────────────────────────────────────────────
  function setStep(n) {
    ['step-upload', 'step-review', 'step-done'].forEach((id, i) => {
      const el = document.getElementById(id);
      el.className = 'step' + (i + 1 === n ? ' active' : i + 1 < n ? ' done' : '');
    });
  }

  // ── Replace timetable button ─────────────────────────────────────────────────
  const replaceBtn = document.getElementById('replace-timetable-btn');
  if (replaceBtn) {
    replaceBtn.addEventListener('click', () => {
      document.getElementById('upload-section').style.display = 'block';
      replaceBtn.scrollIntoView({ behavior: 'smooth' });
    });
  }

  // ── Notification preferences ─────────────────────────────────────────────────
  const emailToggle  = document.getElementById('email-toggle');
  const notifTiming  = document.getElementById('notif-timing');
  const savePrefsBtn = document.getElementById('save-prefs-btn');
  const prefsStatus  = document.getElementById('prefs-status');

  if (emailToggle) {
    emailToggle.addEventListener('change', () => {
      notifTiming.style.opacity        = emailToggle.checked ? '1' : '0.4';
      notifTiming.style.pointerEvents  = emailToggle.checked ? '' : 'none';
    });

    savePrefsBtn.addEventListener('click', () => {
      const payload = {
        email_enabled:         emailToggle.checked,
        notify_before_minutes: parseInt(document.getElementById('notif-minutes').value, 10) || 30,
      };

      savePrefsBtn.disabled     = true;
      savePrefsBtn.textContent  = 'Saving…';
      prefsStatus.textContent   = '';
      prefsStatus.style.color   = '';

      fetch('<?= BASE_URL ?>/api/timetable/preferences', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      })
        .then(r => r.json())
        .then(data => {
          savePrefsBtn.disabled    = false;
          savePrefsBtn.textContent = 'Save Preferences';
          if (data.success) {
            prefsStatus.textContent  = 'Preferences saved.';
            prefsStatus.style.color  = 'var(--color-success, #16a34a)';
          } else {
            prefsStatus.textContent  = data.error || 'Save failed.';
            prefsStatus.style.color  = 'var(--color-danger, #dc2626)';
          }
        })
        .catch(() => {
          savePrefsBtn.disabled    = false;
          savePrefsBtn.textContent = 'Save Preferences';
          prefsStatus.textContent  = 'Network error.';
          prefsStatus.style.color  = 'var(--color-danger, #dc2626)';
        });
    });
  }

  // ── Utility ──────────────────────────────────────────────────────────────────
  function esc(str) {
    return String(str ?? '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
})();
</script>
</body>
</html>
