<?php
/**
 * EduTrack — Parent: Child's Semester History
 *
 * Read-only view of a linked child's full enrollment history,
 * grouped by academic year and semester.
 * Mirrors the student/history.php experience but accessible to parents.
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/UserModel.php';

Auth::startSession();
Auth::requireRole('parent');

$user = Auth::user();

// ── Linked children ───────────────────────────────────────────────────────────
$children = UserModel::getLinkedStudents($user['id']);

if (empty($children)) {
    header('Location: ' . BASE_URL . '/parent/dashboard');
    exit;
}

// ── Resolve which child to show ───────────────────────────────────────────────
$requestedId = (int)($_GET['student_id'] ?? $children[0]['id']);

$child = null;
foreach ($children as $c) {
    if ($c['id'] === $requestedId) { $child = $c; break; }
}
if (!$child) { $child = $children[0]; }

// ── Active period ─────────────────────────────────────────────────────────────
$activeAcademicYear = DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'academic_year'"
)['setting_value'] ?? ACADEMIC_YEAR;

$activeSemester = (int)(DB::row(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'active_semester'"
)['setting_value'] ?? ACTIVE_SEMESTER);

// ── Full history ───────────────────────────────────────────────────────────────
$history = UserModel::getStudentCourseHistory($child['id']);

// Enrich each unit with attendance + marks
foreach ($history as &$period) {
    foreach ($period['units'] as &$unit) {
        $att = DB::row(
            "SELECT
                COUNT(CASE WHEN al.status = 'present' THEN 1 END) AS present_count,
                COUNT(*) AS total_count
             FROM attendance_logs al
             JOIN attendance_sessions s ON s.id = al.session_id
             WHERE al.student_id = ? AND s.unit_id = ?
               AND s.academic_year = ? AND s.semester = ?",
            [$child['id'], $unit['unit_id'], $period['academic_year'], $period['semester']]
        );
        $total   = (int)($att['total_count']   ?? 0);
        $present = (int)($att['present_count'] ?? 0);
        $unit['attendance_total']   = $total;
        $unit['attendance_present'] = $present;
        $unit['attendance_pct']     = $total > 0 ? round($present / $total * 100) : null;

        $unit['marks'] = DB::rows(
            "SELECT a.name AS assessment_name, a.type, a.max_score, m.score
             FROM marks m
             JOIN assessments a ON a.id = m.assessment_id
             WHERE m.student_id = ? AND a.unit_id = ? AND a.is_published = 1
             ORDER BY a.assessment_date ASC",
            [$child['id'], $unit['unit_id']]
        );
    }
    unset($unit);
}
unset($period);

$pageTitle = htmlspecialchars($child['full_name']) . ' — Semester History';
?>
<!DOCTYPE html>
<html lang="en" data-base-url="<?= htmlspecialchars(BASE_URL) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $pageTitle ?> — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/base.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/parent.css">
  <style>
    .child-tab-bar {
      display: flex;
      gap: var(--space-2);
      margin-bottom: var(--space-5);
      flex-wrap: wrap;
    }
    .child-tab {
      padding: 6px 16px;
      border-radius: var(--radius-full);
      font-size: var(--text-sm);
      font-weight: 500;
      border: 1px solid var(--color-border);
      cursor: pointer;
      text-decoration: none;
      color: var(--color-text);
      background: var(--color-bg);
      transition: background .15s;
    }
    .child-tab:hover { background: var(--color-bg-subtle); }
    .child-tab.active {
      background: var(--color-accent);
      color: white;
      border-color: var(--color-accent);
    }
    .history-period { margin-bottom: var(--space-8); }
    .history-period-header {
      display: flex; align-items: center; gap: var(--space-3);
      margin-bottom: var(--space-4); flex-wrap: wrap;
    }
    .history-period-title { font-size: var(--text-lg); font-weight: 700; }
    .history-course-badge {
      background: var(--color-accent-subtle); color: var(--color-accent);
      font-size: var(--text-xs); font-weight: 600; padding: 4px 10px;
      border-radius: var(--radius-full); font-family: var(--font-mono);
    }
    .history-year-badge {
      background: var(--color-bg-subtle); color: var(--color-text-muted);
      font-size: var(--text-xs); padding: 4px 10px; border-radius: var(--radius-full);
    }
    .current-badge {
      background: var(--color-success-subtle); color: var(--color-success);
      font-size: var(--text-xs); font-weight: 600; padding: 4px 10px;
      border-radius: var(--radius-full);
    }
    .unit-history-card { border: 1px solid var(--color-border); border-radius: var(--radius-lg);
                         overflow: hidden; margin-bottom: var(--space-3); background: var(--color-bg); }
    .unit-history-header { display: flex; align-items: center; justify-content: space-between;
                           padding: var(--space-3) var(--space-4); background: var(--color-bg-subtle);
                           cursor: pointer; user-select: none; gap: var(--space-3); }
    .unit-history-header:hover { background: var(--color-border-light); }
    .unit-header-left { display: flex; align-items: center; gap: var(--space-3); flex: 1; min-width: 0; }
    .unit-code-badge { font-family: var(--font-mono); font-size: var(--text-xs); font-weight: 700;
                       background: var(--color-accent); color: white; padding: 3px 8px;
                       border-radius: var(--radius-sm); white-space: nowrap; }
    .unit-name-text { font-size: var(--text-sm); font-weight: 500; white-space: nowrap;
                      overflow: hidden; text-overflow: ellipsis; }
    .unit-lecturer-text { font-size: var(--text-xs); color: var(--color-text-muted); }
    .unit-header-stats { display: flex; align-items: center; gap: var(--space-4); flex-shrink: 0; }
    .att-pill { font-size: var(--text-xs); font-weight: 600; padding: 3px 10px;
                border-radius: var(--radius-full); }
    .att-pill.good { background: var(--color-success-subtle); color: var(--color-success); }
    .att-pill.warn { background: var(--color-warning-subtle); color: var(--color-warning); }
    .att-pill.low  { background: var(--color-danger-subtle);  color: var(--color-danger); }
    .att-pill.none { background: var(--color-bg-subtle); color: var(--color-text-muted); }
    .unit-chevron  { color: var(--color-text-muted); font-size: 12px; transition: transform .2s; }
    .unit-chevron.open { transform: rotate(180deg); }
    .unit-history-body { padding: var(--space-4); display: none; }
    .unit-history-body.open { display: block; }
    .marks-table { width: 100%; font-size: var(--text-sm); }
    .marks-table th { text-align: left; color: var(--color-text-muted); font-weight: 600;
                      font-size: var(--text-xs); padding-bottom: var(--space-2); }
    .marks-table td { padding: var(--space-1) 0; vertical-align: top; }
  </style>
</head>
<body>
<div class="layout">

  <?php include __DIR__ . '/../partials/sidebar_parent.php'; ?>

  <div class="main">
    <header class="topbar">
      <span class="topbar-title">📚 Semester History</span>
    </header>

    <div class="page-content">

      <!-- Child tabs (when multiple children) -->
      <?php if (count($children) > 1): ?>
        <div class="child-tab-bar animate-fade-in">
          <?php foreach ($children as $c): ?>
            <a href="?student_id=<?= $c['id'] ?>"
               class="child-tab <?= $c['id'] === $child['id'] ? 'active' : '' ?>">
              <?= htmlspecialchars($c['full_name']) ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <!-- Student label -->
      <div class="alert alert-info animate-fade-in" style="margin-bottom:var(--space-5)">
        <span class="alert-icon">👤</span>
        <div>
          Viewing semester history for
          <strong><?= htmlspecialchars($child['full_name']) ?></strong>
          (<?= htmlspecialchars($child['reg_number']) ?>).
          This is a read-only view.
        </div>
      </div>

      <?php if (empty($history)): ?>
        <div class="card animate-fade-in">
          <div class="empty-state" style="padding:var(--space-16) 0">
            <span class="empty-icon">📚</span>
            <p class="empty-title">No enrollment history yet</p>
            <p class="empty-text">
              <?= htmlspecialchars($child['full_name']) ?> has not been enrolled in any course yet.
            </p>
          </div>
        </div>

      <?php else: ?>

        <?php foreach ($history as $periodIdx => $period):
          $isCurrent = ($period['academic_year'] === $activeAcademicYear
                        && (int)$period['semester'] === $activeSemester);
        ?>
          <div class="history-period animate-fade-in"
               style="animation-delay: <?= $periodIdx * 0.08 ?>s">

            <div class="history-period-header">
              <span class="history-period-title">
                <?= htmlspecialchars($period['academic_year']) ?>
                &nbsp;·&nbsp; Semester <?= (int)$period['semester'] ?>
              </span>
              <span class="history-course-badge">
                <?= htmlspecialchars($period['course_code']) ?>
              </span>
              <span class="history-year-badge">
                Year <?= (int)$period['year_of_study'] ?>
              </span>
              <?php if ($isCurrent): ?>
                <span class="current-badge">● Current</span>
              <?php endif; ?>
              <span class="text-xs text-muted" style="margin-left:auto">
                <?= htmlspecialchars($period['course_name']) ?>
              </span>
            </div>

            <?php if (empty($period['units'])): ?>
              <div class="card">
                <div style="padding:var(--space-8) var(--space-4);text-align:center;
                             color:var(--color-text-muted);font-size:var(--text-sm)">
                  ⚠️ No units recorded for this period.
                </div>
              </div>

            <?php else: ?>
              <?php foreach ($period['units'] as $unitIdx => $unit):
                $pct     = $unit['attendance_pct'];
                $total   = $unit['attendance_total'];
                $present = $unit['attendance_present'];

                if ($pct === null)    { $pillClass='none'; $pillLabel='No sessions'; }
                elseif ($pct >= 75)  { $pillClass='good'; $pillLabel="{$pct}%"; }
                elseif ($pct >= 50)  { $pillClass='warn'; $pillLabel="{$pct}%"; }
                else                 { $pillClass='low';  $pillLabel="{$pct}%"; }

                $accordionId = "unit-{$periodIdx}-{$unitIdx}";
              ?>
                <div class="unit-history-card">
                  <div class="unit-history-header"
                       onclick="toggleUnit('<?= $accordionId ?>')">
                    <div class="unit-header-left">
                      <span class="unit-code-badge">
                        <?= htmlspecialchars($unit['unit_code']) ?>
                      </span>
                      <div>
                        <div class="unit-name-text">
                          <?= htmlspecialchars($unit['unit_name']) ?>
                        </div>
                        <?php if ($unit['lecturer_name']): ?>
                          <div class="unit-lecturer-text">
                            👨‍🏫 <?= htmlspecialchars($unit['lecturer_name']) ?>
                          </div>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="unit-header-stats">
                      <span class="att-pill <?= $pillClass ?>" title="Attendance">
                        <?= $pillLabel ?>
                      </span>
                      <?php if (!empty($unit['marks'])): ?>
                        <span class="text-xs text-muted">
                          📝 <?= count($unit['marks']) ?> assessment<?= count($unit['marks'])!==1?'s':'' ?>
                        </span>
                      <?php endif; ?>
                      <span class="unit-chevron" id="chevron-<?= $accordionId ?>">▼</span>
                    </div>
                  </div>

                  <div class="unit-history-body" id="body-<?= $accordionId ?>">

                    <!-- Attendance -->
                    <div style="margin-bottom:var(--space-4)">
                      <div class="text-xs text-muted" style="margin-bottom:var(--space-1)">
                        Attendance — <?= $present ?> / <?= $total ?> sessions
                      </div>
                      <?php if ($pct !== null): ?>
                        <div style="height:6px;background:var(--color-border);border-radius:3px;width:100%">
                          <div style="height:100%;border-radius:3px;width:<?= $pct ?>%;
                                      background:<?= $pct>=75?'var(--color-success)':($pct>=50?'var(--color-warning)':'var(--color-danger)') ?>">
                          </div>
                        </div>
                      <?php else: ?>
                        <span class="text-xs text-muted">No attendance sessions recorded.</span>
                      <?php endif; ?>
                    </div>

                    <!-- Marks -->
                    <?php if (!empty($unit['marks'])): ?>
                      <table class="marks-table">
                        <thead>
                          <tr>
                            <th>Assessment</th>
                            <th>Type</th>
                            <th style="text-align:right">Score</th>
                            <th style="text-align:right">Max</th>
                            <th style="text-align:right">%</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($unit['marks'] as $m): ?>
                            <tr>
                              <td><?= htmlspecialchars($m['assessment_name']) ?></td>
                              <td class="text-muted" style="text-transform:capitalize">
                                <?= htmlspecialchars($m['type']) ?>
                              </td>
                              <td style="text-align:right;font-weight:600">
                                <?= htmlspecialchars($m['score']) ?>
                              </td>
                              <td style="text-align:right;color:var(--color-text-muted)">
                                <?= htmlspecialchars($m['max_score']) ?>
                              </td>
                              <td style="text-align:right">
                                <?php
                                  $pm = $m['max_score'] > 0
                                    ? round($m['score'] / $m['max_score'] * 100) : 0;
                                ?>
                                <span style="color:<?= $pm>=50?'var(--color-success)':'var(--color-danger)' ?>;font-weight:600">
                                  <?= $pm ?>%
                                </span>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    <?php else: ?>
                      <p class="text-xs text-muted">No published marks for this unit.</p>
                    <?php endif; ?>

                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>

          </div>
        <?php endforeach; ?>

      <?php endif; ?>

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
<script>
function toggleUnit(id) {
  const body    = document.getElementById('body-' + id);
  const chevron = document.getElementById('chevron-' + id);
  chevron.classList.toggle('open', body.classList.toggle('open'));
}
</script>
</body>
</html>
