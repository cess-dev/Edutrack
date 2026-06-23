<?php
defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

Auth::startSession();
Auth::requireRole('lecturer');

$user = Auth::user();
$csrfToken = Auth::csrfToken();

$academicYear = DB::row("SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year'")['setting_value'] ?? ACADEMIC_YEAR;
$semester     = (int)(DB::row("SELECT setting_value FROM system_settings WHERE setting_key = 'active_semester'")['setting_value'] ?? ACTIVE_SEMESTER);

$units = DB::rows(
    "SELECT id, code, name FROM units WHERE lecturer_id = ? AND is_active = 1 ORDER BY code",
    [$user['id']]
);

$examHistory = [];
foreach ($units as $unit) {
    $uploads = DB::rows(
        "SELECT eu.id, eu.exam_title, eu.avg_score, eu.most_failed, eu.observations,
                eu.analysis_status, eu.uploaded_at, eu.analyzed_at, eu.original_filename
         FROM exam_uploads eu
         WHERE eu.unit_id = ? AND eu.lecturer_id = ?
         ORDER BY eu.uploaded_at DESC",
        [$unit['id'], $user['id']]
    );
    foreach ($uploads as &$u) {
        $insight = DB::row("SELECT id, ai_summary, ai_comparisons FROM exam_insights WHERE upload_id = ?", [$u['id']]);
        $u['insight'] = $insight;
        $u['suggestions'] = $insight
            ? DB::rows("SELECT id, suggestion_text, category, status, follow_up_notes, executed_at FROM exam_suggestions WHERE insight_id = ? ORDER BY id", [$insight['id']])
            : [];
    }
    unset($u);
    $examHistory[$unit['id']] = $uploads;
}
?>
<!DOCTYPE html>
<html lang="en" data-base-url="<?= htmlspecialchars(BASE_URL) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
  <title>Exam Insights — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/base.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/lecturer.css">
  <style>
    .unit-tabs { display:flex; gap:var(--space-2); margin-bottom:var(--space-6); flex-wrap:wrap; overflow-x:auto; }
    .unit-tab {
      padding:var(--space-2) var(--space-4); border-radius:var(--radius-full,9999px);
      border:1px solid var(--color-border); background:var(--color-surface);
      color:var(--color-text); text-decoration:none; font-size:var(--text-sm);
      font-weight:500; cursor:pointer; white-space:nowrap;
    }
    .unit-tab.active { background:var(--color-accent); border-color:var(--color-accent); color:#fff; }
    .unit-tab:hover:not(.active) { border-color:var(--color-accent); }
    .unit-panel { display:none; }
    .unit-panel.active { display:block; }

    .upload-zone {
      border:2px dashed var(--color-border); border-radius:var(--radius-lg);
      padding:var(--space-8); text-align:center; cursor:pointer;
      transition:border-color .2s,background .2s; background:var(--color-surface);
    }
    .upload-zone.drag-over { border-color:var(--color-accent); background:var(--color-accent-subtle,#f0fdf4); }
    .upload-zone input[type=file] { display:none; }
    .upload-icon { font-size:36px; margin-bottom:var(--space-2); }

    .notes-form { display:grid; gap:var(--space-4); margin-top:var(--space-4); }
    .notes-form label { font-weight:600; font-size:var(--text-sm); display:block; margin-bottom:var(--space-1); }
    .notes-form input, .notes-form textarea {
      width:100%; padding:var(--space-2) var(--space-3);
      border:1px solid var(--color-border); border-radius:var(--radius-sm);
      font-size:var(--text-sm); background:var(--color-surface); color:var(--color-text);
    }
    .notes-form textarea { min-height:60px; resize:vertical; }
    .form-row { display:grid; grid-template-columns:1fr 1fr; gap:var(--space-4); }
    @media(max-width:640px) { .form-row { grid-template-columns:1fr; } }

    .insight-card { background:var(--color-surface-raised); border-radius:var(--radius-md); padding:var(--space-4); border:1px solid var(--color-border); margin-top:var(--space-4); }
    .insight-summary { font-size:var(--text-sm); line-height:1.6; margin-bottom:var(--space-4); }
    .suggestion-item {
      display:flex; align-items:flex-start; gap:var(--space-3);
      padding:var(--space-3); border:1px solid var(--color-border);
      border-radius:var(--radius-sm); margin-bottom:var(--space-2);
      background:var(--color-surface); font-size:var(--text-sm);
    }
    .suggestion-item.executed { border-left:3px solid var(--color-success,#16a34a); opacity:.8; }
    .suggestion-item.dismissed { border-left:3px solid var(--color-text-muted); opacity:.6; }
    .sugg-body { flex:1; }
    .sugg-cat { font-size:var(--text-xs); color:var(--color-text-muted); text-transform:uppercase; letter-spacing:.05em; }
    .sugg-actions { display:flex; gap:var(--space-2); margin-top:var(--space-2); flex-wrap:wrap; }
    .sugg-actions button { font-size:var(--text-xs); }
    .sugg-followup { margin-top:var(--space-2); }
    .sugg-followup textarea {
      width:100%; padding:var(--space-1) var(--space-2); border:1px solid var(--color-border);
      border-radius:var(--radius-sm); font-size:var(--text-xs); min-height:36px; resize:vertical;
    }
    .status-badge {
      font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em;
      padding:2px 8px; border-radius:var(--radius-full,9999px); display:inline-block;
    }
    .status-pending  { background:#fef3c7; color:#92400e; }
    .status-executed { background:#d1fae5; color:#065f46; }
    .status-dismissed { background:#e5e7eb; color:#6b7280; }

    .history-table { width:100%; border-collapse:collapse; font-size:var(--text-sm); }
    .history-table th { background:var(--color-surface-raised); padding:var(--space-2) var(--space-3); text-align:left; font-weight:600; border-bottom:2px solid var(--color-border); }
    .history-table td { padding:var(--space-2) var(--space-3); border-bottom:1px solid var(--color-border); vertical-align:top; }
    .history-table tr:hover td { background:var(--color-surface-raised); }

    .compare-bar { display:flex; gap:var(--space-3); align-items:center; margin-top:var(--space-3); flex-wrap:wrap; }
    .compare-result { margin-top:var(--space-4); padding:var(--space-4); background:var(--color-surface-raised); border-radius:var(--radius-md); border:1px solid var(--color-border); font-size:var(--text-sm); line-height:1.6; white-space:pre-wrap; }

    .future-ideas { margin-top:var(--space-4); padding:var(--space-3); background:#eff6ff; border-left:3px solid #3b82f6; border-radius:var(--radius-sm); font-size:var(--text-sm); line-height:1.6; }
    .empty-state { text-align:center; padding:var(--space-8); color:var(--color-text-muted); }
    .empty-icon { font-size:40px; margin-bottom:var(--space-2); }
  </style>
</head>
<body>
<div class="layout">
  <?php include __DIR__ . '/../partials/sidebar_lecturer.php'; ?>

  <div class="main">
    <header class="topbar">
      <span class="topbar-title">Exam Insights</span>
      <div class="topbar-actions">
        <span class="text-sm text-muted"><?= htmlspecialchars($academicYear) ?> · Semester <?= $semester ?></span>
      </div>
    </header>

    <div class="page-content">

    <?php if (empty($units)): ?>
      <div class="empty-state"><div class="empty-icon">📋</div><p>No units assigned to you this semester.</p></div>
    <?php else: ?>

      <!-- Unit tabs -->
      <div class="unit-tabs">
        <?php foreach ($units as $i => $unit): ?>
        <button class="unit-tab <?= $i === 0 ? 'active' : '' ?>" data-unit-id="<?= $unit['id'] ?>">
          <?= htmlspecialchars($unit['code']) ?>
        </button>
        <?php endforeach; ?>
      </div>

      <?php foreach ($units as $i => $unit):
        $history = $examHistory[$unit['id']] ?? [];
      ?>
      <div class="unit-panel <?= $i === 0 ? 'active' : '' ?>" data-unit-id="<?= $unit['id'] ?>">

        <!-- Upload card -->
        <div class="card" style="margin-bottom:var(--space-6)">
          <div class="card-header">
            <h2 class="card-title">Upload Exam — <?= htmlspecialchars($unit['code']) ?> <?= htmlspecialchars($unit['name']) ?></h2>
          </div>
          <div class="card-body">
            <div class="upload-zone" data-unit="<?= $unit['id'] ?>">
              <input type="file" accept=".pdf" class="exam-file-input">
              <div class="upload-icon">📄</div>
              <div style="font-weight:600">Drop a PDF here or click to browse</div>
              <div style="font-size:var(--text-xs);color:var(--color-text-muted);margin-top:var(--space-1)">PDF only, max <?= round(MAX_EXAM_SIZE_BYTES / 1048576) ?> MB</div>
            </div>

            <div class="notes-form" style="display:none" data-unit="<?= $unit['id'] ?>">
              <div class="alert alert-info" style="font-size:var(--text-sm)">
                <strong id="upload-filename-<?= $unit['id'] ?>"></strong> uploaded. Fill in your observations, then analyze with AI.
              </div>
              <input type="hidden" class="upload-id-input" value="">
              <div>
                <label>Exam Title</label>
                <input type="text" class="exam-title-input" placeholder="e.g. CAT 1, Final Exam 2025" required>
              </div>
              <div class="form-row">
                <div>
                  <label>Average Score (%)</label>
                  <input type="number" class="avg-score-input" min="0" max="100" step="0.1" placeholder="e.g. 54.5">
                </div>
                <div>
                  <label>Most Failed Questions/Topics</label>
                  <input type="text" class="most-failed-input" placeholder="e.g. Q3 (normalization), Q7 (joins)">
                </div>
              </div>
              <div>
                <label>Your Observations</label>
                <textarea class="observations-input" placeholder="Any notes about student performance, exam difficulty, patterns you noticed..."></textarea>
              </div>
              <div style="display:flex;gap:var(--space-3);flex-wrap:wrap">
                <button class="btn btn-outline btn-sm save-notes-btn">Save Notes</button>
                <button class="btn btn-primary btn-sm analyze-btn" disabled>Analyze with AI</button>
                <span class="notes-status" style="font-size:var(--text-xs);color:var(--color-text-muted);align-self:center"></span>
              </div>

              <div class="insight-result" style="display:none"></div>
            </div>
          </div>
        </div>

        <!-- History card -->
        <div class="card">
          <div class="card-header"><h2 class="card-title">Past Exams — <?= htmlspecialchars($unit['code']) ?></h2></div>
          <div class="card-body">
            <?php if (empty($history)): ?>
              <div class="empty-state"><div class="empty-icon">📊</div><p>No exam uploads yet for this unit.</p></div>
            <?php else: ?>
              <div style="overflow-x:auto">
              <table class="history-table">
                <thead><tr><th></th><th>Exam</th><th>Avg</th><th>Status</th><th>Date</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($history as $h): ?>
                <tr>
                  <td><input type="checkbox" class="compare-check" data-upload-id="<?= $h['id'] ?>" data-unit-id="<?= $unit['id'] ?>"></td>
                  <td><strong><?= htmlspecialchars($h['exam_title']) ?></strong><br><span style="font-size:var(--text-xs);color:var(--color-text-muted)"><?= htmlspecialchars($h['original_filename']) ?></span></td>
                  <td><?= $h['avg_score'] !== null ? round($h['avg_score'], 1) . '%' : '—' ?></td>
                  <td><span class="status-badge status-<?= $h['analysis_status'] === 'completed' ? 'executed' : ($h['analysis_status'] === 'failed' ? 'dismissed' : 'pending') ?>"><?= $h['analysis_status'] ?></span></td>
                  <td style="font-size:var(--text-xs)"><?= date('d M Y', strtotime($h['uploaded_at'])) ?></td>
                  <td style="white-space:nowrap">
                    <button class="btn btn-outline btn-sm toggle-detail-btn" data-upload-id="<?= $h['id'] ?>">View Details</button>
                    <button class="btn btn-sm delete-exam-btn" data-upload-id="<?= $h['id'] ?>" data-title="<?= htmlspecialchars($h['exam_title']) ?>" style="color:var(--color-danger,#dc2626);border:1px solid var(--color-danger,#dc2626);background:none;margin-left:4px">Delete</button>
                  </td>
                </tr>
                <tr class="detail-row" data-upload-id="<?= $h['id'] ?>" style="display:none">
                  <td colspan="6" style="padding:var(--space-4)">
                    <?php if ($h['most_failed']): ?><p style="font-size:var(--text-sm)"><strong>Failed topics:</strong> <?= htmlspecialchars($h['most_failed']) ?></p><?php endif; ?>
                    <?php if ($h['observations']): ?><p style="font-size:var(--text-sm)"><strong>Observations:</strong> <?= htmlspecialchars($h['observations']) ?></p><?php endif; ?>
                    <?php if ($h['insight']): ?>
                      <div class="insight-card">
                        <h3 style="font-size:var(--text-sm);font-weight:700;margin-bottom:var(--space-2)">AI Analysis</h3>
                        <div class="insight-summary"><?= nl2br(htmlspecialchars($h['insight']['ai_summary'])) ?></div>
                        <?php foreach ($h['suggestions'] as $s): ?>
                        <div class="suggestion-item <?= $s['status'] ?>">
                          <div class="sugg-body">
                            <div class="sugg-cat"><?= htmlspecialchars($s['category']) ?></div>
                            <div><?= htmlspecialchars($s['suggestion_text']) ?></div>
                            <?php if ($s['status'] !== 'pending'): ?>
                              <span class="status-badge status-<?= $s['status'] ?>"><?= $s['status'] ?></span>
                              <?php if ($s['follow_up_notes']): ?><div style="font-size:var(--text-xs);color:var(--color-text-muted);margin-top:var(--space-1)">Note: <?= htmlspecialchars($s['follow_up_notes']) ?></div><?php endif; ?>
                            <?php else: ?>
                              <div class="sugg-actions">
                                <button class="btn btn-sm btn-outline exec-sugg-btn" data-id="<?= $s['id'] ?>" data-action="execute">Mark Executed</button>
                                <button class="btn btn-sm btn-ghost dismiss-sugg-btn" data-id="<?= $s['id'] ?>" data-action="dismiss">Dismiss</button>
                              </div>
                              <div class="sugg-followup">
                                <textarea placeholder="Follow-up notes (optional)..." class="followup-input" data-id="<?= $s['id'] ?>"></textarea>
                              </div>
                            <?php endif; ?>
                          </div>
                        </div>
                        <?php endforeach; ?>
                      </div>
                    <?php elseif ($h['analysis_status'] === 'failed'): ?>
                      <p style="color:var(--color-danger,#dc2626);font-size:var(--text-sm)">Analysis failed. You can re-upload and try again.</p>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
              </div>
              <div class="compare-bar">
                <button class="btn btn-sm btn-outline compare-btn" data-unit-id="<?= $unit['id'] ?>" disabled>Compare Selected (0/2)</button>
                <span class="compare-status" style="font-size:var(--text-xs);color:var(--color-text-muted)"></span>
              </div>
              <div class="compare-result" data-unit-id="<?= $unit['id'] ?>" style="display:none"></div>
            <?php endif; ?>
          </div>
        </div>

      </div>
      <?php endforeach; ?>

    <?php endif; ?>

    </div><!-- /page-content -->
  </div><!-- /main -->
</div>

<!-- Custom modal overlay -->
<div id="app-modal-overlay">
  <div id="app-modal">
    <div id="app-modal-icon"></div>
    <h3 id="app-modal-title"></h3>
    <p id="app-modal-body"></p>
    <div id="app-modal-actions"></div>
  </div>
</div>
<style>
  #app-modal-overlay {
    display:none; position:fixed; inset:0; z-index:9999;
    align-items:center; justify-content:center; padding:24px;
    backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px);
    background:rgba(15,23,42,.6);
  }
  #app-modal-overlay.visible { display:flex; }
  #app-modal {
    background:#fff; border-radius:16px; max-width:440px; width:100%;
    padding:32px; box-shadow:0 24px 80px rgba(0,0,0,.35), 0 0 0 1px rgba(0,0,0,.08);
    animation:modalIn .25s ease;
  }
  #app-modal-icon { text-align:center; font-size:44px; margin-bottom:12px; }
  #app-modal-title { font-size:18px; font-weight:700; text-align:center; margin-bottom:8px; color:#0f172a; }
  #app-modal-body { font-size:14px; color:#475569; text-align:center; line-height:1.65; margin-bottom:24px; }
  #app-modal-actions { display:flex; gap:10px; justify-content:center; }
  #app-modal-actions button {
    padding:10px 24px; border-radius:8px; font-size:14px; font-weight:600;
    cursor:pointer; border:none; transition:transform .1s, box-shadow .15s;
  }
  #app-modal-actions button:active { transform:scale(.97); }
  #app-modal-actions .modal-btn-cancel {
    background:#f1f5f9; color:#334155; border:1px solid #e2e8f0;
  }
  #app-modal-actions .modal-btn-cancel:hover { background:#e2e8f0; }
  #app-modal-actions .modal-btn-primary {
    background:var(--color-accent, #0f7b6c); color:#fff;
    box-shadow:0 2px 8px rgba(15,123,108,.3);
  }
  #app-modal-actions .modal-btn-primary:hover { box-shadow:0 4px 16px rgba(15,123,108,.4); }
  #app-modal-actions .modal-btn-danger {
    background:#dc2626; color:#fff;
    box-shadow:0 2px 8px rgba(220,38,38,.3);
  }
  #app-modal-actions .modal-btn-danger:hover { background:#b91c1c; }
  @keyframes modalIn { from { opacity:0; transform:scale(.92) translateY(12px); } to { opacity:1; transform:scale(1) translateY(0); } }
  @media(max-width:480px) { #app-modal { margin:12px; padding:24px 20px; } }
</style>

<?php include __DIR__ . '/../partials/lecturer_ai_widget.php'; ?>

<script>
(function() {
  const BASE = document.documentElement.dataset.baseUrl || '';
  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
  const headers = () => ({ 'Content-Type':'application/json', 'X-CSRF-Token': csrf() });
  const unitCodes = <?= json_encode(array_column($units, 'code', 'id')) ?>;

  // ── Custom modal helpers ─────────────────────────────────────────────────────
  const modalOverlay = document.getElementById('app-modal-overlay');
  const modalIcon    = document.getElementById('app-modal-icon');
  const modalTitle   = document.getElementById('app-modal-title');
  const modalBody    = document.getElementById('app-modal-body');
  const modalActions = document.getElementById('app-modal-actions');

  function showModal({ icon, title, body, buttons }) {
    modalIcon.textContent = icon || '';
    modalTitle.textContent = title || '';
    modalBody.innerHTML = body || '';
    modalActions.innerHTML = '';
    buttons.forEach(b => {
      const btn = document.createElement('button');
      btn.className = b.cls || 'modal-btn-cancel';
      btn.textContent = b.label;
      btn.addEventListener('click', () => { closeModal(); if (b.onClick) b.onClick(); });
      modalActions.appendChild(btn);
    });
    modalOverlay.classList.add('visible');
  }

  function closeModal() { modalOverlay.classList.remove('visible'); }
  modalOverlay.addEventListener('click', e => { if (e.target === modalOverlay) closeModal(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

  function appAlert(title, body, icon) {
    return new Promise(resolve => {
      showModal({ icon: icon || '⚠️', title, body, buttons: [
        { label: 'OK', cls: 'modal-btn-primary', onClick: resolve }
      ]});
    });
  }

  function appConfirm(title, body, icon) {
    return new Promise(resolve => {
      showModal({ icon: icon || '⚠️', title, body, buttons: [
        { label: 'Cancel', cls: 'modal-btn-cancel', onClick: () => resolve(false) },
        { label: 'Proceed', cls: 'modal-btn-primary', onClick: () => resolve(true) },
      ]});
    });
  }

  function appConfirmDanger(title, body) {
    return new Promise(resolve => {
      showModal({ icon: '🗑️', title, body, buttons: [
        { label: 'Cancel', cls: 'modal-btn-cancel', onClick: () => resolve(false) },
        { label: 'Delete', cls: 'modal-btn-danger', onClick: () => resolve(true) },
      ]});
    });
  }

  // ── Unit tabs ────────────────────────────────────────────────────────────────
  document.querySelectorAll('.unit-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      document.querySelectorAll('.unit-tab').forEach(t => t.classList.remove('active'));
      document.querySelectorAll('.unit-panel').forEach(p => p.classList.remove('active'));
      tab.classList.add('active');
      document.querySelector(`.unit-panel[data-unit-id="${tab.dataset.unitId}"]`).classList.add('active');
    });
  });

  // ── Upload zones ─────────────────────────────────────────────────────────────
  document.querySelectorAll('.upload-zone').forEach(zone => {
    const unitId = zone.dataset.unit;
    const fileInput = zone.querySelector('.exam-file-input');
    const notesForm = zone.closest('.card-body').querySelector(`.notes-form[data-unit="${unitId}"]`);

    zone.addEventListener('click', () => fileInput.click());
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
    zone.addEventListener('drop', e => { e.preventDefault(); zone.classList.remove('drag-over'); if (e.dataTransfer.files.length) upload(e.dataTransfer.files[0], unitId, zone, notesForm); });
    fileInput.addEventListener('change', () => { if (fileInput.files.length) upload(fileInput.files[0], unitId, zone, notesForm); });
  });

  async function upload(file, unitId, zone, notesForm) {
    const titleInput = notesForm.querySelector('.exam-title-input');
    let title = titleInput.value.trim();
    if (!title) title = file.name.replace(/\.pdf$/i, '');
    titleInput.value = title;

    const fd = new FormData();
    fd.append('exam_pdf', file);
    fd.append('unit_id', unitId);
    fd.append('exam_title', title);

    zone.querySelector('.upload-icon').textContent = '⏳';
    try {
      const res = await fetch(`${BASE}/api/exam-insights/upload`, { method:'POST', body:fd, credentials:'same-origin' });
      const data = await res.json();
      if (!data.success) { appAlert('Upload Failed', esc(data.error || 'Upload failed.'), '❌'); zone.querySelector('.upload-icon').textContent = '📄'; return; }

      // Check if extracted text mentions a different unit code
      if (data.text_preview) {
        const currentCode = unitCodes[unitId] || '';
        const preview = data.text_preview.toUpperCase();
        const otherUnits = Object.entries(unitCodes)
          .filter(([id, code]) => id !== String(unitId) && preview.includes(code.toUpperCase()))
          .map(([, code]) => code);
        const mentionsCurrent = currentCode && preview.includes(currentCode.toUpperCase());

        if (!mentionsCurrent && otherUnits.length > 0) {
          const proceed = await appConfirm(
            'Unit Mismatch Detected',
            `This PDF mentions <strong>${esc(otherUnits.join(', '))}</strong> but does not appear to mention <strong>${esc(currentCode)}</strong>.<br><br>You are uploading under the <strong>${esc(currentCode)}</strong> section. Are you sure this is the correct unit?`
          );
          if (!proceed) {
            // Delete the already-uploaded record
            await fetch(`${BASE}/api/exam-insights/delete`, {
              method:'POST', credentials:'same-origin', headers: headers(),
              body: JSON.stringify({ upload_id: data.upload_id })
            });
            zone.querySelector('.upload-icon').textContent = '📄';
            return;
          }
        } else if (!mentionsCurrent && otherUnits.length === 0) {
          // Doesn't mention current unit but no other match either — soft warning
        }
      }

      notesForm.querySelector('.upload-id-input').value = data.upload_id;
      notesForm.querySelector(`#upload-filename-${unitId}`).textContent = file.name + (data.has_text ? ' (text extracted)' : ' (no text found)');
      zone.style.display = 'none';
      notesForm.style.display = 'grid';
    } catch { appAlert('Network Error', 'Could not connect to the server. Please try again.', '🌐'); }
    zone.querySelector('.upload-icon').textContent = '📄';
  }

  // ── Save notes ───────────────────────────────────────────────────────────────
  document.querySelectorAll('.save-notes-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      const form = btn.closest('.notes-form');
      const uploadId = form.querySelector('.upload-id-input').value;
      const status = form.querySelector('.notes-status');
      const avgScore = parseFloat(form.querySelector('.avg-score-input').value);

      if (isNaN(avgScore)) { status.textContent = 'Please enter an average score.'; return; }

      status.textContent = 'Saving...';
      btn.disabled = true;
      try {
        const res = await fetch(`${BASE}/api/exam-insights/save_notes`, {
          method:'POST', credentials:'same-origin', headers: headers(),
          body: JSON.stringify({
            upload_id: parseInt(uploadId),
            avg_score: avgScore,
            most_failed: form.querySelector('.most-failed-input').value.trim(),
            observations: form.querySelector('.observations-input').value.trim(),
          })
        });
        const data = await res.json();
        if (data.success) {
          status.textContent = 'Saved!';
          form.querySelector('.analyze-btn').disabled = false;
        } else {
          status.textContent = data.error || 'Save failed.';
        }
      } catch { status.textContent = 'Network error.'; }
      btn.disabled = false;
    });
  });

  // ── Analyze ──────────────────────────────────────────────────────────────────
  document.querySelectorAll('.analyze-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      const form = btn.closest('.notes-form');
      const uploadId = form.querySelector('.upload-id-input').value;
      const status = form.querySelector('.notes-status');
      const resultDiv = form.querySelector('.insight-result');

      btn.disabled = true;
      btn.textContent = 'Analyzing...';
      status.textContent = 'This may take 30-60 seconds...';

      try {
        const res = await fetch(`${BASE}/api/exam-insights/analyze`, {
          method:'POST', credentials:'same-origin', headers: headers(),
          body: JSON.stringify({ upload_id: parseInt(uploadId) })
        });
        const data = await res.json();
        if (data.success) {
          status.textContent = 'Analysis complete!';
          renderInsight(resultDiv, data.insight);
          resultDiv.style.display = 'block';
        } else {
          status.textContent = data.error || 'Analysis failed.';
          btn.disabled = false;
        }
      } catch { status.textContent = 'Network error.'; btn.disabled = false; }
      btn.textContent = 'Analyze with AI';
    });
  });

  function renderInsight(container, insight) {
    let html = `<div class="insight-card">
      <h3 style="font-size:var(--text-sm);font-weight:700;margin-bottom:var(--space-2)">AI Analysis</h3>
      <div class="insight-summary">${esc(insight.summary).replace(/\n/g,'<br>')}</div>`;

    if (insight.suggestions?.length) {
      html += '<h4 style="font-size:var(--text-sm);font-weight:600;margin-bottom:var(--space-2)">Suggestions</h4>';
      insight.suggestions.forEach(s => {
        html += `<div class="suggestion-item" data-sugg-id="${s.id}">
          <div class="sugg-body">
            <div class="sugg-cat">${esc(s.category)}</div>
            <div>${esc(s.suggestion_text)}</div>
            <div class="sugg-actions">
              <button class="btn btn-sm btn-outline exec-sugg-btn" data-id="${s.id}" data-action="execute">Mark Executed</button>
              <button class="btn btn-sm btn-ghost dismiss-sugg-btn" data-id="${s.id}" data-action="dismiss">Dismiss</button>
            </div>
            <div class="sugg-followup"><textarea placeholder="Follow-up notes..." class="followup-input" data-id="${s.id}"></textarea></div>
          </div>
        </div>`;
      });
    }

    if (insight.comparisons) {
      let comp = insight.comparisons;
      if (typeof comp === 'object') comp = Array.isArray(comp) ? comp.join('\n') : JSON.stringify(comp, null, 2);
      html += `<div style="margin-top:var(--space-4)"><strong>Historical Comparison:</strong><br>${esc(String(comp)).replace(/\n/g,'<br>')}</div>`;
    }
    if (insight.future_ideas) {
      let ideas = insight.future_ideas;
      if (Array.isArray(ideas)) {
        ideas = ideas.map(item => typeof item === 'object' ? (item.text || item.idea || JSON.stringify(item)) : String(item)).join('\n');
      } else if (typeof ideas === 'object') {
        ideas = Object.values(ideas).join('\n');
      }
      html += `<div class="future-ideas"><strong>Future Ideas:</strong><br>${esc(String(ideas)).replace(/\n/g,'<br>')}</div>`;
    }
    html += '</div>';
    container.innerHTML = html;
    bindSuggestionButtons(container);
  }

  // ── Suggestion actions ───────────────────────────────────────────────────────
  function bindSuggestionButtons(scope) {
    scope = scope || document;
    scope.querySelectorAll('.exec-sugg-btn, .dismiss-sugg-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        const id = parseInt(btn.dataset.id);
        const action = btn.dataset.action;
        const item = btn.closest('.suggestion-item');
        const followUp = item.querySelector(`.followup-input[data-id="${id}"]`)?.value?.trim() || '';

        btn.disabled = true;
        try {
          const res = await fetch(`${BASE}/api/exam-insights/suggestions`, {
            method:'POST', credentials:'same-origin', headers: headers(),
            body: JSON.stringify({ suggestion_id: id, action, follow_up_notes: followUp })
          });
          const data = await res.json();
          if (data.success) {
            item.classList.add(data.status);
            item.querySelector('.sugg-actions').innerHTML = `<span class="status-badge status-${data.status}">${data.status}</span>`;
            const fup = item.querySelector('.sugg-followup');
            if (fup) fup.innerHTML = followUp ? `<div style="font-size:var(--text-xs);color:var(--color-text-muted)">Note: ${esc(followUp)}</div>` : '';
          }
        } catch {}
        btn.disabled = false;
      });
    });
  }
  bindSuggestionButtons(document);

  // ── Delete exam ───────────────────────────────────────────────────────────────
  document.querySelectorAll('.delete-exam-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
      const id = btn.dataset.uploadId;
      const title = btn.dataset.title;
      const confirmed = await appConfirmDanger(
        'Delete Exam Upload',
        `Are you sure you want to delete <strong>${esc(title)}</strong>?<br><br>This will permanently remove the exam, its AI analysis, and all suggestions. This cannot be undone.`
      );
      if (!confirmed) return;

      btn.disabled = true;
      btn.textContent = 'Deleting...';
      try {
        const res = await fetch(`${BASE}/api/exam-insights/delete`, {
          method:'POST', credentials:'same-origin', headers: headers(),
          body: JSON.stringify({ upload_id: parseInt(id) })
        });
        const data = await res.json();
        if (data.success) {
          const row = btn.closest('tr');
          const detailRow = document.querySelector(`.detail-row[data-upload-id="${id}"]`);
          if (row) row.remove();
          if (detailRow) detailRow.remove();
        } else {
          appAlert('Delete Failed', esc(data.error || 'Could not delete the exam.'), '❌');
          btn.disabled = false;
          btn.textContent = 'Delete';
        }
      } catch {
        appAlert('Network Error', 'Could not connect to the server.', '🌐');
        btn.disabled = false;
        btn.textContent = 'Delete';
      }
    });
  });

  // ── Toggle history detail ────────────────────────────────────────────────────
  document.querySelectorAll('.toggle-detail-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const row = document.querySelector(`.detail-row[data-upload-id="${btn.dataset.uploadId}"]`);
      const visible = row.style.display !== 'none';
      row.style.display = visible ? 'none' : 'table-row';
      btn.textContent = visible ? 'Details' : 'Hide';
    });
  });

  // ── Compare ──────────────────────────────────────────────────────────────────
  document.querySelectorAll('.compare-check').forEach(cb => {
    cb.addEventListener('change', () => {
      const unitId = cb.dataset.unitId;
      const checked = document.querySelectorAll(`.compare-check[data-unit-id="${unitId}"]:checked`);
      const btn = document.querySelector(`.compare-btn[data-unit-id="${unitId}"]`);
      btn.textContent = `Compare Selected (${checked.length}/2)`;
      btn.disabled = checked.length !== 2;
    });
  });

  document.querySelectorAll('.compare-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      const unitId = btn.dataset.unitId;
      const checked = document.querySelectorAll(`.compare-check[data-unit-id="${unitId}"]:checked`);
      if (checked.length !== 2) return;

      const ids = Array.from(checked).map(c => parseInt(c.dataset.uploadId));
      const status = btn.closest('.compare-bar').querySelector('.compare-status');
      const resultDiv = document.querySelector(`.compare-result[data-unit-id="${unitId}"]`);

      btn.disabled = true;
      status.textContent = 'Comparing with AI...';
      try {
        const res = await fetch(`${BASE}/api/exam-insights/compare`, {
          method:'POST', credentials:'same-origin', headers: headers(),
          body: JSON.stringify({ unit_id: parseInt(unitId), upload_id_a: ids[0], upload_id_b: ids[1] })
        });
        const data = await res.json();
        if (data.success) {
          resultDiv.textContent = data.comparison;
          resultDiv.style.display = 'block';
          status.textContent = '';
        } else {
          status.textContent = data.error || 'Comparison failed.';
        }
      } catch { status.textContent = 'Network error.'; }
      btn.disabled = false;
    });
  });

  function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
})();
</script>
</body>
</html>
