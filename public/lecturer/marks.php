<?php
/**
 * EduTrack — Lecturer Marks Management Page
 *
 * Allows lecturers to:
 *   - Select a unit and view all its assessments
 *   - Create new assessment components (CAT, exam, assignment, etc.)
 *   - Upload single marks via form or bulk via CSV
 *   - Toggle publish/unpublish per assessment
 *   - View the full class mark sheet in a grid
 */

define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/MarksModel.php';

Auth::startSession();
Auth::requireRole('lecturer');

$user = Auth::user();

// ── Active academic context ───────────────────────────────────────────────────
$academicYear = DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year'"
)['setting_value'] ?? ACADEMIC_YEAR;

$semester = (int)(DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'active_semester'"
)['setting_value'] ?? ACTIVE_SEMESTER);

// ── Units taught by this lecturer ─────────────────────────────────────────────
$units = DB::rows(
    "SELECT u.id, u.code, u.name, c.name AS course_name
     FROM units u
     JOIN courses c ON c.id = u.course_id
     WHERE u.lecturer_id = ? AND u.is_active = 1
     ORDER BY u.code ASC",
    [$user['id']]
);

// ── Selected unit (from GET param or first unit) ──────────────────────────────
$selectedUnitId = (int)($_GET['unit_id'] ?? ($units[0]['id'] ?? 0));

$selectedUnit = null;
$assessments  = [];
$markSheet    = ['assessments' => [], 'students' => []];

if ($selectedUnitId > 0) {
    // Verify unit belongs to this lecturer
    $selectedUnit = DB::row(
        "SELECT u.id, u.code, u.name, c.name AS course_name
         FROM units u JOIN courses c ON c.id = u.course_id
         WHERE u.id = ? AND u.lecturer_id = ?",
        [$selectedUnitId, $user['id']]
    );

    if ($selectedUnit) {
        $assessments = MarksModel::getUnitAssessments($selectedUnitId, false);
        $markSheet   = MarksModel::getUnitMarksSheet($selectedUnitId, $academicYear, $semester);
        $markSheetStudentCount = count($markSheet['students']);
        $displayStudents = array_slice($markSheet['students'], 0, 3);
    }
}

// ── Enrolled students for single-mark upload dropdown ────────────────────────
$enrolledStudents = [];
if ($selectedUnit) {
    $enrolledStudents = DB::rows(
        "SELECT u.id, u.reg_number, u.full_name
         FROM enrollments e
         JOIN users u ON u.id = e.student_id
         WHERE e.unit_id = ? AND e.academic_year = ? AND e.semester = ?
         ORDER BY u.full_name ASC",
        [$selectedUnitId, $academicYear, $semester]
    );
}

$csrfToken = Auth::csrfToken();
$pageTitle = 'Marks Management';
?>
<!DOCTYPE html>
<html lang="en" data-base-url="<?= htmlspecialchars(BASE_URL) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
  <title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/base.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/lecturer.css">
