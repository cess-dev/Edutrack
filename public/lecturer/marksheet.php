<?php
/**
 * EduTrack — Lecturer Marks Sheet Page
 *
 * Shows the class marks sheet for the selected unit.
 */

define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/MarksModel.php';

Auth::startSession();
Auth::requireRole('lecturer');

$user = Auth::user();

$academicYear = DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year'"
)['setting_value'] ?? ACADEMIC_YEAR;

$semester = (int)(DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'active_semester'"
)['setting_value'] ?? ACTIVE_SEMESTER);

$units = DB::rows(
    "SELECT u.id, u.code, u.name, c.name AS course_name
     FROM units u
     JOIN courses c ON c.id = u.course_id
     WHERE u.lecturer_id = ? AND u.is_active = 1
     ORDER BY u.code ASC",
    [$user['id']]
);

$selectedUnitId = (int)($_GET['unit_id'] ?? ($units[0]['id'] ?? 0));
$selectedUnit = null;
$markSheet = ['assessments' => [], 'students' => []];

if ($selectedUnitId > 0) {
    $selectedUnit = DB::row(
        "SELECT u.id, u.code, u.name, c.name AS course_name
         FROM units u JOIN courses c ON c.id = u.course_id
         WHERE u.id = ? AND u.lecturer_id = ?",
        [$selectedUnitId, $user['id']]
    );

    if ($selectedUnit) {
        $markSheet = MarksModel::getUnitMarksSheet($selectedUnitId, $academicYear, $semester);
    }
}

$csrfToken = Auth::csrfToken();
$pageTitle = 'Marks Sheet';
?>
<!DOCTYPE html>
<html lang="en" data-base-url="<?= htmlspecialchars(BASE_URL) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
  <title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars(APP_NAME) ?> Lecturer</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/base.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/lecturer.css">
</head>
<body>
<div class="layout">

  <?php include __DIR__ . '/../partials/sidebar_lecturer.php'; ?>

  <div class="main">
    <header class="topbar">
      <span class="topbar-title">Marks Sheet</span>
      <div class="topbar-actions">
        <?php if ($selectedUnit): ?>
          <a href="<?= BASE_URL ?>/api/reports/marks_sheet.php?unit_id=<?= $selectedUnitId ?>"
             class="btn btn-secondary btn-sm" target="_blank">
            🖨️ Export PDF
          </a>
        <?php endif; ?>
      </div>
    </header>

    <div class="page-content">

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
          <p class="empty-title">Select a unit above to view its marks sheet</p>
        </div>
      <?php else: ?>
        <div class="page-title animate-fade-in">
          <div class="title-icon">🧾</div>
          <div>
            <?= htmlspecialchars($selectedUnit['code']) ?> —
            <?= htmlspecialchars($selectedUnit['name']) ?>
            <div class="text-sm text-muted font-mono" style="font-family:var(--font-body)">
              <?= htmlspecialchars($selectedUnit['course_name']) ?> ·
              <?= $academicYear ?> · Semester <?= $semester ?>
            </div>
          </div>
        </div>

        <?php if (!empty($markSheet['students']) && !empty($markSheet['assessments'])): ?>
        <div class="card animate-fade-in" style="animation-delay:0.1s">
          <div class="card-header">
            <div>
              <div class="card-title">Class Mark Sheet</div>
              <div class="card-subtitle">
                <?= count($markSheet['students']) ?> students ·
                <?= count($markSheet['assessments']) ?> assessments
              </div>
            </div>
            <a href="<?= BASE_URL ?>/api/reports/marks_sheet.php?unit_id=<?= $selectedUnitId ?>"
               class="btn btn-secondary btn-sm" target="_blank">
              🖨️ Export PDF
            </a>
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
                <?php foreach ($markSheet['students'] as $s): ?>
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
        <?php else: ?>
          <div class="empty-state" style="padding:var(--space-10) 0">
            <span class="empty-icon">📋</span>
            <p class="empty-title">No marks available yet</p>
            <p class="empty-text">Create assessments and upload marks for this unit first.</p>
          </div>
        <?php endif; ?>
      <?php endif; ?>

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->

<nav class="mobile-nav">
  <a href="<?= BASE_URL ?>/public/lecturer/dashboard.php" class="mobile-nav-item">
    <span class="nav-icon">🏠</span><span>Home</span>
  </a>
  <a href="<?= BASE_URL ?>/public/lecturer/sessions.php" class="mobile-nav-item">
    <span class="nav-icon">📋</span><span>Sessions</span>
  </a>
  <a href="<?= BASE_URL ?>/public/lecturer/marks.php" class="mobile-nav-item">
    <span class="nav-icon">📝</span><span>Marks</span>
  </a>
  <a href="<?= BASE_URL ?>/public/lecturer/marksheet.php" class="mobile-nav-item active">
    <span class="nav-icon">📊</span><span>Mark Sheet</span>
  </a>
  <a href="<?= BASE_URL ?>/public/lecturer/disputes.php" class="mobile-nav-item">
    <span class="nav-icon">⚠️</span><span>Disputes</span>
  </a>
</nav>

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

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
</body>
</html>
