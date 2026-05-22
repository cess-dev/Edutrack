<?php
/**
 * EduTrack — Student Marks Page
 *
 * Displays all published marks for the student grouped by unit.
 * Shows per-assessment scores, weighted contributions,
 * running unit total, grade letter, and overall GPA.
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
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

// All published marks grouped by unit with weighted totals
$unitMarks = MarksModel::getStudentMarks($user['id'], $academicYear, $semester);

// Transcript summary (GPA)
$transcript = MarksModel::getStudentTranscript($user['id']);

$csrfToken = Auth::csrfToken();
$pageTitle = 'My Marks';
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
      <span class="topbar-title">My Marks</span>
      <div class="topbar-actions">
        <a href="<?= BASE_URL ?>/student/transcript"
           class="btn btn-secondary btn-sm">
          🎓 View Transcript
        </a>
      </div>
    </header>

    <div class="page-content">

      <!-- GPA summary strip -->
      <?php if ($transcript['gpa'] !== null): ?>
        <div class="gpa-strip animate-fade-in">
          <div class="gpa-block">
            <div class="gpa-value">
              <?= number_format($transcript['gpa'], 2) ?>
            </div>
            <div class="gpa-label">Current GPA</div>
          </div>
          <div class="gpa-divider"></div>
          <div class="gpa-block">
            <div class="gpa-value" style="font-size:var(--text-2xl)">
              <?= $transcript['total_units'] ?>
            </div>
            <div class="gpa-label">Units Graded</div>
          </div>
          <div class="gpa-divider"></div>
          <div style="flex:1">
            <div class="text-sm text-muted" style="margin-bottom:var(--space-2)">
              <?= $academicYear ?> · Semester <?= $semester ?>
            </div>
            <div class="gpa-bar-track">
              <div class="gpa-bar-fill"
                   style="width:<?= min(100, ($transcript['gpa'] / 4.0) * 100) ?>%">
              </div>
            </div>
            <div class="text-xs text-muted" style="margin-top:var(--space-1)">
              GPA scale: 0.0 – 4.0
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- Per-unit marks -->
      <?php if (empty($unitMarks)): ?>
        <div class="empty-state animate-fade-in">
          <span class="empty-icon">📝</span>
          <p class="empty-title">No marks published yet</p>
          <p class="empty-text">
            Your lecturer will publish marks once assessments have been graded.
            Check back after each CAT or assignment.
          </p>
        </div>

      <?php else: ?>
        <div class="animate-fade-in" style="animation-delay:0.1s">
          <?php foreach ($unitMarks as $i => $unit): ?>
            <div class="unit-marks-block"
                 style="animation-delay:<?= 0.05 * $i ?>s">

              <!-- Unit header -->
              <div class="unit-marks-header">
                <div style="display:flex;align-items:center;gap:var(--space-3)">
                  <span class="font-mono text-xs font-semibold text-accent">
                    <?= htmlspecialchars($unit['unit_code']) ?>
                  </span>
                  <span class="font-medium text-sm text-primary">
                    <?= htmlspecialchars($unit['unit_name']) ?>
                  </span>
                </div>
                <?php if ($unit['grade']): ?>
                  <div style="display:flex;align-items:center;gap:var(--space-3)">
                    <span class="text-sm text-muted"><?= $unit['remark'] ?></span>
                    <span class="grade-pill grade-<?= $unit['grade'] ?>">
                      <?= $unit['grade'] ?>
                    </span>
                  </div>
                <?php else: ?>
                  <span class="text-xs text-muted">Grade pending</span>
                <?php endif; ?>
              </div>

              <!-- Assessments table -->
              <div class="unit-marks-body">
                <table class="table">
                  <thead>
                    <tr>
                      <th>Assessment</th>
                      <th>Type</th>
                      <th>Date</th>
                      <th style="text-align:center">Score</th>
                      <th style="text-align:center">Max</th>
                      <th style="text-align:center">%</th>
                      <th style="text-align:center">Weight</th>
                      <th style="text-align:center">Contribution</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($unit['assessments'] as $a):
                      $scorePct = $a['score'] !== null
                          ? round(($a['score'] / $a['max_score']) * 100, 1)
                          : null;
                      $scoreCol = $scorePct === null ? 'inherit'
                          : ($scorePct >= 50 ? 'var(--color-success)' : 'var(--color-error)');
                    ?>
                      <tr>
                        <td class="font-medium text-sm">
                          <?= htmlspecialchars($a['name']) ?>
                        </td>
                        <td>
                          <span class="badge badge-info text-xs">
                            <?= ucfirst(str_replace('_',' ',$a['type'])) ?>
                          </span>
                        </td>
                        <td class="text-xs text-muted">
                          <?= $a['assessment_date']
                              ? date('d M Y', strtotime($a['assessment_date']))
                              : '—' ?>
                        </td>
                        <td class="text-center font-mono font-semibold"
                            style="color:<?= $scoreCol ?>">
                          <?= $a['score'] !== null
                              ? htmlspecialchars($a['score'])
                              : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td class="text-center font-mono text-muted text-sm">
                          <?= $a['max_score'] ?>
                        </td>
                        <td class="text-center font-mono text-sm"
                            style="color:<?= $scoreCol ?>">
                          <?= $scorePct !== null ? $scorePct . '%' : '—' ?>
                        </td>
                        <td class="text-center text-sm text-muted">
                          <?= $a['weight_percent'] ?>%
                        </td>
                        <td class="text-center font-mono font-semibold text-sm"
                            style="color:var(--color-accent)">
                          <?= $a['weighted_score'] !== null
                              ? number_format($a['weighted_score'], 2)
                              : '<span class="text-muted">—</span>' ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>

              <!-- Unit weighted total footer -->
              <div class="unit-weighted-total">
                <span class="text-muted">
                  Weight earned: <?= $unit['weight_earned'] ?>% / 100%
                  <?php if ($unit['weight_earned'] < 100): ?>
                    <span class="text-xs" style="color:var(--color-amber)">
                      (<?= 100 - $unit['weight_earned'] ?>% not yet published)
                    </span>
                  <?php endif; ?>
                </span>
                <span style="display:flex;align-items:center;gap:var(--space-3)">
                  <span class="text-sm text-muted">Weighted Total:</span>
                  <span class="font-mono font-bold"
                        style="font-size:var(--text-lg);
                               color:<?= $unit['weighted_total'] >= 50
                                         ? 'var(--color-success)'
                                         : 'var(--color-error)' ?>">
                    <?= number_format($unit['weighted_total'], 2) ?> / 100
                  </span>
                  <?php if ($unit['grade']): ?>
                    <span class="grade-pill grade-<?= $unit['grade'] ?>">
                      <?= $unit['grade'] ?>
                    </span>
                  <?php endif; ?>
                </span>
              </div>

            </div><!-- /unit-marks-block -->
          <?php endforeach; ?>
        </div>

        <!-- Grade boundaries reference -->
        <div class="card animate-fade-in"
             style="margin-top:var(--space-6);animation-delay:0.3s">
          <div class="card-header">
            <div class="card-title">Grade Boundaries</div>
            <div class="card-subtitle">Weighted total out of 100</div>
          </div>
          <div style="display:flex;gap:var(--space-3);flex-wrap:wrap">
            <?php
              $boundaries = [
                ['grade'=>'A','range'=>'70 – 100','remark'=>'Distinction',    'class'=>'grade-A'],
                ['grade'=>'B','range'=>'60 – 69', 'remark'=>'Credit',         'class'=>'grade-B'],
                ['grade'=>'C','range'=>'50 – 59', 'remark'=>'Pass',           'class'=>'grade-C'],
                ['grade'=>'D','range'=>'40 – 49', 'remark'=>'Marginal Fail',  'class'=>'grade-D'],
                ['grade'=>'E','range'=>'0 – 39',  'remark'=>'Fail',           'class'=>'grade-E'],
              ];
              foreach ($boundaries as $b):
            ?>
              <div style="display:flex;align-items:center;gap:var(--space-3);
                          padding:var(--space-3) var(--space-4);
                          background:var(--color-bg-subtle);
                          border-radius:var(--radius-md);flex:1;min-width:140px">
                <span class="grade-pill <?= $b['class'] ?>"><?= $b['grade'] ?></span>
                <div>
                  <div class="font-semibold text-sm"><?= $b['range'] ?>%</div>
                  <div class="text-xs text-muted"><?= $b['remark'] ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

      <?php endif; ?>

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->

<nav class="mobile-nav">
  <a href="<?= BASE_URL ?>/student/dashboard" class="mobile-nav-item">
    <span class="nav-icon">🏠</span><span>Home</span>
  </a>
  <a href="<?= BASE_URL ?>/student/scan" class="mobile-nav-item">
    <span class="nav-icon">📷</span><span>Scan</span>
  </a>
  <a href="<?= BASE_URL ?>/student/attendance" class="mobile-nav-item">
    <span class="nav-icon">📋</span><span>Attendance</span>
  </a>
  <a href="<?= BASE_URL ?>/student/marks" class="mobile-nav-item active">
    <span class="nav-icon">📝</span><span>Marks</span>
  </a>
</nav>

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>

<style>
/* GPA summary strip */
.gpa-strip {
  display: flex;
  align-items: center;
  gap: var(--space-6);
  background: var(--color-bg-card);
  border: 1px solid var(--color-border-light);
  border-radius: var(--radius-xl);
  padding: var(--space-5) var(--space-8);
  margin-bottom: var(--space-6);
  box-shadow: var(--shadow-sm);
}

.gpa-block { text-align: center; flex-shrink: 0; }

.gpa-value {
  font-family: var(--font-heading);
  font-size: var(--text-4xl);
  color: var(--color-primary);
  line-height: 1;
  margin-bottom: var(--space-1);
}

.gpa-label {
  font-size: var(--text-xs);
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--color-text-muted);
  font-weight: var(--weight-semibold);
}

.gpa-divider {
  width: 1px;
  height: 48px;
  background: var(--color-border-light);
  flex-shrink: 0;
}

.gpa-bar-track {
  height: 8px;
  background: var(--color-bg-inset);
  border-radius: var(--radius-full);
  overflow: hidden;
}

.gpa-bar-fill {
  height: 100%;
  background: linear-gradient(90deg, var(--color-accent), var(--color-primary));
  border-radius: var(--radius-full);
  transition: width 0.8s var(--transition-spring);
}

@media (max-width: 640px) {
  .gpa-strip {
    flex-wrap: wrap;
    gap: var(--space-4);
    padding: var(--space-5);
  }
  .gpa-divider { display: none; }
  .gpa-block   { flex: 1; min-width: 80px; }
}
</style>

</body>
</html>