</head>
<body>
<div class="layout">

  <?php include __DIR__ . '/../partials/sidebar_lecturer.php'; ?>

  <div class="main">
    <header class="topbar">
      <span class="topbar-title">Marks Management</span>
      <div class="topbar-actions">
        <?php if ($selectedUnit): ?>
          <button class="btn btn-primary btn-sm" onclick="openCreateModal()">
            + New Assessment
          </button>
        <?php endif; ?>
      </div>
    </header>

    <div class="page-content">

      <!-- Unit selector -->
      <div class="card animate-fade-in" style="margin-bottom:var(--space-6)">
        <div class="card-header">
          <div class="card-title">Select Unit</div>
        </div>
        <div style="display:flex;gap:var(--space-3);flex-wrap:wrap">
          <?php foreach ($units as $unit): ?>
            <a href="?unit_id=<?= $unit['id'] ?>"
               class="unit-selector-btn <?= $unit['id'] === $selectedUnitId ? 'active' : '' ?>">
              <span class="font-mono text-xs"><?= htmlspecialchars($unit['code']) ?></span>
              <span class="text-sm"><?= htmlspecialchars($unit['name']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if (!$selectedUnit): ?>
        <div class="empty-state">
          <span class="empty-icon">📝</span>
          <p class="empty-title">Select a unit above to manage marks</p>
        </div>

      <?php else: ?>

        <!-- Unit header -->
        <div class="page-title animate-fade-in">
          <div class="title-icon">📝</div>
          <div>
            <?= htmlspecialchars($selectedUnit['code']) ?> —
            <?= htmlspecialchars($selectedUnit['name']) ?>
            <div class="text-sm text-muted font-mono" style="font-family:var(--font-body)">
              <?= htmlspecialchars($selectedUnit['course_name']) ?> ·
              <?= $academicYear ?> · Semester <?= $semester ?>
            </div>
          </div>
        </div>

        <!-- Assessments list -->
        <div class="card animate-fade-in" style="margin-bottom:var(--space-6)">
          <div class="card-header">
            <div>
              <div class="card-title">Assessments</div>
              <div class="card-subtitle">
                <?php
                  $totalWeight = array_sum(array_column($assessments, 'weight_percent'));
                  echo "Total weight: {$totalWeight}% / 100%";
                ?>
              </div>
            </div>
            <button class="btn btn-primary btn-sm" onclick="openCreateModal()">
              + New Assessment
            </button>
          </div>

          <?php if (empty($assessments)): ?>
            <div class="empty-state" style="padding:var(--space-8) 0">
              <span class="empty-icon">📋</span>
              <p class="empty-title">No assessments yet</p>
              <p class="empty-text">
                Create your first assessment component (CAT, exam, assignment).
              </p>
              <button class="btn btn-primary btn-sm" onclick="openCreateModal()">
                + Create Assessment
              </button>
            </div>
          <?php else: ?>
            <div class="table-wrap">
              <table class="table">
                <thead>
                  <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Max Score</th>
                    <th>Weight</th>
                    <th>Date</th>
                    <th>Marks</th>
                    <th>Avg</th>
                    <th>Visible</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($assessments as $a): ?>
                  <tr>
                    <td class="font-medium"><?= htmlspecialchars($a['name']) ?></td>
                    <td>
                      <span class="badge badge-info">
                        <?= htmlspecialchars(ucfirst(str_replace('_',' ',$a['type']))) ?>
                      </span>
                    </td>
                    <td class="font-mono text-sm"><?= $a['max_score'] ?></td>
                    <td class="font-mono text-sm"><?= $a['weight_percent'] ?>%</td>
                    <td class="text-sm text-muted">
                      <?= $a['assessment_date']
                          ? date('d M Y', strtotime($a['assessment_date']))
                          : '—' ?>
                    </td>
                    <td>
                      <span class="font-semibold"><?= $a['marks_uploaded'] ?></span>
                      <span class="text-muted text-xs">/ <?= count($enrolledStudents) ?></span>
                    </td>
                    <td class="font-mono text-sm">
                      <?= $a['class_average'] !== null
                          ? round($a['class_average'], 1)
                          : '—' ?>
                    </td>
                    <td>
                      <button class="btn btn-ghost btn-sm"
                              onclick="togglePublish(<?= $a['id'] ?>, this)"
                              data-published="<?= $a['is_published'] ?>"
                              title="<?= $a['is_published'] ? 'Unpublish' : 'Publish' ?>">
                        <?= $a['is_published'] ? '✅ Published' : '⬜ Draft' ?>
                      </button>
                    </td>
                    <td>
                      <button class="btn btn-primary btn-sm"
                              onclick="openUploadModal(<?= $a['id'] ?>,
                                '<?= htmlspecialchars($a['name'], ENT_QUOTES) ?>',
                                <?= $a['max_score'] ?>)">
                        Upload Marks
                      </button>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <!-- Class mark sheet -->
        <?php if (!empty($markSheet['students']) && !empty($markSheet['assessments'])): ?>
        <div class="card animate-fade-in" style="animation-delay:0.1s">
          <div class="card-header">
            <div>
              <div class="card-title">Class Mark Sheet</div>
              <div class="card-subtitle">
                Showing <?= count($displayStudents) ?> of <?= $markSheetStudentCount ?> students ·
                <?= count($markSheet['assessments']) ?> assessments
              </div>
            </div>
            <div style="display:flex;gap:var(--space-2);flex-wrap:wrap;align-items:center">
              <a href="<?= BASE_URL ?>/public/lecturer/marksheet.php?unit_id=<?= $selectedUnitId ?>"
                 class="btn btn-outline btn-sm">
                View all
              </a>
              <a href="<?= BASE_URL ?>/api/reports/marks_sheet.php?unit_id=<?= $selectedUnitId ?>"
                 class="btn btn-secondary btn-sm" target="_blank">
                🖨️ Export PDF
              </a>
            </div>
          </div>
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>Reg. No.</th>
                  <th>Student Name</th>
                  <?php foreach ($markSheet['assessments'] as $a): ?>
                    <th title="Max: <?= $a['max_score'] ?> | Weight: <?= $a['weight_percent'] ?>%">
                      <?= htmlspecialchars($a['name']) ?>
                      <div class="text-xs" style="font-weight:400;opacity:0.7">
                        /<?= $a['max_score'] ?>
                      </div>
                    </th>
                  <?php endforeach; ?>
                  <th>Total</th>
                  <th>Grade</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($displayStudents as $s): ?>
                <tr>
                  <td class="font-mono text-xs"><?= htmlspecialchars($s['reg_number']) ?></td>
                  <td class="text-sm"><?= htmlspecialchars($s['full_name']) ?></td>
                  <?php foreach ($markSheet['assessments'] as $a): ?>
                    <td class="font-mono text-sm text-center">
                      <?php
                        $score = $s['scores'][$a['id']] ?? null;
                        if ($score !== null) {
                          $pct = ($score / $a['max_score']) * 100;
                          $col = $pct >= 50
                            ? 'var(--color-success)'
                            : 'var(--color-error)';
                          echo "<span style='color:{$col}'>{$score}</span>";
                        } else {
                          echo '<span class="text-muted">—</span>';
                        }
                      ?>
                    </td>
                  <?php endforeach; ?>
                  <td class="font-mono font-semibold text-sm">
                    <?= $s['weighted_total'] ?>
                  </td>
                  <td>
                    <?php if ($s['grade']): ?>
                      <span class="grade-pill grade-<?= $s['grade'] ?>">
                        <?= $s['grade'] ?>
                      </span>
                    <?php else: ?>
                      <span class="text-muted text-xs">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php endif; ?>

      <?php endif; ?>
    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->


