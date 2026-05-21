<?php
/**
 * EduTrack — Student Transcript Page
 *
 * Displays the student's official academic summary:
 *   - All published grades from vw_unit_grades
 *   - GPA calculation
 *   - PDF download link
 */

define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/MarksModel.php';

Auth::startSession();
Auth::requireRole('student');

$user = Auth::user();

$academicYear = DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year'"
)['setting_value'] ?? ACADEMIC_YEAR;

$semester = (int)(DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'active_semester'"
)['setting_value'] ?? ACTIVE_SEMESTER);

$schoolName = DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'school_name'"
)['setting_value'] ?? SCHOOL_NAME;

$transcript = MarksModel::getStudentTranscript($user['id']);

$csrfToken = Auth::csrfToken();
$pageTitle = 'My Transcript';
?>
<!DOCTYPE html>
<html lang="en" data-base-url="<?= htmlspecialchars(BASE_URL) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
  <title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/base.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/student.css">
</head>
<body>
<div class="layout">

  <?php include __DIR__ . '/../partials/sidebar_student.php'; ?>

  <div class="main">
    <header class="topbar">
      <span class="topbar-title">My Transcript</span>
      <div class="topbar-actions">
        <a href="<?= BASE_URL ?>/api/reports/transcript.php?student_id=<?= $user['id'] ?>"
           class="btn btn-primary btn-sm"
           target="_blank">
          ⬇️ Download PDF
        </a>
      </div>
    </header>

    <div class="page-content">

      <!-- Transcript card -->
      <div class="card animate-fade-in"
           style="max-width:700px;margin:0 auto">

        <!-- Header -->
        <div style="text-align:center;padding:var(--space-6) 0 var(--space-5);
                    border-bottom:2px solid var(--color-primary)">
          <div style="font-size:var(--text-xl);font-weight:var(--weight-bold);
                      color:var(--color-primary);margin-bottom:var(--space-1)">
            <?= htmlspecialchars($schoolName) ?>
          </div>
          <div style="font-size:var(--text-sm);color:var(--color-text-secondary)">
            <?= htmlspecialchars(APP_NAME) ?> — Academic Transcript
          </div>
        </div>

        <!-- Student info -->
        <div style="display:grid;grid-template-columns:1fr 1fr;
                    gap:var(--space-4);padding:var(--space-5) 0;
                    border-bottom:1px solid var(--color-border-light)">
          <div>
            <div class="text-xs text-muted" style="margin-bottom:2px">Student Name</div>
            <div class="font-semibold"><?= htmlspecialchars($user['full_name']) ?></div>
          </div>
          <div>
            <div class="text-xs text-muted" style="margin-bottom:2px">Registration No.</div>
            <div class="font-mono font-semibold">
              <?= htmlspecialchars($user['reg_number']) ?>
            </div>
          </div>
          <div>
            <div class="text-xs text-muted" style="margin-bottom:2px">Academic Year</div>
            <div class="font-medium"><?= htmlspecialchars($academicYear) ?></div>
          </div>
          <div>
            <div class="text-xs text-muted" style="margin-bottom:2px">Semester</div>
            <div class="font-medium">Semester <?= $semester ?></div>
          </div>
        </div>

        <!-- Grades table -->
        <?php if (empty($transcript['units'])): ?>
          <div class="empty-state" style="padding:var(--space-10) 0">
            <span class="empty-icon">📝</span>
            <p class="empty-title">No grades available</p>
            <p class="empty-text">
              Grades will appear here once all assessments have been
              published and marked by your lecturers.
            </p>
          </div>

        <?php else: ?>
          <div class="table-wrap" style="margin:var(--space-5) 0">
            <table class="table">
              <thead>
                <tr>
                  <th>Unit Code</th>
                  <th>Unit Name</th>
                  <th style="text-align:center">Total</th>
                  <th style="text-align:center">Grade</th>
                  <th style="text-align:center">Points</th>
                  <th>Remark</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($transcript['units'] as $unit): ?>
                  <tr>
                    <td class="font-mono text-xs font-semibold text-accent">
                      <?= htmlspecialchars($unit['unit_code']) ?>
                    </td>
                    <td class="text-sm">
                      <?= htmlspecialchars($unit['unit_name']) ?>
                    </td>
                    <td class="text-center font-mono font-semibold">
                      <?= number_format((float)$unit['weighted_total'], 2) ?>
                    </td>
                    <td class="text-center">
                      <span class="grade-pill grade-<?= $unit['grade'] ?>">
                        <?= $unit['grade'] ?>
                      </span>
                    </td>
                    <td class="text-center font-mono text-sm">
                      <?= number_format((float)$unit['grade_points'], 1) ?>
                    </td>
                    <td class="text-sm text-muted">
                      <?= htmlspecialchars($unit['remark']) ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- GPA -->
          <?php if ($transcript['gpa'] !== null): ?>
            <div style="background:var(--color-bg-subtle);
                        border:1.5px solid var(--color-border);
                        border-radius:var(--radius-lg);
                        padding:var(--space-4) var(--space-5);
                        display:flex;align-items:center;
                        justify-content:space-between;
                        margin-bottom:var(--space-5)">
              <div>
                <div class="text-sm font-semibold color-primary">
                  Cumulative Grade Point Average
                </div>
                <div class="text-xs text-muted">
                  Based on <?= $transcript['total_units'] ?> graded unit(s)
                </div>
              </div>
              <div style="text-align:right">
                <div style="font-family:var(--font-heading);
                            font-size:var(--text-3xl);
                            color:var(--color-primary);
                            line-height:1">
                  <?= number_format((float)$transcript['gpa'], 2) ?>
                </div>
                <div class="text-xs text-muted">out of 4.00</div>
              </div>
            </div>
          <?php endif; ?>

          <!-- Disclaimer -->
          <div class="text-xs text-muted"
               style="text-align:center;padding:var(--space-4) 0;
                      border-top:1px solid var(--color-border-light)">
            This is a system-generated record. For an official certified
            transcript, contact the school registrar's office.
            Generated <?= date('d M Y, H:i') ?>.
          </div>
        <?php endif; ?>

      </div><!-- /card -->

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->

<nav class="mobile-nav">
  <a href="<?= BASE_URL ?>/public/student/dashboard.php" class="mobile-nav-item">
    <span class="nav-icon">🏠</span><span>Home</span>
  </a>
  <a href="<?= BASE_URL ?>/public/student/scan.php" class="mobile-nav-item">
    <span class="nav-icon">📷</span><span>Scan</span>
  </a>
  <a href="<?= BASE_URL ?>/public/student/attendance.php" class="mobile-nav-item">
    <span class="nav-icon">📋</span><span>Attendance</span>
  </a>
  <a href="<?= BASE_URL ?>/public/student/marks.php" class="mobile-nav-item active">
    <span class="nav-icon">📝</span><span>Marks</span>
  </a>
</nav>

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
</body>
</html>