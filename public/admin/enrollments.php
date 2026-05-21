<?php
/**
 * EduTrack — Admin Enrollment Management
 *
 * Allows the admin to:
 *   - View all enrollments for the active academic year / semester
 *   - Enroll a student in a unit
 *   - Bulk-enroll a student in all units for a course year/semester
 *   - Remove (unenroll) a student from a unit
 *   - Search enrollments by student or unit
 */

define('EDUTRACK_LOADED', true);
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
$filterUnit    = (int)($_GET['unit_id']    ?? 0);
$filterStudent = (int)($_GET['student_id'] ?? 0);
$search        = trim($_GET['search'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$perPage       = ROWS_PER_PAGE;
$offset        = ($page - 1) * $perPage;

// ── Build enrollment query ────────────────────────────────────────────────────
$conditions = ["e.academic_year = ?", "e.semester = ?"];
$params     = [$academicYear, $semester];

if ($filterUnit > 0) {
    $conditions[] = "e.unit_id = ?";
    $params[]     = $filterUnit;
}
if ($filterStudent > 0) {
    $conditions[] = "e.student_id = ?";
    $params[]     = $filterStudent;
}
if (!empty($search)) {
    $conditions[] = "(stu.full_name LIKE ? OR stu.reg_number LIKE ?)";
    $like         = '%' . $search . '%';
    $params[]     = $like;
    $params[]     = $like;
}

$where = 'WHERE ' . implode(' AND ', $conditions);

$totalRows = (int)(DB::row(
    "SELECT COUNT(*) AS cnt
     FROM enrollments e
     JOIN users stu ON stu.id = e.student_id
     {$where}",
    $params
)['cnt'] ?? 0);

$totalPages = max(1, (int)ceil($totalRows / $perPage));

$enrollments = DB::rows(
    "SELECT e.id, e.student_id, e.unit_id, e.enrolled_at,
            stu.reg_number, stu.full_name AS student_name,
            u.code AS unit_code, u.name AS unit_name,
            c.code AS course_code
     FROM enrollments e
     JOIN users stu ON stu.id  = e.student_id
     JOIN units u   ON u.id   = e.unit_id
     JOIN courses c ON c.id   = u.course_id
     {$where}
     ORDER BY stu.full_name ASC, u.code ASC
     LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);

// ── Dropdown data for modals ──────────────────────────────────────────────────
// Active units with course info
$units = DB::rows(
    "SELECT u.id, u.code, u.name, u.semester, u.year_of_study,
            c.code AS course_code, c.name AS course_name
     FROM units u
     JOIN courses c ON c.id = u.course_id
     WHERE u.is_active = 1
     ORDER BY c.code ASC, u.year_of_study ASC, u.semester ASC, u.code ASC"
);

// Courses for bulk enrollment
$courses = DB::rows(
    "SELECT id, code, name FROM courses WHERE is_active = 1 ORDER BY code ASC"
);

// ── Filter dropdowns ──────────────────────────────────────────────────────────
$filterUnits = DB::rows(
    "SELECT DISTINCT u.id, u.code, u.name
     FROM enrollments e
     JOIN units u ON u.id = e.unit_id
     WHERE e.academic_year = ? AND e.semester = ?
     ORDER BY u.code ASC",
    [$academicYear, $semester]
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
          📋 Bulk Enroll
        </button>
        <button class="btn btn-primary btn-sm" onclick="openEnrollModal()">
          + Enroll Student
        </button>
      </div>
    </header>

    <div class="page-content">

      <!-- Context banner -->
      <div class="alert alert-info animate-fade-in"
           style="margin-bottom:var(--space-5)">
        <span class="alert-icon">📅</span>
        <div>
          Showing enrollments for
          <strong><?= htmlspecialchars($academicYear) ?></strong>,
          Semester <strong><?= $semester ?></strong>.
          To change, update the academic year and semester in
          <a href="<?= BASE_URL ?>/public/admin/settings.php">System Settings</a>.
        </div>
      </div>

      <!-- Search and filter bar -->
      <div class="user-toolbar animate-fade-in">
        <form method="GET" style="display:flex;gap:var(--space-3);flex:1;flex-wrap:wrap">
          <input type="text"
                 name="search"
                 class="form-control user-search"
                 placeholder="Search by student name or reg number..."
                 value="<?= htmlspecialchars($search) ?>"
                 autocomplete="off">

          <select name="unit_id" class="form-control"
                  style="width:auto;min-width:200px"
                  onchange="this.form.submit()">
            <option value="0">All Units</option>
            <?php foreach ($filterUnits as $u): ?>
              <option value="<?= $u['id'] ?>"
                      <?= $filterUnit === $u['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($u['code']) ?> — <?= htmlspecialchars($u['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
          <?php if ($search || $filterUnit): ?>
            <a href="?" class="btn btn-ghost btn-sm">Clear</a>
          <?php endif; ?>
        </form>
        <span class="text-sm text-muted">
          <?= $totalRows ?> enrollment<?= $totalRows !== 1 ? 's' : '' ?>
        </span>
      </div>

      <!-- Enrollments table -->
      <div class="card animate-fade-in" style="animation-delay:0.1s">
        <?php if (empty($enrollments)): ?>
          <div class="empty-state" style="padding:var(--space-12) 0">
            <span class="empty-icon">📋</span>
            <p class="empty-title">No enrollments found</p>
            <p class="empty-text">
              <?= ($search || $filterUnit)
                  ? 'No results match the current filter. Try clearing it.'
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
                  <th>Unit</th>
                  <th>Course</th>
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
                        <?= htmlspecialchars($e['unit_code']) ?>
                      </span>
                      <div class="text-xs text-muted" style="margin-top:2px">
                        <?= htmlspecialchars($e['unit_name']) ?>
                      </div>
                    </td>
                    <td class="text-xs text-muted">
                      <?= htmlspecialchars($e['course_code']) ?>
                    </td>
                    <td class="text-xs text-muted">
                      <?= date('d M Y', strtotime($e['enrolled_at'])) ?>
                    </td>
                    <td>
                      <button class="btn btn-danger btn-sm"
                              onclick="unenroll(
                                <?= $e['id'] ?>,
                                '<?= htmlspecialchars($e['student_name'], ENT_QUOTES) ?>',
                                '<?= htmlspecialchars($e['unit_code'],    ENT_QUOTES) ?>'
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
            $baseQs = http_build_query(array_filter([
                'search'     => $search   ?: null,
                'unit_id'    => $filterUnit ?: null,
            ]));
            $sep = $baseQs ? '&' : '';
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
      <h2 class="modal-title">Enroll Student</h2>
      <button class="modal-close" onclick="closeModal('enroll-modal')">✕</button>
    </div>
    <div class="modal-body">
      <div data-error-container="enroll"
           class="alert alert-error" style="margin-bottom:var(--space-4)"></div>

      <div class="form-group">
        <label class="form-label">
          Student <span class="required">*</span>
        </label>
        <input type="text"
               id="en-student-search"
               class="form-control"
               placeholder="Type name or reg number..."
               autocomplete="off"
               oninput="searchEnrollStudents(this.value)">
        <div id="en-student-results"
             style="border:1px solid var(--color-border);border-top:none;
                    border-radius:0 0 var(--radius-md) var(--radius-md);
                    max-height:180px;overflow-y:auto;display:none;background:white">
        </div>
        <input type="hidden" id="en-student-id">
        <div id="en-student-selected"
             class="text-sm text-accent" style="margin-top:var(--space-2)">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">
          Unit <span class="required">*</span>
        </label>
        <select id="en-unit" class="form-control">
          <option value="">— Select unit —</option>
          <?php foreach ($units as $u): ?>
            <option value="<?= $u['id'] ?>">
              <?= htmlspecialchars($u['code']) ?> — <?= htmlspecialchars($u['name']) ?>
              (<?= htmlspecialchars($u['course_code']) ?>, Yr<?= $u['year_of_study'] ?> Sem<?= $u['semester'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="alert alert-info" style="margin-top:var(--space-2)">
        <span class="alert-icon">ℹ</span>
        <span>
          Enrolling for
          <strong><?= htmlspecialchars($academicYear) ?></strong>,
          Semester <strong><?= $semester ?></strong>.
        </span>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('enroll-modal')">
        Cancel
      </button>
      <button class="btn btn-primary" id="enroll-btn" onclick="enrollStudent()">
        Enroll Student
      </button>
    </div>
  </div>
</div>


<!-- ── Bulk Enroll Modal ─────────────────────────────────────────────────── -->
<div class="modal-backdrop" id="bulk-modal" hidden>
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title">Bulk Enroll by Course</h2>
      <button class="modal-close" onclick="closeModal('bulk-modal')">✕</button>
    </div>
    <div class="modal-body">
      <div data-error-container="bulk"
           class="alert alert-error" style="margin-bottom:var(--space-4)"></div>

      <div class="alert alert-info" style="margin-bottom:var(--space-4)">
        <span class="alert-icon">ℹ</span>
        <div>
          Enrolls a student in <strong>all active units</strong> for the
          selected course year and semester. Existing enrollments are skipped.
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">
          Student <span class="required">*</span>
        </label>
        <input type="text"
               id="bk-student-search"
               class="form-control"
               placeholder="Type name or reg number..."
               autocomplete="off"
               oninput="searchBulkStudents(this.value)">
        <div id="bk-student-results"
             style="border:1px solid var(--color-border);border-top:none;
                    border-radius:0 0 var(--radius-md) var(--radius-md);
                    max-height:180px;overflow-y:auto;display:none;background:white">
        </div>
        <input type="hidden" id="bk-student-id">
        <div id="bk-student-selected"
             class="text-sm text-accent" style="margin-top:var(--space-2)">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">
            Course <span class="required">*</span>
          </label>
          <select id="bk-course" class="form-control"
                  onchange="loadCourseYears(this.value)">
            <option value="">— Select course —</option>
            <?php foreach ($courses as $c): ?>
              <option value="<?= $c['id'] ?>">
                <?= htmlspecialchars($c['code']) ?> — <?= htmlspecialchars($c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">
            Year of Study <span class="required">*</span>
          </label>
          <select id="bk-year" class="form-control">
            <option value="">— Select year —</option>
            <?php for($y=1;$y<=6;$y++): ?>
              <option value="<?= $y ?>">Year <?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('bulk-modal')">
        Cancel
      </button>
      <button class="btn btn-primary" id="bulk-btn" onclick="bulkEnroll()">
        Enroll in All Units
      </button>
    </div>
  </div>
</div>


<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
<script>
const BASE_URL    = <?= json_encode(BASE_URL) ?>;
const ACAD_YEAR   = <?= json_encode($academicYear) ?>;
const SEMESTER    = <?= json_encode($semester) ?>;

// ── Modal helpers ─────────────────────────────────────────────────────────────
function openModal(id) {
  document.getElementById(id).hidden = false;
  document.body.style.overflow = 'hidden';
}
function closeModal(id) {
  document.getElementById(id).hidden = true;
  document.body.style.overflow = '';
}
document.querySelectorAll('.modal-backdrop').forEach(b => {
  b.addEventListener('click', e => { if (e.target === b) closeModal(b.id); });
});
function getErr(key) { return document.querySelector(`[data-error-container="${key}"]`); }
function setErr(key,msg){ const e=getErr(key); e.textContent=msg; e.hidden=false; }
function clearErr(key) { const e=getErr(key); e.textContent=''; e.hidden=true; }

function escHtml(s) {
  return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Student typeahead (shared logic) ──────────────────────────────────────────
function makeStudentSearch(searchId, resultsId, hiddenId, selectedId) {
  let timer = null;
  document.getElementById(searchId).addEventListener('input', function() {
    const q = this.value.trim();
    clearTimeout(timer);
    const resultsEl = document.getElementById(resultsId);
    if (q.length < 2) { resultsEl.style.display='none'; return; }
    timer = setTimeout(async () => {
      try {
        const data = await Api.get(`${BASE_URL}/api/admin/students_search.php`, { q });
        const students = data.students || [];
        if (!students.length) {
          resultsEl.innerHTML = '<div style="padding:12px;color:var(--color-text-muted);font-size:13px">No students found</div>';
        } else {
          resultsEl.innerHTML = students.map(s =>
            `<div onclick="selectStudent('${searchId}','${resultsId}','${hiddenId}','${selectedId}',
               ${s.id},'${escHtml(s.reg_number)} — ${escHtml(s.full_name)}')"
               style="padding:10px 14px;cursor:pointer;font-size:13px;
                      border-bottom:1px solid var(--color-border-light)"
               onmouseenter="this.style.background='var(--color-bg-subtle)'"
               onmouseleave="this.style.background=''">
               <strong>${escHtml(s.reg_number)}</strong> — ${escHtml(s.full_name)}
             </div>`
          ).join('');
        }
        resultsEl.style.display = 'block';
      } catch {}
    }, 300);
  });
}

function selectStudent(searchId, resultsId, hiddenId, selectedId, id, label) {
  document.getElementById(hiddenId).value      = id;
  document.getElementById(searchId).value      = label;
  document.getElementById(selectedId).textContent = '✓ Selected: ' + label;
  document.getElementById(resultsId).style.display = 'none';
}

// Wire up both search inputs
makeStudentSearch('en-student-search','en-student-results','en-student-id','en-student-selected');
makeStudentSearch('bk-student-search','bk-student-results','bk-student-id','bk-student-selected');

// ── Open modals ───────────────────────────────────────────────────────────────
function openEnrollModal() {
  clearErr('enroll');
  ['en-student-search','en-student-id','en-student-selected'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = ''; if (el) el.textContent = '';
  });
  document.getElementById('en-student-selected').textContent = '';
  document.getElementById('en-student-results').style.display = 'none';
  document.getElementById('en-unit').value = '';
  openModal('enroll-modal');
  setTimeout(() => document.getElementById('en-student-search').focus(), 100);
}

function openBulkModal() {
  clearErr('bulk');
  ['bk-student-search','bk-student-id'].forEach(id => {
    const el = document.getElementById(id); if(el) el.value='';
  });
  document.getElementById('bk-student-selected').textContent = '';
  document.getElementById('bk-student-results').style.display = 'none';
  document.getElementById('bk-course').value = '';
  document.getElementById('bk-year').value   = '';
  openModal('bulk-modal');
  setTimeout(() => document.getElementById('bk-student-search').focus(), 100);
}

// ── Enroll single ─────────────────────────────────────────────────────────────
async function enrollStudent() {
  clearErr('enroll');
  const studentId = document.getElementById('en-student-id').value;
  const unitId    = document.getElementById('en-unit').value;
  const btn       = document.getElementById('enroll-btn');

  if (!studentId) { setErr('enroll','Please search for and select a student.'); return; }
  if (!unitId)    { setErr('enroll','Please select a unit.');                   return; }

  await Api.withLoading(btn, async () => {
    try {
      await Api.post(`${BASE_URL}/api/admin/enrollment_add.php`, {
        student_id:    parseInt(studentId),
        unit_id:       parseInt(unitId),
        academic_year: ACAD_YEAR,
        semester:      parseInt(SEMESTER),
      });
      Toast.show('success', 'Student enrolled successfully.');
      closeModal('enroll-modal');
      setTimeout(() => window.location.reload(), 700);
    } catch (err) { setErr('enroll', err.message); }
  });
}

// ── Bulk enroll ───────────────────────────────────────────────────────────────
async function bulkEnroll() {
  clearErr('bulk');
  const studentId = document.getElementById('bk-student-id').value;
  const courseId  = document.getElementById('bk-course').value;
  const year      = document.getElementById('bk-year').value;
  const btn       = document.getElementById('bulk-btn');

  if (!studentId) { setErr('bulk','Please select a student.'); return; }
  if (!courseId)  { setErr('bulk','Please select a course.');  return; }
  if (!year)      { setErr('bulk','Please select a year.');    return; }

  await Api.withLoading(btn, async () => {
    try {
      const data = await Api.post(`${BASE_URL}/api/admin/enrollment_bulk.php`, {
        student_id:    parseInt(studentId),
        course_id:     parseInt(courseId),
        year_of_study: parseInt(year),
        academic_year: ACAD_YEAR,
        semester:      parseInt(SEMESTER),
      });
      Toast.show('success', data.message);
      closeModal('bulk-modal');
      setTimeout(() => window.location.reload(), 700);
    } catch (err) { setErr('bulk', err.message); }
  });
}

// ── Unenroll ──────────────────────────────────────────────────────────────────
async function unenroll(enrollmentId, studentName, unitCode) {
  if (!confirm(`Remove ${studentName} from ${unitCode}?\n\nThis does not delete attendance or marks records.`)) return;

  try {
    await Api.post(`${BASE_URL}/api/admin/enrollment_remove.php`, {
      enrollment_id: enrollmentId,
    });
    Toast.show('success', `${studentName} unenrolled from ${unitCode}.`);
    const row = document.getElementById(`enroll-row-${enrollmentId}`);
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