<!-- ── Create Assessment Modal ───────────────────────────────────────────── -->
<div class="modal-backdrop" id="create-modal" hidden>
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title">New Assessment</h2>
      <button class="modal-close" onclick="closeCreateModal()">✕</button>
    </div>
    <div class="modal-body">
      <div data-error-container class="alert alert-error"
           style="margin-bottom:var(--space-4)"></div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">
            Name <span class="required">*</span>
          </label>
          <input type="text" id="a-name" class="form-control"
                 placeholder="e.g. CAT 1, Final Exam">
        </div>
        <div class="form-group">
          <label class="form-label">
            Type <span class="required">*</span>
          </label>
          <select id="a-type" class="form-control">
            <option value="cat">CAT</option>
            <option value="assignment">Assignment</option>
            <option value="practical">Practical</option>
            <option value="project">Project</option>
            <option value="final_exam">Final Exam</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">
            Max Score <span class="required">*</span>
          </label>
          <input type="number" id="a-max" class="form-control"
                 placeholder="e.g. 30" min="1" max="100" step="0.5">
        </div>
        <div class="form-group">
          <label class="form-label">
            Weight (%) <span class="required">*</span>
          </label>
          <input type="number" id="a-weight" class="form-control"
                 placeholder="e.g. 20" min="1" max="100" step="0.5">
          <div class="form-hint">
            <?php
              $used = !empty($assessments)
                  ? array_sum(array_column($assessments, 'weight_percent'))
                  : 0;
              $remaining = 100 - $used;
            ?>
            <?= $remaining ?>% remaining of 100%
          </div>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Assessment Date</label>
        <input type="date" id="a-date" class="form-control">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeCreateModal()">Cancel</button>
      <button class="btn btn-primary" id="create-btn" onclick="createAssessment()">
        Create Assessment
      </button>
    </div>
  </div>
