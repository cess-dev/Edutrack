<?php
/**
 * EduTrack — Admin Courses & Units Management
 *
 * Allows the admin to:
 *   - Create and manage courses (programmes of study)
 *   - Add units (subjects) to each course
 *   - Assign lecturers to units
 *   - Activate / deactivate courses and units
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/UserModel.php';

Auth::startSession();
Auth::requireRole('admin');

$user = Auth::user();

// ── Load all courses with their units ─────────────────────────────────────────
$courses = DB::rows(
    "SELECT c.id, c.code, c.name, c.department, c.duration_years, c.is_active,
            COUNT(u.id) AS unit_count
     FROM courses c
     LEFT JOIN units u ON u.course_id = c.id AND u.is_active = 1
     GROUP BY c.id, c.code, c.name, c.department, c.duration_years, c.is_active
     ORDER BY c.is_active DESC, c.code ASC"
);

// ── Units per course (for the expanded tree view) ─────────────────────────────
$allUnits = DB::rows(
    "SELECT u.id, u.course_id, u.code, u.name, u.semester,
            u.year_of_study, u.credit_hours, u.is_active,
            lec.full_name AS lecturer_name,
            lec.id        AS lecturer_id,
            COUNT(e.id)   AS enrolled_count
     FROM units u
     LEFT JOIN users lec ON lec.id = u.lecturer_id
     LEFT JOIN enrollments e
           ON e.unit_id = u.id
          AND e.academic_year = (SELECT setting_value FROM system_settings
                                  WHERE setting_key = 'academic_year')
          AND e.semester      = (SELECT setting_value FROM system_settings
                                  WHERE setting_key = 'active_semester')
     GROUP BY u.id, u.course_id, u.code, u.name, u.semester,
              u.year_of_study, u.credit_hours, u.is_active,
              lec.full_name, lec.id
     ORDER BY u.year_of_study ASC, u.semester ASC, u.code ASC"
);

// Index units by course_id for easy rendering
$unitsByCourse = [];
foreach ($allUnits as $unit) {
    $unitsByCourse[$unit['course_id']][] = $unit;
}

// ── Lecturer options for dropdowns ────────────────────────────────────────────
$lecturers = UserModel::getLecturerOptions();

$csrfToken = Auth::csrfToken();
$pageTitle = 'Courses & Units';
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
      <span class="topbar-title">Courses &amp; Units</span>
      <div class="topbar-actions">
        <button class="btn btn-primary btn-sm" onclick="openCourseModal()">
          + New Course
        </button>
      </div>
    </header>

    <div class="page-content">

      <!-- Summary strip -->
      <div class="grid-stats animate-fade-in" style="margin-bottom:var(--space-6)">
        <div class="stat-card">
          <div class="stat-icon"
               style="background:var(--color-accent-light);color:var(--color-accent)">
            📚
          </div>
          <div class="stat-body">
            <div class="stat-value">
              <?= count(array_filter($courses, fn($c) => $c['is_active'])) ?>
            </div>
            <div class="stat-label">Active Courses</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon"
               style="background:#EEF0FB;color:#534AB7">
            📖
          </div>
          <div class="stat-body">
            <div class="stat-value">
              <?= count(array_filter($allUnits, fn($u) => $u['is_active'])) ?>
            </div>
            <div class="stat-label">Active Units</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon"
               style="background:var(--color-amber-light);color:var(--color-amber)">
            👨‍🏫
          </div>
          <div class="stat-body">
            <div class="stat-value"><?= count($lecturers) ?></div>
            <div class="stat-label">Lecturers Available</div>
          </div>
        </div>
      </div>

      <!-- Course tree -->
      <?php if (empty($courses)): ?>
        <div class="empty-state animate-fade-in">
          <span class="empty-icon">📚</span>
          <p class="empty-title">No courses yet</p>
          <p class="empty-text">
            Create your first course to start adding units and enrolling students.
          </p>
          <button class="btn btn-primary" onclick="openCourseModal()">
            + Create First Course
          </button>
        </div>

      <?php else: ?>
        <div class="course-tree animate-fade-in">
          <?php foreach ($courses as $course):
            $units = $unitsByCourse[$course['id']] ?? [];
          ?>
            <div class="course-node <?= !$course['is_active'] ? 'course-inactive' : '' ?>"
                 id="course-<?= $course['id'] ?>">

              <!-- Course header -->
              <div class="course-node-header">
                <div style="display:flex;align-items:center;gap:var(--space-3);flex:1">
                  <div class="course-code-badge">
                    <?= htmlspecialchars($course['code']) ?>
                  </div>
                  <div>
                    <div class="font-semibold text-sm">
                      <?= htmlspecialchars($course['name']) ?>
                    </div>
                    <div class="text-xs text-muted">
                      <?= $course['department']
                          ? htmlspecialchars($course['department']) . ' · '
                          : '' ?>
                      <?= $course['duration_years'] ?> year<?= $course['duration_years'] != 1 ? 's' : '' ?>
                      · <?= $course['unit_count'] ?> unit<?= $course['unit_count'] != 1 ? 's' : '' ?>
                    </div>
                  </div>
                </div>

                <div style="display:flex;align-items:center;gap:var(--space-3)">
                  <?php if (!$course['is_active']): ?>
                    <span class="badge badge-neutral">Inactive</span>
                  <?php endif; ?>

                  <button class="btn btn-primary btn-sm"
                          onclick="openUnitModal(<?= $course['id'] ?>,
                            '<?= htmlspecialchars($course['name'], ENT_QUOTES) ?>')">
                    + Add Unit
                  </button>
                  <button class="btn btn-ghost btn-sm"
                          title="Edit course"
                          onclick="openEditCourseModal(
                            <?= $course['id'] ?>,
                            '<?= htmlspecialchars($course['code'],   ENT_QUOTES) ?>',
                            '<?= htmlspecialchars($course['name'],   ENT_QUOTES) ?>',
                            '<?= htmlspecialchars($course['department'] ?? '', ENT_QUOTES) ?>',
                            <?= $course['duration_years'] ?>,
                            <?= $course['is_active'] ?>
                          )">
                    ✏️
                  </button>
                </div>
              </div>

              <!-- Units list -->
              <?php if (empty($units)): ?>
                <div class="text-sm text-muted"
                     style="padding:var(--space-5) var(--space-6)">
                  No units added yet.
                  <button class="btn btn-ghost btn-sm"
                          onclick="openUnitModal(<?= $course['id'] ?>,
                            '<?= htmlspecialchars($course['name'], ENT_QUOTES) ?>')">
                    + Add First Unit
                  </button>
                </div>

              <?php else: ?>
                <div class="course-node-units">
                  <?php foreach ($units as $unit): ?>
                    <div class="unit-node-row
                         <?= !$unit['is_active'] ? 'unit-inactive' : '' ?>">

                      <div style="min-width:90px">
                        <span class="font-mono text-xs font-semibold text-accent">
                          <?= htmlspecialchars($unit['code']) ?>
                        </span>
                      </div>

                      <div style="flex:1;overflow:hidden">
                        <div class="text-sm font-medium">
                          <?= htmlspecialchars($unit['name']) ?>
                        </div>
                        <div class="text-xs text-muted">
                          Year <?= $unit['year_of_study'] ?>
                          · Sem <?= $unit['semester'] ?>
                          · <?= $unit['credit_hours'] ?> credit<?= $unit['credit_hours'] != 1 ? 's' : '' ?>
                          · <?= $unit['enrolled_count'] ?> enrolled
                        </div>
                      </div>

                      <div style="min-width:140px;text-align:right">
                        <?php if ($unit['lecturer_name']): ?>
                          <span class="text-xs text-muted">
                            👨‍🏫 <?= htmlspecialchars($unit['lecturer_name']) ?>
                          </span>
                        <?php else: ?>
                          <span class="text-xs"
                                style="color:var(--color-amber)">
                            ⚠ No lecturer assigned
                          </span>
                        <?php endif; ?>
                      </div>

                      <?php if (!$unit['is_active']): ?>
                        <span class="badge badge-neutral">Inactive</span>
                      <?php endif; ?>

                      <div style="display:flex;gap:var(--space-2);flex-shrink:0">
                        <button class="btn btn-ghost btn-sm"
                                title="Edit unit"
                                onclick="openEditUnitModal(
                                  <?= $unit['id'] ?>,
                                  '<?= htmlspecialchars($unit['code'],   ENT_QUOTES) ?>',
                                  '<?= htmlspecialchars($unit['name'],   ENT_QUOTES) ?>',
                                  <?= $unit['semester'] ?>,
                                  <?= $unit['year_of_study'] ?>,
                                  <?= $unit['credit_hours'] ?>,
                                  <?= $unit['lecturer_id'] ?? 'null' ?>,
                                  <?= $unit['is_active'] ?>
                                )">
                          ✏️
                        </button>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

            </div><!-- /course-node -->
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->


<!-- ── Create Course Modal ───────────────────────────────────────────────── -->
<div class="modal-backdrop" id="course-modal" hidden>
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title" id="course-modal-title">New Course</h2>
      <button class="modal-close" onclick="closeModal('course-modal')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="cm-id">
      <div data-error-container="course"
           class="alert alert-error" style="margin-bottom:var(--space-4)"></div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">
            Course Code <span class="required">*</span>
          </label>
          <input type="text" id="cm-code" class="form-control"
                 placeholder="e.g. BCS" autocapitalize="characters" maxlength="20">
        </div>
        <div class="form-group">
          <label class="form-label">
            Duration (years) <span class="required">*</span>
          </label>
          <input type="number" id="cm-years" class="form-control"
                 value="4" min="1" max="6">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">
          Course Name <span class="required">*</span>
        </label>
        <input type="text" id="cm-name" class="form-control"
               placeholder="e.g. Bachelor of Computer Science" maxlength="150">
      </div>
      <div class="form-group">
        <label class="form-label">Department / School</label>
        <input type="text" id="cm-dept" class="form-control"
               placeholder="e.g. School of Computing" maxlength="100">
      </div>
      <div class="form-group" id="cm-active-wrap" style="display:none">
        <label class="form-label"
               style="display:flex;align-items:center;gap:var(--space-3);cursor:pointer">
          <input type="checkbox" id="cm-active" style="width:16px;height:16px">
          <span>Active (visible to lecturers and students)</span>
        </label>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary"
              onclick="closeModal('course-modal')">Cancel</button>
      <button class="btn btn-primary" id="course-save-btn"
              onclick="saveCourse()">Create Course</button>
    </div>
  </div>
</div>


<!-- ── Create / Edit Unit Modal ──────────────────────────────────────────── -->
<div class="modal-backdrop" id="unit-modal" hidden>
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title" id="unit-modal-title">Add Unit</h2>
      <button class="modal-close" onclick="closeModal('unit-modal')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="um-id">
      <input type="hidden" id="um-course-id">
      <div data-error-container="unit"
           class="alert alert-error" style="margin-bottom:var(--space-4)"></div>

      <div class="alert alert-info" id="um-course-label"
           style="margin-bottom:var(--space-4)">
        <span class="alert-icon">📚</span>
        <span id="um-course-name-label"></span>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">
            Unit Code <span class="required">*</span>
          </label>
          <input type="text" id="um-code" class="form-control"
                 placeholder="e.g. BCS101" autocapitalize="characters" maxlength="20">
        </div>
        <div class="form-group">
          <label class="form-label">
            Credit Hours <span class="required">*</span>
          </label>
          <input type="number" id="um-credits" class="form-control"
                 value="3" min="1" max="12">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">
          Unit Name <span class="required">*</span>
        </label>
        <input type="text" id="um-name" class="form-control"
               placeholder="e.g. Introduction to Programming" maxlength="150">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">
            Year of Study <span class="required">*</span>
          </label>
          <select id="um-year" class="form-control">
            <?php for ($y = 1; $y <= 6; $y++): ?>
              <option value="<?= $y ?>">Year <?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">
            Semester <span class="required">*</span>
          </label>
          <select id="um-semester" class="form-control">
            <option value="1">Semester 1</option>
            <option value="2">Semester 2</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">
          Assign Lecturer
        </label>
        <select id="um-lecturer" class="form-control">
          <option value="">— No lecturer assigned —</option>
          <?php foreach ($lecturers as $lec): ?>
            <option value="<?= $lec['id'] ?>">
              <?= htmlspecialchars($lec['full_name']) ?>
              (<?= htmlspecialchars($lec['reg_number']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" id="um-active-wrap" style="display:none">
        <label class="form-label"
               style="display:flex;align-items:center;gap:var(--space-3);cursor:pointer">
          <input type="checkbox" id="um-active" checked style="width:16px;height:16px">
          <span>Active</span>
        </label>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary"
              onclick="closeModal('unit-modal')">Cancel</button>
      <button class="btn btn-primary" id="unit-save-btn"
              onclick="saveUnit()">Add Unit</button>
    </div>
  </div>
</div>


<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
<script>
const BASE_URL = <?= json_encode(BASE_URL) ?>;

let courseEditMode = false;
let unitEditMode   = false;

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
function getErr(key) {
  return document.querySelector(`[data-error-container="${key}"]`);
}
function setErr(key, msg) {
  const el = getErr(key); el.textContent = msg; el.hidden = false;
}
function clearErr(key) {
  const el = getErr(key); el.textContent = ''; el.hidden = true;
}

// ── Course modal ──────────────────────────────────────────────────────────────
function openCourseModal() {
  courseEditMode = false;
  clearErr('course');
  document.getElementById('course-modal-title').textContent = 'New Course';
  document.getElementById('course-save-btn').textContent    = 'Create Course';
  document.getElementById('cm-id').value    = '';
  document.getElementById('cm-code').value  = '';
  document.getElementById('cm-name').value  = '';
  document.getElementById('cm-dept').value  = '';
  document.getElementById('cm-years').value = '4';
  document.getElementById('cm-active-wrap').style.display = 'none';
  openModal('course-modal');
  setTimeout(() => document.getElementById('cm-code').focus(), 100);
}

function openEditCourseModal(id, code, name, dept, years, isActive) {
  courseEditMode = true;
  clearErr('course');
  document.getElementById('course-modal-title').textContent = 'Edit Course';
  document.getElementById('course-save-btn').textContent    = 'Save Changes';
  document.getElementById('cm-id').value    = id;
  document.getElementById('cm-code').value  = code;
  document.getElementById('cm-name').value  = name;
  document.getElementById('cm-dept').value  = dept;
  document.getElementById('cm-years').value = years;
  document.getElementById('cm-active').checked = !!isActive;
  document.getElementById('cm-active-wrap').style.display = 'block';
  openModal('course-modal');
}

async function saveCourse() {
  clearErr('course');
  const id    = document.getElementById('cm-id').value;
  const code  = document.getElementById('cm-code').value.trim();
  const name  = document.getElementById('cm-name').value.trim();
  const dept  = document.getElementById('cm-dept').value.trim();
  const years = parseInt(document.getElementById('cm-years').value);
  const active= document.getElementById('cm-active').checked;
  const btn   = document.getElementById('course-save-btn');

  if (!code || !name) {
    setErr('course', 'Course code and name are required.');
    return;
  }

  const endpoint = courseEditMode
    ? `${BASE_URL}/api/admin/course_update.php`
    : `${BASE_URL}/api/admin/course_create.php`;

  const payload = courseEditMode
    ? { course_id: parseInt(id), code, name, department: dept,
        duration_years: years, is_active: active ? 1 : 0 }
    : { code, name, department: dept, duration_years: years };

  await Api.withLoading(btn, async () => {
    try {
      await Api.post(endpoint, payload);
      Toast.show('success', courseEditMode ? 'Course updated.' : 'Course created.');
      closeModal('course-modal');
      setTimeout(() => window.location.reload(), 700);
    } catch (err) { setErr('course', err.message); }
  });
}

// ── Unit modal ────────────────────────────────────────────────────────────────
function openUnitModal(courseId, courseName) {
  unitEditMode = false;
  clearErr('unit');
  document.getElementById('unit-modal-title').textContent = 'Add Unit';
  document.getElementById('unit-save-btn').textContent    = 'Add Unit';
  document.getElementById('um-id').value       = '';
  document.getElementById('um-course-id').value= courseId;
  document.getElementById('um-course-name-label').textContent =
    'Adding unit to: ' + courseName;
  document.getElementById('um-code').value     = '';
  document.getElementById('um-name').value     = '';
  document.getElementById('um-credits').value  = '3';
  document.getElementById('um-year').value     = '1';
  document.getElementById('um-semester').value = '1';
  document.getElementById('um-lecturer').value = '';
  document.getElementById('um-active-wrap').style.display = 'none';
  openModal('unit-modal');
  setTimeout(() => document.getElementById('um-code').focus(), 100);
}

function openEditUnitModal(id, code, name, semester, year, credits, lecturerId, isActive) {
  unitEditMode = true;
  clearErr('unit');
  document.getElementById('unit-modal-title').textContent = 'Edit Unit';
  document.getElementById('unit-save-btn').textContent    = 'Save Changes';
  document.getElementById('um-id').value        = id;
  document.getElementById('um-code').value      = code;
  document.getElementById('um-name').value      = name;
  document.getElementById('um-semester').value  = semester;
  document.getElementById('um-year').value      = year;
  document.getElementById('um-credits').value   = credits;
  document.getElementById('um-lecturer').value  = lecturerId || '';
  document.getElementById('um-active').checked  = !!isActive;
  document.getElementById('um-active-wrap').style.display = 'block';
  document.getElementById('um-course-label').style.display = 'none';
  openModal('unit-modal');
}

async function saveUnit() {
  clearErr('unit');
  const id        = document.getElementById('um-id').value;
  const courseId  = document.getElementById('um-course-id').value;
  const code      = document.getElementById('um-code').value.trim();
  const name      = document.getElementById('um-name').value.trim();
  const semester  = parseInt(document.getElementById('um-semester').value);
  const year      = parseInt(document.getElementById('um-year').value);
  const credits   = parseInt(document.getElementById('um-credits').value);
  const lecturerId= document.getElementById('um-lecturer').value;
  const active    = document.getElementById('um-active').checked;
  const btn       = document.getElementById('unit-save-btn');

  if (!code || !name) {
    setErr('unit', 'Unit code and name are required.');
    return;
  }

  const endpoint = unitEditMode
    ? `${BASE_URL}/api/admin/unit_update.php`
    : `${BASE_URL}/api/admin/unit_create.php`;

  const payload = unitEditMode
    ? { unit_id: parseInt(id), code, name, semester, year_of_study: year,
        credit_hours: credits,
        lecturer_id: lecturerId ? parseInt(lecturerId) : null,
        is_active: active ? 1 : 0 }
    : { course_id: parseInt(courseId), code, name, semester,
        year_of_study: year, credit_hours: credits,
        lecturer_id: lecturerId ? parseInt(lecturerId) : null };

  await Api.withLoading(btn, async () => {
    try {
      await Api.post(endpoint, payload);
      Toast.show('success', unitEditMode ? 'Unit updated.' : 'Unit added.');
      closeModal('unit-modal');
      setTimeout(() => window.location.reload(), 700);
    } catch (err) { setErr('unit', err.message); }
  });
}
</script>

<style>
.course-code-badge {
  background: var(--color-accent-light);
  color: var(--color-accent);
  font-family: var(--font-mono);
  font-size: var(--text-xs);
  font-weight: var(--weight-bold);
  padding: var(--space-1) var(--space-3);
  border-radius: var(--radius-sm);
  letter-spacing: 0.04em;
  flex-shrink: 0;
}

.course-inactive { opacity: 0.55; }
.unit-inactive   { opacity: 0.55; background: var(--color-bg-subtle); }
</style>

</body>
</html>