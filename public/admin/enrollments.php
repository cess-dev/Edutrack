<?php
/**
 * EduTrack — Admin Enrollment Management
 *
 * Allows the admin to:
 *   - View all course-level enrollments for the active academic year / semester
 *   - Enroll a student in a course (auto-derives unit enrollments)
 *   - Bulk-enroll students via CSV (reg_number, full_name, course_code, year_of_study, semester)
 *   - Remove a student's course enrollment (and its unit enrollments)
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/UserModel.php';

Auth::startSession();
Auth::requireRole('admin');

$user = Auth::user();

// ── Active academic context ───────────────────────────────────────────────────
$academicYear = DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year'"
)['setting_value'] ?? ACADEMIC_YEAR;

$semester = (int)(DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'active_semester'"
)['setting_value'] ?? ACTIVE_SEMESTER);

// ── Filters ───────────────────────────────────────────────────────────────────
$search  = trim($_GET['search'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = ROWS_PER_PAGE;
$offset  = ($page - 1) * $perPage;

// ── Build course-enrollment query ─────────────────────────────────────────────
$params    = [$academicYear, $semester];
$likeWhere = '';
if ($search !== '') {
    $like      = '%' . $search . '%';
    $likeWhere = " AND (u.full_name LIKE ? OR u.reg_number LIKE ? OR c.code LIKE ?)";
    $params[]  = $like;
    $params[]  = $like;
    $params[]  = $like;
}

$totalRows = (int)(DB::row(
    "SELECT COUNT(*) AS cnt
     FROM student_course_enrollments sce
     JOIN users   u ON u.id = sce.student_id
     JOIN courses c ON c.id = sce.course_id
     WHERE sce.academic_year = ? AND sce.semester = ?{$likeWhere}",
    $params
)['cnt'] ?? 0);

$totalPages = max(1, (int)ceil($totalRows / $perPage));

$enrollments = DB::rows(
    "SELECT
        sce.id,
        sce.student_id,
        sce.year_of_study,
        sce.source,
        sce.enrolled_at,
        u.reg_number,
        u.full_name AS student_name,
        c.id   AS course_id,
        c.code AS course_code,
        c.name AS course_name,
        (SELECT COUNT(*) FROM enrollments e
         JOIN units un ON un.id = e.unit_id
         WHERE e.student_id    = sce.student_id
           AND e.academic_year = sce.academic_year
           AND e.semester      = sce.semester
           AND un.course_id    = sce.course_id) AS unit_count
     FROM student_course_enrollments sce
     JOIN users   u ON u.id = sce.student_id
     JOIN courses c ON c.id = sce.course_id
     WHERE sce.academic_year = ? AND sce.semester = ?{$likeWhere}
     ORDER BY u.full_name ASC, c.code ASC
     LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);

// ── Courses for manual enrollment modal ──────────────────────────────────────
$courses = DB::rows(
    "SELECT id, code, name FROM courses WHERE is_active = 1 ORDER BY code ASC"
);

$csrfToken = Auth::csrfToken();
$pageTitle = 'Enrollment Management';
?>
<!DOCTYPE html>
<html lang="en" data-base-url="<?= htmlspecialchars(BASE_URL) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
  <title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/base.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/admin.css">
</head>
<body>
<div class="layout">

  <?php include __DIR__ . '/../partials/sidebar_admin.php'; ?>

  <div class="main">
    <header class="topbar">
      <span class="topbar-title">Enrollments</span>
      <div class="topbar-actions">
        <span class="text-sm text-muted hidden-mobile">
          <?= htmlspecialchars($academicYear) ?> · Sem <?= $semester ?>
        </span>
        <button class="btn btn-secondary btn-sm" onclick="openBulkModal()">
          📋 Bulk Enroll (CSV)
        </button>
        <button class="btn btn-primary btn-sm" onclick="openEnrollModal()">
          + Enroll Student
        </button>
      </div>
    </header>

    <div class="page-content">

      <!-- Context banner -->
      <div class="alert alert-info animate-fade-in" style="margin-bottom:var(--space-5)">
        <span class="alert-icon">📅</span>
        <div>
          Showing course enrollments for
          <strong><?= htmlspecialchars($academicYear) ?></strong>,
          Semester <strong><?= $semester ?></strong>.
          To change, update settings in
          <a href="<?= BASE_URL ?>/admin/settings">System Settings</a>.
          Enrolling a student in a course auto-enrolls them in all active units for that
          course / year / semester.
        </div>
      </div>

      <!-- Search bar -->
      <div class="user-toolbar animate-fade-in">
        <form method="GET" style="display:flex;gap:var(--space-3);flex:1;flex-wrap:wrap">
          <input type="text"
                 name="search"
                 class="form-control user-search"
                 placeholder="Search by student name, reg number or course code..."
                 value="<?= htmlspecialchars($search) ?>"
                 autocomplete="off">
          <button type="submit" class="btn btn-secondary btn-sm">Search</button>
          <?php if ($search): ?>
            <a href="?" class="btn btn-ghost btn-sm">Clear</a>
          <?php endif; ?>
        </form>
        <span class="text-sm text-muted">
          <?= $totalRows ?> enrollment<?= $totalRows !== 1 ? 's' : '' ?>
        </span>
      </div>

      <!-- Enrollment table -->
      <div class="card animate-fade-in" style="animation-delay:0.1s">
        <?php if (empty($enrollments)): ?>
          <div class="empty-state" style="padding:var(--space-12) 0">
            <span class="empty-icon">📋</span>
            <p class="empty-title">No enrollments found</p>
            <p class="empty-text">
              <?= $search
                  ? 'No results match the current search. Try a different term.'
                  : 'No students have been enrolled for this semester yet.' ?>
            </p>
            <button class="btn btn-primary btn-sm" onclick="openEnrollModal()">
              + Enroll First Student
            </button>
          </div>

        <?php else: ?>
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Reg. Number</th>
                  <th>Course</th>
                  <th>Year</th>
                  <th>Units</th>
                  <th>Source</th>
                  <th>Enrolled On</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($enrollments as $e): ?>
                  <tr id="enroll-row-<?= $e['id'] ?>">
                    <td>
                      <div style="display:flex;align-items:center;gap:var(--space-3)">
                        <div class="user-avatar-sm">
                          <?= strtoupper(substr($e['student_name'], 0, 1)) ?>
                        </div>
                        <span class="font-medium text-sm">
                          <?= htmlspecialchars($e['student_name']) ?>
                        </span>
                      </div>
                    </td>
                    <td class="font-mono text-xs">
                      <?= htmlspecialchars($e['reg_number']) ?>
                    </td>
                    <td>
                      <span class="badge badge-info font-mono text-xs">
                        <?= htmlspecialchars($e['course_code']) ?>
                      </span>
                      <div class="text-xs text-muted" style="margin-top:2px">
                        <?= htmlspecialchars($e['course_name']) ?>
                      </div>
                    </td>
                    <td class="text-sm">Year <?= (int)$e['year_of_study'] ?></td>
                    <td>
                      <?php if ((int)$e['unit_count'] === 0): ?>
                        <span class="badge badge-warning text-xs" title="No units set up yet">
                          0 units ⚠
                        </span>
                      <?php else: ?>
                        <span class="badge badge-success text-xs">
                          <?= (int)$e['unit_count'] ?> units
                        </span>
                      <?php endif; ?>
                    </td>
                    <td class="text-xs text-muted">
                      <?= $e['source'] === 'csv' ? '📋 CSV' : '✋ Manual' ?>
                    </td>
                    <td class="text-xs text-muted">
                      <?= date('d M Y', strtotime($e['enrolled_at'])) ?>
                    </td>
                    <td>
                      <button class="btn btn-danger btn-sm"
                              onclick="unenroll(
                                <?= $e['id'] ?>,
                                <?= $e['student_id'] ?>,
                                <?= $e['course_id'] ?>,
                                '<?= htmlspecialchars($e['student_name'], ENT_QUOTES) ?>',
                                '<?= htmlspecialchars($e['course_code'],  ENT_QUOTES) ?>'
                              )">
                        Remove
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Pagination -->
          <?php if ($totalPages > 1):
            $baseQs = http_build_query(array_filter(['search' => $search ?: null]));
            $sep    = $baseQs ? '&' : '';
          ?>
            <div class="pagination">
              <a href="?<?= $baseQs.$sep ?>page=<?= max(1,$page-1) ?>"
                 class="page-btn <?= $page<=1?'disabled':'' ?>">‹</a>
              <?php for($p=max(1,$page-2); $p<=min($totalPages,$page+2); $p++): ?>
                <a href="?<?= $baseQs.$sep ?>page=<?= $p ?>"
                   class="page-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a>
              <?php endfor; ?>
              <a href="?<?= $baseQs.$sep ?>page=<?= min($totalPages,$page+1) ?>"
                 class="page-btn <?= $page>=$totalPages?'disabled':'' ?>">›</a>
              <span class="page-info">Page <?= $page ?> of <?= $totalPages ?></span>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->


<!-- ── Enroll Student Modal ──────────────────────────────────────────────── -->
<div class="modal-backdrop" id="enroll-modal" hidden>
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title">Enroll Student in Course</h2>
      <button class="modal-close" onclick="closeModal('enroll-modal')">✕</button>
    </div>
    <div class="modal-body">
      <div data-error-container="enroll"
           class="alert alert-error" style="margin-bottom:var(--space-4)"></div>

      <div class="alert alert-info" style="margin-bottom:var(--space-4)">
        <span class="alert-icon">ℹ</span>
        <div>
          The student will be automatically enrolled in all active units for the
          selected course, year, and semester (<?= htmlspecialchars($academicYear) ?> Sem <?= $semester ?>).
        </div>
      </div>

      <!-- Student picker -->
      <div class="form-group">
        <label class="form-label">Student <span class="required">*</span></label>
        <input type="text"
               id="en-student-search"
               class="form-control"
               placeholder="Type name or reg number..."
               autocomplete="off">
        <div id="en-student-results"
             style="border:1px solid var(--color-border);border-top:none;
                    border-radius:0 0 var(--radius-md) var(--radius-md);
                    max-height:180px;overflow-y:auto;display:none;background:white">
        </div>
        <input type="hidden" id="en-student-id">
        <div id="en-student-selected" class="text-sm text-accent"
             style="margin-top:var(--space-2)"></div>
      </div>

      <!-- Course + Year row -->
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Course <span class="required">*</span></label>
          <select id="en-course" class="form-control">
            <option value="">— Select course —</option>
            <?php foreach ($courses as $c): ?>
              <option value="<?= $c['id'] ?>">
                <?= htmlspecialchars($c['code']) ?> — <?= htmlspecialchars($c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Year of Study <span class="required">*</span></label>
          <select id="en-year" class="form-control">
            <option value="">— Year —</option>
            <?php for($y=1;$y<=6;$y++): ?>
              <option value="<?= $y ?>">Year <?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('enroll-modal')">Cancel</button>
      <button class="btn btn-primary" id="enroll-btn" onclick="enrollStudent()">
        Enroll Student
      </button>
    </div>
  </div>
</div>


<!-- ── Bulk Enroll Modal ─────────────────────────────────────────────────── -->
<div class="modal-backdrop" id="bulk-modal" hidden>
  <div class="modal" style="max-width:560px">
    <div class="modal-header">
      <h2 class="modal-title">Bulk Enroll via CSV</h2>
      <button class="modal-close" onclick="closeModal('bulk-modal')">✕</button>
    </div>
    <div class="modal-body">
      <div data-error-container="bulk"
           class="alert alert-error" style="margin-bottom:var(--space-4)"></div>

      <div class="alert alert-info" style="margin-bottom:var(--space-5)">
        <span class="alert-icon">ℹ</span>
        <div>
          <strong>Academic year is set automatically</strong> from System Settings
          (<strong><?= htmlspecialchars($academicYear) ?></strong>).
          The CSV must contain one student per row with these columns:
          <br><br>
          <code style="font-size:12px;background:var(--color-bg-subtle);
                        padding:4px 8px;border-radius:4px;display:inline-block">
            reg_number, full_name, course_code, year_of_study, semester
          </code>
          <br><br>
          A header row is optional. Unknown reg numbers are skipped and reported.
          Students with no units yet are enrolled and auto-assigned when units are added.
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">CSV File <span class="required">*</span></label>
        <input type="file" id="bk-csv" class="form-control" accept=".csv,text/csv">
      </div>

      <!-- Result summary (shown after upload) -->
      <div id="bulk-summary" style="display:none;margin-top:var(--space-4)">
        <div id="bulk-summary-ok"
             class="alert alert-success" style="margin-bottom:var(--space-3)"></div>
        <div id="bulk-warnings" style="display:none;margin-bottom:var(--space-3)">
          <p class="text-sm font-medium" style="margin-bottom:var(--space-2)">⚠️ Warnings:</p>
          <ul id="bulk-warnings-list"
              style="font-size:12px;color:var(--color-text-muted);padding-left:var(--space-4);
                     list-style:disc;line-height:1.8"></ul>
        </div>
        <div id="bulk-errors" style="display:none">
          <p class="text-sm font-medium text-danger" style="margin-bottom:var(--space-2)">
            ❌ Rows skipped:
          </p>
          <ul id="bulk-errors-list"
              style="font-size:12px;color:var(--color-text-muted);padding-left:var(--space-4);
                     list-style:disc;line-height:1.8"></ul>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('bulk-modal')">Close</button>
      <button class="btn btn-primary" id="bulk-btn" onclick="bulkEnroll()">
        Upload &amp; Enroll
      </button>
    </div>
  </div>
</div>


<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
<script>
const BASE_URL  = <?= json_encode(BASE_URL) ?>;
const ACAD_YEAR = <?= json_encode($academicYear) ?>;
const SEMESTER  = <?= json_encode($semester) ?>;

// ── Modal helpers ─────────────────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).hidden = false; document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).hidden = true;  document.body.style.overflow=''; }
document.querySelectorAll('.modal-backdrop').forEach(b => {
  b.addEventListener('click', e => { if (e.target === b) closeModal(b.id); });
});
function getErr(k) { return document.querySelector(`[data-error-container="${k}"]`); }
function setErr(k,m){ const e=getErr(k); e.textContent=m; e.hidden=false; }
function clearErr(k){ const e=getErr(k); e.textContent=''; e.hidden=true; }
function escHtml(s) {
  return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Student typeahead ─────────────────────────────────────────────────────────
(function() {
  let timer = null;
  const searchEl   = document.getElementById('en-student-search');
  const resultsEl  = document.getElementById('en-student-results');
  const hiddenEl   = document.getElementById('en-student-id');
  const selectedEl = document.getElementById('en-student-selected');

  searchEl.addEventListener('input', function() {
    const q = this.value.trim();
    clearTimeout(timer);
    if (q.length < 2) { resultsEl.style.display='none'; return; }
    timer = setTimeout(async () => {
      try {
        const data = await Api.get(`${BASE_URL}/api/admin/students_search.php`, { q });
        const list = data.students || [];
        resultsEl.innerHTML = list.length
          ? list.map(s =>
              `<div onclick="pickStudent(${s.id},'${escHtml(s.reg_number)}','${escHtml(s.full_name)}')"
                   style="padding:10px 14px;cursor:pointer;font-size:13px;
                          border-bottom:1px solid var(--color-border-light)"
                   onmouseenter="this.style.background='var(--color-bg-subtle)'"
                   onmouseleave="this.style.background=''">
                <strong>${escHtml(s.reg_number)}</strong> — ${escHtml(s.full_name)}
              </div>`).join('')
          : '<div style="padding:12px;color:var(--color-text-muted);font-size:13px">No students found</div>';
        resultsEl.style.display = 'block';
      } catch {}
    }, 300);
  });

  window.pickStudent = function(id, reg, name) {
    hiddenEl.value         = id;
    searchEl.value         = `${reg} — ${name}`;
    selectedEl.textContent = `✓ ${reg} — ${name}`;
    resultsEl.style.display = 'none';
  };
})();

// ── Open modals ───────────────────────────────────────────────────────────────
function openEnrollModal() {
  clearErr('enroll');
  document.getElementById('en-student-search').value   = '';
  document.getElementById('en-student-id').value       = '';
  document.getElementById('en-student-selected').textContent = '';
  document.getElementById('en-student-results').style.display = 'none';
  document.getElementById('en-course').value = '';
  document.getElementById('en-year').value   = '';
  openModal('enroll-modal');
  setTimeout(() => document.getElementById('en-student-search').focus(), 100);
}

function openBulkModal() {
  clearErr('bulk');
  document.getElementById('bk-csv').value = null;
  document.getElementById('bulk-summary').style.display = 'none';
  openModal('bulk-modal');
}

// ── Enroll single student in course ──────────────────────────────────────────
async function enrollStudent() {
  clearErr('enroll');
  const studentId = document.getElementById('en-student-id').value;
  const courseId  = document.getElementById('en-course').value;
  const year      = document.getElementById('en-year').value;
  const btn       = document.getElementById('enroll-btn');

  if (!studentId) { setErr('enroll','Please search for and select a student.'); return; }
  if (!courseId)  { setErr('enroll','Please select a course.');                 return; }
  if (!year)      { setErr('enroll','Please select year of study.');            return; }

  await Api.withLoading(btn, async () => {
    try {
      const data = await Api.post(`${BASE_URL}/api/admin/enrollment_add.php`, {
        student_id:    parseInt(studentId),
        course_id:     parseInt(courseId),
        year_of_study: parseInt(year),
        academic_year: ACAD_YEAR,
        semester:      parseInt(SEMESTER),
      });
      Toast.show('success', data.message || 'Student enrolled successfully.');
      closeModal('enroll-modal');
      setTimeout(() => window.location.reload(), 700);
    } catch (err) { setErr('enroll', err.message); }
  });
}

// ── Bulk enroll via CSV ───────────────────────────────────────────────────────
async function bulkEnroll() {
  clearErr('bulk');
  const csvFile = document.getElementById('bk-csv').files[0];
  const btn     = document.getElementById('bulk-btn');

  if (!csvFile) { setErr('bulk', 'Please select a CSV file.'); return; }

  const formData = new FormData();
  formData.append('csv_file', csvFile);

  // Hide previous summary
  document.getElementById('bulk-summary').style.display = 'none';

  await Api.withLoading(btn, async () => {
    try {
      const data = await Api.upload(`${BASE_URL}/api/admin/enrollment_bulk.php`, formData);

      // Show summary inside modal
      const summaryDiv  = document.getElementById('bulk-summary');
      const summaryOk   = document.getElementById('bulk-summary-ok');
      const warnDiv     = document.getElementById('bulk-warnings');
      const warnList    = document.getElementById('bulk-warnings-list');
      const errDiv      = document.getElementById('bulk-errors');
      const errList     = document.getElementById('bulk-errors-list');

      summaryOk.textContent = data.message;
      summaryDiv.style.display = 'block';

      if (data.warnings && data.warnings.length) {
        warnList.innerHTML = data.warnings.map(w =>
          `<li><strong>${escHtml(w.reg_number)}</strong> (${escHtml(w.course_code)}): ${escHtml(w.reason)}</li>`
        ).join('');
        warnDiv.style.display = 'block';
      } else {
        warnDiv.style.display = 'none';
      }

      if (data.errors && data.errors.length) {
        errList.innerHTML = data.errors.map(e =>
          `<li>Row ${e.row} — <strong>${escHtml(e.reg_number)}</strong>: ${escHtml(e.reason)}</li>`
        ).join('');
        errDiv.style.display = 'block';
      } else {
        errDiv.style.display = 'none';
      }

      if (data.enrolled > 0) {
        setTimeout(() => window.location.reload(), 3000);
      }
    } catch (err) { setErr('bulk', err.message); }
  });
}

// ── Unenroll (course-level) ───────────────────────────────────────────────────
async function unenroll(sceId, studentId, courseId, studentName, courseCode) {
  if (!confirm(
    `Remove ${studentName} from ${courseCode}?\n\n` +
    `This also removes all unit enrollments for this course this semester. ` +
    `Attendance and marks records are kept.`
  )) return;

  try {
    await Api.post(`${BASE_URL}/api/admin/enrollment_remove.php`, {
      sce_id:        sceId,
      student_id:    studentId,
      course_id:     courseId,
      academic_year: ACAD_YEAR,
      semester:      SEMESTER,
    });
    Toast.show('success', `${studentName} unenrolled from ${courseCode}.`);
    const row = document.getElementById(`enroll-row-${sceId}`);
    if (row) {
      row.style.opacity = '0';
      row.style.transition = 'opacity 0.3s ease';
      setTimeout(() => row.remove(), 300);
    }
  } catch (err) {
    Api.showError(err);
  }
}
</script>

</body>
</html>