</div>

<!-- ── Upload Marks Modal ────────────────────────────────────────────────── -->
<div class="modal-backdrop" id="upload-modal" hidden>
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title">Upload Marks</h2>
      <button class="modal-close" onclick="closeUploadModal()">✕</button>
    </div>
    <div class="modal-body">
      <div id="upload-context" class="alert alert-info"
           style="margin-bottom:var(--space-4)">
        <span class="alert-icon">📝</span>
        <span id="upload-context-text"></span>
      </div>
      <div data-error-container class="alert alert-error"
           style="margin-bottom:var(--space-4)"></div>

      <!-- Upload mode tabs -->
      <div style="display:flex;gap:var(--space-2);margin-bottom:var(--space-5)">
        <button class="btn btn-primary btn-sm" id="tab-single"
                onclick="switchUploadTab('single')">
          Single Student
        </button>
        <button class="btn btn-secondary btn-sm" id="tab-bulk"
                onclick="switchUploadTab('bulk')">
          Bulk CSV
        </button>
      </div>

      <!-- Single mark form -->
      <div id="single-form">
        <div class="form-group">
          <label class="form-label">
            Student <span class="required">*</span>
          </label>
          <input type="text" id="student-search" class="form-control"
                 placeholder="Search by reg number or name..." 
                 autocomplete="off"
                 style="margin-bottom:var(--space-2)">
          
          <!-- Live search results -->
          <div id="search-results" 
               style="display:none;
                      background:white;
                      border:1px solid var(--color-border);
                      border-radius:var(--radius-md);
                      max-height:200px;
                      overflow-y:auto;
                      margin-bottom:var(--space-2);
                      box-shadow:0 4px 6px rgba(0,0,0,0.1)">
          </div>

          <select id="single-student" class="form-control">
            <option value="">— Or select from list below —</option>
            <?php foreach ($enrolledStudents as $s): ?>
              <option value="<?= $s['id'] ?>" data-reg="<?= htmlspecialchars($s['reg_number']) ?>" data-name="<?= htmlspecialchars($s['full_name']) ?>">
                <?= htmlspecialchars($s['reg_number']) ?> —
                <?= htmlspecialchars($s['full_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label" id="score-label">
            Score <span class="required">*</span>
          </label>
          <input type="number" id="single-score" class="form-control"
                 placeholder="0" min="0" step="0.5">
        </div>
      </div>

      <!-- Bulk CSV form -->
      <div id="bulk-form" style="display:none">
        <div class="alert alert-info" style="margin-bottom:var(--space-4)">
          <span class="alert-icon">📋</span>
          <div>
            <strong>CSV Format:</strong> Header row required with columns:
            <code>reg_number, score</code><br>
            <span class="text-xs">One student per row. Extra columns are ignored.</span>
          </div>
        </div>
        <div class="upload-zone" id="drop-zone"
             onclick="document.getElementById('csv-file').click()">
          <span class="upload-icon">📂</span>
          <div class="font-medium text-sm">Click to choose CSV file</div>
          <div class="upload-hint">or drag and drop here</div>
          <div id="file-name" class="text-xs text-accent"
               style="margin-top:var(--space-2)"></div>
        </div>
        <input type="file" id="csv-file" accept=".csv,text/csv"
               style="display:none" onchange="onFileSelect(this)">
      </div>

      <!-- Upload result summary -->
      <div id="upload-result" style="display:none;margin-top:var(--space-4)"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeUploadModal()">Cancel</button>
      <button class="btn btn-primary" id="upload-btn" onclick="submitUpload()">
        Save Mark
      </button>
    </div>
  </div>
</div>


<nav class="mobile-nav">
  <a href="<?= BASE_URL ?>/public/lecturer/dashboard.php" class="mobile-nav-item">
    <span class="nav-icon">🏠</span><span>Home</span>
  </a>
  <a href="<?= BASE_URL ?>/public/lecturer/sessions.php" class="mobile-nav-item">
    <span class="nav-icon">📋</span><span>Sessions</span>
  </a>
  <a href="<?= BASE_URL ?>/public/lecturer/marks.php" class="mobile-nav-item active">
    <span class="nav-icon">📝</span><span>Marks</span>
  </a>
  <a href="<?= BASE_URL ?>/public/lecturer/disputes.php" class="mobile-nav-item">
    <span class="nav-icon">⚠️</span><span>Disputes</span>
  </a>
</nav>

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
<script>
const BASE_URL      = <?= json_encode(BASE_URL) ?>;
const UNIT_ID       = <?= json_encode($selectedUnitId) ?>;
let   activeAssessmentId  = null;
let   activeAssessmentMax = null;
let   uploadMode    = 'single';

// ── Create Assessment Modal ───────────────────────────────────────────────────
function openCreateModal() {
  document.getElementById('create-modal').hidden = false;
  document.body.style.overflow = 'hidden';
}
function closeCreateModal() {
  document.getElementById('create-modal').hidden = true;
  document.body.style.overflow = '';
}

async function createAssessment() {
  const name   = document.getElementById('a-name').value.trim();
  const type   = document.getElementById('a-type').value;
  const max    = parseFloat(document.getElementById('a-max').value);
  const weight = parseFloat(document.getElementById('a-weight').value);
  const date   = document.getElementById('a-date').value;
  const btn    = document.getElementById('create-btn');
  const errEl  = document.querySelector('#create-modal [data-error-container]');

  errEl.textContent = ''; errEl.hidden = true;

  if (!name || !type || isNaN(max) || isNaN(weight)) {
    errEl.textContent = 'Please fill in all required fields.';
    errEl.hidden = false;
    return;
  }

  await Api.withLoading(btn, async () => {
    try {
      await Api.post(`${BASE_URL}/api/marks/assessment_create.php`, {
        unit_id:         UNIT_ID,
        name, type,
        max_score:       max,
        weight_percent:  weight,
        assessment_date: date || null,
      });
      Toast.show('success', 'Assessment created.');
      closeCreateModal();
      window.location.reload();
    } catch (err) {
      errEl.textContent = err.message;
      errEl.hidden = false;
    }
  });
}

// ── Upload Marks Modal ────────────────────────────────────────────────────────
function openUploadModal(assessmentId, name, maxScore) {
  activeAssessmentId  = assessmentId;
  activeAssessmentMax = maxScore;

  document.getElementById('upload-context-text').textContent =
    `Assessment: ${name}  ·  Max score: ${maxScore}`;
  document.getElementById('score-label').textContent =
    `Score (max ${maxScore})  *`;
  document.getElementById('single-score').max = maxScore;
  document.getElementById('upload-result').style.display = 'none';
  document.querySelector('#upload-modal [data-error-container]').textContent = '';
  document.querySelector('#upload-modal [data-error-container]').hidden = true;

  // Reset search and form
  document.getElementById('student-search').value = '';
  document.getElementById('search-results').style.display = 'none';
  document.getElementById('search-results').innerHTML = '';
  document.getElementById('single-student').value = '';
  document.getElementById('single-score').value = '';

  switchUploadTab('single');
  document.getElementById('upload-modal').hidden = false;
  document.body.style.overflow = 'hidden';
}

function closeUploadModal() {
  document.getElementById('upload-modal').hidden = true;
  document.body.style.overflow = '';
  activeAssessmentId = null;
}

function switchUploadTab(mode) {
  uploadMode = mode;
  document.getElementById('single-form').style.display = mode === 'single' ? 'block' : 'none';
  document.getElementById('bulk-form').style.display   = mode === 'bulk'   ? 'block' : 'none';
  document.getElementById('tab-single').className =
    'btn btn-sm ' + (mode === 'single' ? 'btn-primary' : 'btn-secondary');
  document.getElementById('tab-bulk').className =
    'btn btn-sm ' + (mode === 'bulk' ? 'btn-primary' : 'btn-secondary');
  document.getElementById('upload-btn').textContent =
    mode === 'single' ? 'Save Mark' : 'Upload CSV';

  // Reset search when switching to single tab
  if (mode === 'single') {
    setTimeout(() => {
      document.getElementById('student-search').value = '';
      document.getElementById('search-results').style.display = 'none';
      document.getElementById('search-results').innerHTML = '';
      document.getElementById('student-search').focus();
    }, 0);
  }
}

// ── Live student search with autocomplete ──────────────────────────────────────
function showSearchResults(query) {
  const resultsContainer = document.getElementById('search-results');
  const q = query.toLowerCase().trim();

  if (!q) {
    resultsContainer.style.display = 'none';
    resultsContainer.innerHTML = '';
    return;
  }

  // Get all enrolled students and filter
  const allOptions = document.querySelectorAll('#single-student option');
  const filtered = [];

  allOptions.forEach((opt, idx) => {
    if (idx === 0) return; // Skip placeholder
    const reg = opt.dataset.reg?.toLowerCase() || '';
    const name = opt.dataset.name?.toLowerCase() || '';
    
    if (reg.includes(q) || name.includes(q)) {
      filtered.push({
        id: opt.value,
        reg: opt.dataset.reg,
        name: opt.dataset.name
      });
    }
  });

  if (filtered.length === 0) {
    resultsContainer.innerHTML = '<div style="padding:var(--space-3);text-align:center;color:var(--color-text-muted);font-size:var(--text-sm)">No students found</div>';
    resultsContainer.style.display = 'block';
    return;
  }

  // Render results
  const html = filtered.map(s =>
    `<div class="search-result-item" onclick="selectStudentFromSearch(${s.id}, '${escHtml(s.reg)}', '${escHtml(s.name)}')"
          style="padding:var(--space-3);cursor:pointer;border-bottom:1px solid var(--color-border-light);
                  transition:background var(--transition-fast);display:flex;justify-content:space-between;align-items:center"
          onmouseover="this.style.background='var(--color-accent-light)'"
          onmouseout="this.style.background='white'">
      <div>
        <div class="font-mono text-sm font-medium">${escHtml(s.reg)}</div>
        <div class="text-sm text-muted">${escHtml(s.name)}</div>
      </div>
      <span style="color:var(--color-accent);font-size:var(--text-xs)">Select →</span>
    </div>`
  ).join('');

  resultsContainer.innerHTML = html;
  resultsContainer.style.display = 'block';
}

function selectStudentFromSearch(studentId, reg, name) {
  document.getElementById('single-student').value = studentId;
  document.getElementById('student-search').value = `${reg} — ${name}`;
  document.getElementById('search-results').style.display = 'none';
  document.getElementById('search-results').innerHTML = '';
  document.getElementById('single-score').focus();
}

// Add search listener
document.addEventListener('DOMContentLoaded', () => {
  const searchInput = document.getElementById('student-search');
  const resultsContainer = document.getElementById('search-results');
  
  if (searchInput) {
    // Show results as they type
    searchInput.addEventListener('input', (e) => {
      showSearchResults(e.target.value);
    });

    // Close results when clicking outside
    document.addEventListener('click', (e) => {
      if (!searchInput.contains(e.target) && !resultsContainer.contains(e.target)) {
        resultsContainer.style.display = 'none';
      }
    });

    // Show results on focus if there's already input
    searchInput.addEventListener('focus', () => {
      if (searchInput.value.trim()) {
        showSearchResults(searchInput.value);
      }
    });
  }
});

function onFileSelect(input) {
  const name = input.files[0]?.name || '';
  document.getElementById('file-name').textContent = name ? `Selected: ${name}` : '';
}

// Drag and drop on upload zone
const dropZone = document.getElementById('drop-zone');
if (dropZone) {
  dropZone.addEventListener('dragover', e => {
    e.preventDefault();
    dropZone.classList.add('dragover');
  });
  dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
  dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('dragover');
    const file = e.dataTransfer.files[0];
    if (file) {
      const dt = new DataTransfer();
      dt.items.add(file);
      document.getElementById('csv-file').files = dt.files;
      document.getElementById('file-name').textContent = `Selected: ${file.name}`;
    }
  });
}

