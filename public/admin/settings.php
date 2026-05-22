<?php
/**
 * EduTrack — Admin System Settings Page
 *
 * Allows the admin to update all runtime settings stored in
 * the system_settings table without touching config.php.
 *
 * Settings are grouped into logical sections:
 *   - Institution details
 *   - Academic calendar
 *   - Attendance rules
 *   - Notification settings
 *   - System controls
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';

Auth::startSession();
Auth::requireRole('admin');

$user = Auth::user();

// ── Load all current settings ─────────────────────────────────────────────────
$settingRows = DB::rows(
    "SELECT setting_key, setting_value, description
     FROM system_settings
     ORDER BY setting_key ASC"
);

// Index by key for easy lookup in the form
$settings = [];
foreach ($settingRows as $row) {
    $settings[$row['setting_key']] = $row;
}

// Helper: get current value with fallback
function settingVal(array $settings, string $key, string $fallback = ''): string {
    return htmlspecialchars($settings[$key]['setting_value'] ?? $fallback);
}

$csrfToken = Auth::csrfToken();
$pageTitle = 'System Settings';
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
      <span class="topbar-title">System Settings</span>
      <div class="topbar-actions">
        <span class="text-sm text-muted">
          Changes take effect immediately
        </span>
      </div>
    </header>

    <div class="page-content">

      <div class="alert alert-info animate-fade-in"
           style="margin-bottom:var(--space-6)">
        <span class="alert-icon">ℹ</span>
        <div>
          These settings are stored in the database and override the
          defaults in <code>config.php</code>. Changes apply instantly
          without restarting Apache.
        </div>
      </div>

      <!-- ── Section 1: Institution ──────────────────────────────────── -->
      <div class="card animate-fade-in settings-section">
        <div class="settings-section-title">
          🏫 Institution Details
        </div>

        <div class="form-group">
          <label class="form-label" for="school_name">
            School / Institution Name
          </label>
          <input type="text"
                 id="school_name"
                 name="school_name"
                 class="form-control"
                 value="<?= settingVal($settings, 'school_name') ?>"
                 placeholder="e.g. Nairobi Technical Institute"
                 maxlength="150">
          <div class="form-hint">
            Displayed on all portal headers, reports, and email footers.
          </div>
        </div>

        <div class="form-actions" style="padding-top:var(--space-4)">
          <button class="btn btn-primary"
                  onclick="saveSetting('school_name', 'school_name')">
            Save Institution Name
          </button>
        </div>
      </div>

      <!-- ── Section 2: Academic Calendar ───────────────────────────── -->
      <div class="card animate-fade-in settings-section"
           style="animation-delay:0.05s">
        <div class="settings-section-title">
          📅 Academic Calendar
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="academic_year">
              Academic Year <span class="required">*</span>
            </label>
            <input type="text"
                   id="academic_year"
                   name="academic_year"
                   class="form-control"
                   value="<?= settingVal($settings, 'academic_year') ?>"
                   placeholder="e.g. 2024/2025"
                   maxlength="12">
            <div class="form-hint">
              Used to scope attendance and marks records.
              Change at the start of each academic year.
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="active_semester">
              Active Semester <span class="required">*</span>
            </label>
            <select id="active_semester"
                    name="active_semester"
                    class="form-control">
              <option value="1"
                <?= settingVal($settings,'active_semester') === '1' ? 'selected' : '' ?>>
                Semester 1
              </option>
              <option value="2"
                <?= settingVal($settings,'active_semester') === '2' ? 'selected' : '' ?>>
                Semester 2
              </option>
            </select>
            <div class="form-hint">
              All new attendance sessions and marks will be recorded under this semester.
            </div>
          </div>
        </div>

        <div class="alert alert-warning" style="margin-bottom:var(--space-4)">
          <span class="alert-icon">⚠️</span>
          <div>
            Changing the academic year or semester does <strong>not</strong>
            delete existing data. Old records remain visible in history views
            — only new sessions and marks are created under the new values.
          </div>
        </div>

        <div class="form-actions" style="padding-top:var(--space-4)">
          <button class="btn btn-primary"
                  onclick="saveMultiple(['academic_year','active_semester'])">
            Save Calendar Settings
          </button>
        </div>
      </div>

      <!-- ── Section 3: Attendance Rules ────────────────────────────── -->
      <div class="card animate-fade-in settings-section"
           style="animation-delay:0.1s">
        <div class="settings-section-title">
          📊 Attendance Rules
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="attendance_threshold">
              Alert Threshold (%)
            </label>
            <input type="number"
                   id="attendance_threshold"
                   name="attendance_threshold"
                   class="form-control"
                   value="<?= settingVal($settings, 'attendance_threshold', '75') ?>"
                   min="1" max="100" step="1">
            <div class="form-hint">
              Students below this percentage trigger a parent alert.
              Default: 75%.
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="attendance_window">
              QR Code Validity (minutes)
            </label>
            <input type="number"
                   id="attendance_window"
                   name="attendance_window"
                   class="form-control"
                   value="<?= settingVal($settings, 'attendance_window', '10') ?>"
                   min="1" max="120" step="1">
            <div class="form-hint">
              How long a generated QR code remains valid. Default: 10 min.
            </div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="dispute_window_hours">
            Dispute Window (hours)
          </label>
          <input type="number"
                 id="dispute_window_hours"
                 name="dispute_window_hours"
                 class="form-control"
                 style="max-width:200px"
                 value="<?= settingVal($settings, 'dispute_window_hours', '24') ?>"
                 min="1" max="168" step="1">
          <div class="form-hint">
            Hours after a session closes that students can raise a dispute.
            Default: 24 hours. Max: 168 hours (1 week).
          </div>
        </div>

        <div class="form-actions" style="padding-top:var(--space-4)">
          <button class="btn btn-primary"
                  onclick="saveMultiple([
                    'attendance_threshold',
                    'attendance_window',
                    'dispute_window_hours'
                  ])">
            Save Attendance Rules
          </button>
        </div>
      </div>

      <!-- ── Section 4: Notifications ───────────────────────────────── -->
      <div class="card animate-fade-in settings-section"
           style="animation-delay:0.15s">
        <div class="settings-section-title">
          📧 Notifications
        </div>

        <div class="form-group">
          <label class="form-label" style="display:flex;align-items:center;
                 gap:var(--space-3);cursor:pointer">
            <input type="checkbox"
                   id="smtp_enabled"
                   style="width:18px;height:18px;cursor:pointer"
                   <?= settingVal($settings,'smtp_enabled') === '1' ? 'checked' : '' ?>>
            <span>Enable email notifications (SMTP)</span>
          </label>
          <div class="form-hint">
            When enabled, the system sends email alerts to parents when
            a student's attendance drops below the threshold.
            Requires a working SMTP server configured in
            <code>config/config.php</code>.
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" style="display:flex;align-items:center;
                 gap:var(--space-3);cursor:pointer">
            <input type="checkbox"
                   id="allow_student_register"
                   style="width:18px;height:18px;cursor:pointer"
                   <?= settingVal($settings,'allow_student_register') === '1'
                       ? 'checked' : '' ?>>
            <span>Allow student self-registration</span>
          </label>
          <div class="form-hint">
            When enabled, a "Register" link appears on the student login page.
            Students can create their own accounts (admin still approves enrollment).
            Recommended: <strong>OFF</strong> for controlled environments.
          </div>
        </div>

        <div class="form-actions" style="padding-top:var(--space-4)">
          <button class="btn btn-primary"
                  onclick="saveCheckboxes([
                    'smtp_enabled',
                    'allow_student_register'
                  ])">
            Save Notification Settings
          </button>
        </div>
      </div>

      <!-- ── Section 5: System Controls ─────────────────────────────── -->
      <div class="card animate-fade-in settings-section"
           style="animation-delay:0.2s">
        <div class="settings-section-title">
          🔧 System Controls
        </div>

        <div class="form-group">
          <label class="form-label" style="display:flex;align-items:center;
                 gap:var(--space-3);cursor:pointer">
            <input type="checkbox"
                   id="maintenance_mode"
                   style="width:18px;height:18px;cursor:pointer"
                   <?= settingVal($settings,'maintenance_mode') === '1'
                       ? 'checked' : '' ?>>
            <span style="font-weight:var(--weight-semibold)">
              Enable Maintenance Mode
            </span>
          </label>
          <div class="form-hint" style="margin-left:26px">
            When ON, all non-admin users see a maintenance page instead of their portal.
            You (the admin) remain unaffected.
            <strong style="color:var(--color-error)">
              Turn this OFF when maintenance is complete.
            </strong>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="rows_per_page">
            Rows Per Page
          </label>
          <input type="number"
                 id="rows_per_page"
                 name="rows_per_page"
                 class="form-control"
                 style="max-width:160px"
                 value="<?= settingVal($settings, 'rows_per_page', '25') ?>"
                 min="5" max="100" step="5">
          <div class="form-hint">
            Number of rows shown per page in tables across all portals.
            Default: 25.
          </div>
        </div>

        <div class="form-actions" style="padding-top:var(--space-4)">
          <button class="btn btn-primary"
                  onclick="saveCheckboxesAndFields(
                    ['maintenance_mode'],
                    ['rows_per_page']
                  )">
            Save System Controls
          </button>
        </div>
      </div>

      <!-- ── Section 6: Danger zone ──────────────────────────────────── -->
      <div class="card animate-fade-in settings-section"
           style="animation-delay:0.25s;
                  border:1.5px solid var(--color-error-light)">
        <div class="settings-section-title" style="color:var(--color-error)">
          🗑️ Danger Zone
        </div>
        <p class="text-sm text-muted" style="margin-bottom:var(--space-5)">
          These actions are irreversible. Proceed only if you are certain.
        </p>

        <div style="display:flex;gap:var(--space-4);flex-wrap:wrap">
          <div class="danger-action-card">
            <div class="danger-action-title">Close All Active Sessions</div>
            <div class="danger-action-desc">
              Immediately closes every open QR attendance session.
              Use during an emergency or end-of-day cleanup.
            </div>
            <button class="btn btn-danger btn-sm"
                    onclick="closeAllSessions()">
              Close All Sessions
            </button>
          </div>

          <div class="danger-action-card">
            <div class="danger-action-title">Export Database Backup</div>
            <div class="danger-action-desc">
              Opens phpMyAdmin export page for
              <code><?= htmlspecialchars(DB_NAME) ?></code>.
              Back up before any major change.
            </div>
            <a href="http://localhost/phpmyadmin/index.php?route=/database/export&db=<?= urlencode(DB_NAME) ?>"
               class="btn btn-secondary btn-sm"
               target="_blank" rel="noopener">
              Open phpMyAdmin Export
            </a>
          </div>
        </div>
      </div>

    </div><!-- /page-content -->
  </div><!-- /main -->
</div><!-- /layout -->

<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
<script>
const BASE_URL = <?= json_encode(BASE_URL) ?>;

// ── Save a single text/number field ──────────────────────────────────────────
async function saveSetting(fieldId, key) {
  const value = document.getElementById(fieldId)?.value?.trim();
  if (value === undefined) return;

  try {
    await Api.post(`${BASE_URL}/api/admin/settings_update.php`, {
      settings: [{ key, value }],
    });
    Toast.show('success', 'Setting saved.');
  } catch (err) {
    Api.showError(err);
  }
}

// ── Save multiple text/number fields at once ─────────────────────────────────
async function saveMultiple(fieldIds) {
  const settings = fieldIds.map(id => ({
    key:   id,
    value: document.getElementById(id)?.value?.trim() ?? '',
  }));

  // Basic validation: no empty required values
  const empty = settings.find(s => s.value === '');
  if (empty) {
    Toast.show('error', `Value for "${empty.key}" cannot be empty.`);
    return;
  }

  try {
    await Api.post(`${BASE_URL}/api/admin/settings_update.php`, { settings });
    Toast.show('success', 'Settings saved successfully.');
  } catch (err) {
    Api.showError(err);
  }
}

// ── Save checkbox fields (0 or 1) ────────────────────────────────────────────
async function saveCheckboxes(fieldIds) {
  const settings = fieldIds.map(id => ({
    key:   id,
    value: document.getElementById(id)?.checked ? '1' : '0',
  }));

  try {
    await Api.post(`${BASE_URL}/api/admin/settings_update.php`, { settings });
    Toast.show('success', 'Settings saved.');
  } catch (err) {
    Api.showError(err);
  }
}

// ── Save a mix of checkboxes and text fields ──────────────────────────────────
async function saveCheckboxesAndFields(checkboxIds, textIds) {
  const settings = [
    ...checkboxIds.map(id => ({
      key:   id,
      value: document.getElementById(id)?.checked ? '1' : '0',
    })),
    ...textIds.map(id => ({
      key:   id,
      value: document.getElementById(id)?.value?.trim() ?? '',
    })),
  ];

  try {
    await Api.post(`${BASE_URL}/api/admin/settings_update.php`, { settings });
    Toast.show('success', 'Settings saved.');
  } catch (err) {
    Api.showError(err);
  }
}

// ── Close all active sessions ─────────────────────────────────────────────────
async function closeAllSessions() {
  if (!confirm(
    'Close ALL active attendance sessions?\n\n' +
    'Students currently scanning will be cut off immediately. ' +
    'Absent students will be auto-marked for each closed session.'
  )) return;

  try {
    const data = await Api.post(
      `${BASE_URL}/api/admin/close_all_sessions.php`, {}
    );
    Toast.show('success', data.message || 'All sessions closed.');
  } catch (err) {
    Api.showError(err);
  }
}
</script>

<style>
.danger-action-card {
  flex: 1;
  min-width: 240px;
  background: var(--color-error-light);
  border: 1px solid #f5c0b0;
  border-radius: var(--radius-lg);
  padding: var(--space-4) var(--space-5);
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.danger-action-title {
  font-size: var(--text-sm);
  font-weight: var(--weight-semibold);
  color: var(--color-error);
}

.danger-action-desc {
  font-size: var(--text-xs);
  color: var(--color-text-secondary);
  line-height: var(--leading-relaxed);
}

code {
  font-family: var(--font-mono);
  font-size: 0.9em;
  background: var(--color-bg-inset);
  padding: 1px 5px;
  border-radius: var(--radius-sm);
}
</style>

</body>
</html>