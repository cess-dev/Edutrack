<?php
/**
 * EduTrack — Parent Transcript Page
 *
 * Displays a linked child's full academic transcript for parents.
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/UserModel.php';
require_once __DIR__ . '/../../backend/models/MarksModel.php';

Auth::startSession();
Auth::requireRole('parent');

$user = Auth::user();
$children = UserModel::getLinkedStudents($user['id']);

if (empty($children)) {
    header('Location: ' . BASE_URL . '/parent/dashboard');
    exit;
}

$requestedId = (int)($_GET['student_id'] ?? $children[0]['id']);
$child = null;
foreach ($children as $c) {
    if ($c['id'] === $requestedId) {
        $child = $c;
        break;
    }
}
if (!$child) {
    $child = $children[0];
}

$academicYear = DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year'"
)['setting_value'] ?? ACADEMIC_YEAR;

$semester = (int)(DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'active_semester'"
)['setting_value'] ?? ACTIVE_SEMESTER);

$schoolName = DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'school_name'"
)['setting_value'] ?? SCHOOL_NAME;

$transcript = MarksModel::getStudentTranscript($child['id']);
$csrfToken = Auth::csrfToken();
$firstName = htmlspecialchars(explode(' ', $child['full_name'])[0]);
$pageTitle = "$firstName's Transcript";
?>
<!DOCTYPE html>
<html lang="en" data-base-url="<?= htmlspecialchars(BASE_URL) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
  <title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/base.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/parent.css">
</head>
<body>
<div class="layout">

  <?php include __DIR__ . '/../partials/sidebar_parent.php'; ?>

  <div class="main">
    <header class="topbar">
      <span class="topbar-title"><?= $firstName ?>'s Transcript</span>
      <div class="topbar-actions">
        <a href="<?= BASE_URL ?>/api/reports/transcript.php?student_id=<?= $child['id'] ?>"
           class="btn btn-primary btn-sm"
           target="_blank">
          ⬇️ Download PDF
        </a>
      </div>
    </header>

    <div class="page-content">
      <?php if (count($children) > 1): ?>
        <div class="parent-child-selector animate-fade-in">
          <span class="child-selector-label">Viewing:</span>
          <?php foreach ($children as $c): ?>
            <a href="?student_id=<?= $c['id'] ?>"
               class="filter-chip <?= $c['id'] === $child['id'] ? 'active' : '' ?>">
              <?= htmlspecialchars(explode(' ', $c['full_name'])[0]) ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="card animate-fade-in" style="max-width:760px;margin:0 auto">

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

        <div style="display:grid;grid-template-columns:1fr 1fr;
                    gap:var(--space-4);padding:var(--space-5) 0;
                    border-bottom:1px solid var(--color-border-light)">
          <div>
            <div class="text-xs text-muted" style="margin-bottom:2px">Student Name</div>
            <div class="font-semibold"><?= htmlspecialchars($child['full_name']) ?></div>
          </div>
          <div>
            <div class="text-xs text-muted" style="margin-bottom:2px">Registration No.</div>
            <div class="font-mono font-semibold">
              <?= htmlspecialchars($child['reg_number']) ?>
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

        <?php if (empty($transcript['units'])): ?>
          <div class="empty-state" style="padding:var(--space-10) 0">
            <span class="empty-icon">📝</span>
            <p class="empty-title">No grades available</p>
            <p class="empty-text">
              Grades will appear here once all assessments have been
              published and marked by your child's lecturers.
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
  <a href="<?= BASE_URL ?>/parent/dashboard" class="mobile-nav-item">
    <span class="nav-icon">🏠</span><span>Home</span>
  </a>
  <a href="<?= BASE_URL ?>/parent/attendance" class="mobile-nav-item">
    <span class="nav-icon">📋</span><span>Attendance</span>
  </a>
  <a href="<?= BASE_URL ?>/parent/marks" class="mobile-nav-item">
    <span class="nav-icon">📝</span><span>Marks</span>
  </a>
  <a href="<?= BASE_URL ?>/parent/profile" class="mobile-nav-item">
    <span class="nav-icon">👤</span><span>Profile</span>
  </a>
</nav>

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
</body>
</html>