async function submitUpload() {
  const errEl = document.querySelector('#upload-modal [data-error-container]');
  const btn   = document.getElementById('upload-btn');
  errEl.textContent = ''; errEl.hidden = true;

  if (uploadMode === 'single') {
    const studentId = document.getElementById('single-student').value;
    const score     = document.getElementById('single-score').value;

    if (!studentId || score === '') {
      errEl.textContent = 'Please select a student and enter a score.';
      errEl.hidden = false;
      return;
    }

    await Api.withLoading(btn, async () => {
      try {
        await Api.post(`${BASE_URL}/api/marks/upload.php`, {
          mode:          'single',
          assessment_id: activeAssessmentId,
          student_id:    parseInt(studentId),
          score:         parseFloat(score),
        });
        Toast.show('success', 'Mark saved successfully.');
        closeUploadModal();
        window.location.reload();
      } catch (err) {
        errEl.textContent = err.message;
        errEl.hidden = false;
      }
    });

  } else {
    // Bulk CSV
    const fileInput = document.getElementById('csv-file');
    if (!fileInput.files.length) {
      errEl.textContent = 'Please select a CSV file.';
      errEl.hidden = false;
      return;
    }

    const formData = new FormData();
    formData.append('mode',          'bulk');
    formData.append('assessment_id', activeAssessmentId);
    formData.append('csv',           fileInput.files[0]);

    await Api.withLoading(btn, async () => {
      try {
        const data = await Api.upload(`${BASE_URL}/api/marks/upload.php`, formData);
        showUploadResult(data);
      } catch (err) {
        errEl.textContent = err.message;
        errEl.hidden = false;
      }
    });
  }
}

