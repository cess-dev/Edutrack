<?php
/**
 * EduTrack — Admin Reports Page
 *
 * Central hub for generating and downloading system-wide reports:
 *   - School-wide attendance summary
 *   - At-risk students list
 *   - Unit attendance summaries (per unit)
 *   - Student transcripts
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

Auth::startSession();
Auth::requireRole('admin');

$user = Auth::user();

$academicYear = DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year'"
)['setting_value'] ?? ACADEMIC_YEAR;

$semester = (int)(DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'active_semester'"
)['setting_value'] ?? ACTIVE_SEMESTER);

$threshold = (int)(DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'attendance_threshold'"
)['setting_value'] ?? ATTENDANCE_ALERT_THRESHOLD);

// All active units for report generation
$units = DB::rows(
    "SELECT u.id, u.code, u.name, lec.full_name AS lecturer_name,
            c.code AS course_code
     FROM units u
     JOIN courses c ON c.id = u.course_id
     LEFT JOIN users lec ON lec.id = u.lecturer_id
     WHERE u.is_active = 1
     ORDER BY c.code ASC, u.code ASC"
);

// At-risk summary (school-wide count)
$atRiskCount = (int)(DB::row(
    "SELECT COUNT(DISTINCT student_id) AS cnt
     FROM vw_attendance_summary
     WHERE attendance_percent < ?
       AND academic_year = ? AND semester = ?
       AND total_sessions > 0",
    [$threshold, $academicYear, $semester]
)['cnt'] ?? 0);

// Active students count
$studentCount = (int)(DB::row(
    "SELECT COUNT(*) AS cnt FROM users WHERE role = 'student' AND is_active = 1"
)['cnt'] ?? 0);

$csrfToken = Auth::csrfToken();
$pageTitle = 'Reports';
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
      <span class="topbar-title">Reports</span>
      <div class="topbar-actions">
        <span class="text-sm text-muted">
          <?= htmlspecialchars($academicYear) ?> · Sem <?= $semester ?>
        </span>
      </div>
    </header>

    <div class="page-content">

      <!-- Context strip -->
      <div class="alert alert-info animate-fade-in"
           style="margin-bottom:var(--space-6)">
        <span class="alert-icon">ℹ</span>
        <div>
          Reports use data from
          <strong><?= htmlspecialchars($academicYear) ?></strong>,
          Semester <strong><?= $semester ?></strong>.
          Attendance threshold: <strong><?= $threshold ?>%</strong>.
          <?php if ($atRiskCount > 0): ?>
            &nbsp;·&nbsp;
            <span style="color:var(--color-error)">
              <strong><?= $atRiskCount ?></strong> student(s) currently at risk.
            </span>
          <?php endif; ?>
        </div>
      </div>

      <!-- ── Unit Attendance Reports ─────────────────────────────────── -->
      <div class="card animate-fade-in" style="margin-bottom:var(--space-6)">
        <div class="card-header">
          <div>
            <div class="card-title">Unit Attendance Summaries</div>
            <div class="card-subtitle">
              Download a PDF attendance summary for any unit
            </div>
          </div>
        </div>

        <?php if (empty($units)): ?>
          <div class="empty-state" style="padding:var(--space-8) 0">
            <span class="empty-icon">📚</span>
            <p class="empty-text">No active units found.</p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>Unit Code</th>
                  <th>Unit Name</th>
                  <th>Course</th>
                  <th>Lecturer</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($units as $u): ?>
                  <tr>
                    <td class="font-mono text-xs font-semibold text-accent">
                      <?= htmlspecialchars($u['code']) ?>
                    </td>
                    <td class="text-sm"><?= htmlspecialchars($u['name']) ?></td>
                    <td class="text-xs text-muted">
                      <?= htmlspecialchars($u['course_code']) ?>
                    </td>
                    <td class="text-sm text-muted">
                      <?= $u['lecturer_name']
                          ? htmlspecialchars($u['lecturer_name'])
                          : '<span class="text-muted">Unassigned</span>' ?>
                    </td>
                    <td>
                      <a href="<?= BASE_URL ?>/api/reports/class_report.php?unit_id=<?= $u['id'] ?>"
                         class="btn btn-secondary btn-sm"
                         target="_blank">
                        🖨️ Download PDF
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <!-- ── Student Transcripts ─────────────────────────────────────── -->
      <div class="card animate-fade-in" style="animation-delay:0.1s">
        <div class="card-header">
          <div>
            <div class="card-title">Student Transcripts</div>
            <div class="card-subtitle">
              Search for a student to download their transcript
            </div>
          </div>
        </div>

        <div style="display:flex;gap:var(--space-3);max-width:480px;
                    margin-bottom:var(--space-4)">
          <input type="text"
                 id="transcript-search"
                 class="form-control"
                 placeholder="Type student name or reg number..."
                 autocomplete="off"
                 oninput="searchTranscript(this.value)">
        </div>

        <div id="transcript-results"></div>

        <div class="text-xs text-muted">
          Only active students are searchable. Transcripts include all published grades.
        </div>
      </div>

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
<script>
const BASE_URL = <?= json_encode(BASE_URL) ?>;

let searchTimer = null;

function escHtml(s) {
  return String(s ?? '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

async function searchTranscript(q) {
  clearTimeout(searchTimer);
  const resultsEl = document.getElementById('transcript-results');

  if (q.trim().length < 2) {
    resultsEl.innerHTML = '';
    return;
  }

  searchTimer = setTimeout(async () => {
    try {
      const data = await Api.get(`${BASE_URL}/api/admin/students_search.php`, { q });
      const students = data.students || [];

      if (!students.length) {
        resultsEl.innerHTML =
          '<p class="text-sm text-muted">No students found.</p>';
        return;
      }

      resultsEl.innerHTML = `
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Reg. Number</th>
                <th>Name</th>
                <th>Transcript</th>
              </tr>
            </thead>
            <tbody>
              ${students.map(s => `
                <tr>
                  <td class="font-mono text-xs">${escHtml(s.reg_number)}</td>
                  <td class="text-sm">${escHtml(s.full_name)}</td>
                  <td>
                    <a href="${BASE_URL}/api/reports/transcript.php?student_id=${s.id}"
                       class="btn btn-secondary btn-sm"
                       target="_blank">
                      ⬇️ Download PDF
                    </a>
                  </td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>`;
    } catch {
      resultsEl.innerHTML =
        '<p class="text-sm text-muted">Search failed. Please try again.</p>';
    }
  }, 300);
}
</script>

</body>
</html>