function showUploadResult(data) {
  const resultEl = document.getElementById('upload-result');
  const type     = data.skipped === 0 ? 'success' : (data.saved > 0 ? 'warning' : 'error');

  let html = `<div class="alert alert-${type}">
    <span class="alert-icon">${type === 'success' ? '✅' : '⚠️'}</span>
    <div><strong>${data.message}</strong>`;

  if (data.errors && data.errors.length) {
    html += `<ul style="margin-top:var(--space-2);padding-left:var(--space-4)">`;
    data.errors.slice(0, 10).forEach(e => {
      html += `<li class="text-xs">Row ${e.row}: ${escHtml(e.reg_number)} — ${escHtml(e.reason)}</li>`;
    });
    if (data.errors.length > 10) {
      html += `<li class="text-xs">...and ${data.errors.length - 10} more errors</li>`;
    }
    html += `</ul>`;
  }

  html += `</div></div>`;
  if (data.saved > 0) {
    html += `<button class="btn btn-primary btn-sm" style="margin-top:var(--space-3)"
              onclick="closeUploadModal();window.location.reload()">
              Done — Reload Page
             </button>`;
  }

  resultEl.innerHTML = html;
  resultEl.style.display = 'block';
}

// ── Publish toggle ────────────────────────────────────────────────────────────
async function togglePublish(assessmentId, btn) {
  try {
    const data = await Api.post(`${BASE_URL}/api/marks/publish.php`, {
      assessment_id: assessmentId,
    });
    btn.textContent      = data.published ? '✅ Published' : '⬜ Draft';
    btn.dataset.published = data.published ? '1' : '0';
    Toast.show('success', data.message);
  } catch (err) {
    Api.showError(err);
  }
}

function escHtml(str) {
  return String(str ?? '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<style>
.unit-selector-btn {
  display: inline-flex;
  flex-direction: column;
  gap: 2px;
  padding: var(--space-3) var(--space-4);
  border: 1.5px solid var(--color-border);
  border-radius: var(--radius-md);
  background: white;
  cursor: pointer;
  text-decoration: none;
  color: var(--color-text);
  transition: border-color var(--transition-fast),
              background var(--transition-fast);
}
.unit-selector-btn:hover {
  border-color: var(--color-accent);
  text-decoration: none;
  color: var(--color-text);
}
.unit-selector-btn.active {
  border-color: var(--color-accent);
  background: var(--color-accent-light);
}
</style>

</body>
</html>