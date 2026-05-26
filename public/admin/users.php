<?php
/**
 * EduTrack — Admin User Management Page
 *
 * Allows the admin to:
 *   - List all users with search and role filter
 *   - Create new accounts (any role)
 *   - Edit contact details
 *   - Reset passwords
 *   - Activate / deactivate accounts
 *   - Link parents to students
 */

defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../backend/middleware/auth.php';
require_once __DIR__ . '/../../backend/models/UserModel.php';

Auth::startSession();
Auth::requireRole('admin');

$user = Auth::user();

// ── Filters ───────────────────────────────────────────────────────────────────
$validRoles   = ['all', 'admin', 'lecturer', 'student', 'parent'];
$filterRole   = in_array($_GET['role'] ?? '', $validRoles, true)
    ? $_GET['role'] : 'all';
$search       = trim($_GET['search'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));

// ── Fetch users ───────────────────────────────────────────────────────────────
$result = UserModel::listUsers(
    $filterRole === 'all' ? 'all' : $filterRole,
    $search,
    $page
);

$users      = $result['rows'];
$totalPages = $result['pages'];
$totalRows  = $result['total'];

// ── Role counts for tab badges ────────────────────────────────────────────────
$roleCounts = [];
foreach (['admin', 'lecturer', 'student', 'parent'] as $r) {
    $roleCounts[$r] = (int)(DB::row(
        "SELECT COUNT(*) AS cnt FROM users WHERE role = ?", [$r]
    )['cnt'] ?? 0);
}
$roleCounts['all'] = array_sum($roleCounts);

// ── Lecturer options for unit assignment dropdowns ───────────────────────────
$lecturers = UserModel::getLecturerOptions();

$csrfToken = Auth::csrfToken();
$pageTitle = 'User Management';
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
      <span class="topbar-title">User Management</span>
      <div class="topbar-actions">
        <button class="btn btn-secondary btn-sm" onclick="openBulkLecturersModal()">
          📋 Bulk Add Lecturers
        </button>
        <button class="btn btn-secondary btn-sm" onclick="openBulkParentsModal()">
          📋 Bulk Add Parents
        </button>
        <button class="btn btn-secondary btn-sm" onclick="openBulkLinkModal()">
          🔗 Bulk Link Parents
        </button>
        <button class="btn btn-primary btn-sm" onclick="openCreateModal()">
          + New User
        </button>
      </div>
    </header>

    <div class="page-content">

      <!-- Role filter tabs -->
      <div class="role-filter-tabs animate-fade-in"
           style="margin-bottom:var(--space-5)">
        <?php
          $roleLabels = [
            'all'      => ['icon'=>'👥','label'=>'All'],
            'lecturer' => ['icon'=>'👨‍🏫','label'=>'Lecturers'],
            'student'  => ['icon'=>'🎓','label'=>'Students'],
            'parent'   => ['icon'=>'👨‍👩‍👧','label'=>'Parents'],
            'admin'    => ['icon'=>'⚙️','label'=>'Admins'],
          ];
          foreach ($roleLabels as $r => $meta):
            $qs = http_build_query(array_filter([
                'role'   => $r !== 'all' ? $r : null,
                'search' => $search ?: null,
            ]));
        ?>
          <a href="?<?= $qs ?>"
             class="role-filter-tab <?= $filterRole === $r ? 'active' : '' ?>">
            <?= $meta['icon'] ?> <?= $meta['label'] ?>
            <span style="opacity:0.7">(<?= $roleCounts[$r] ?>)</span>
          </a>
        <?php endforeach; ?>
      </div>

      <!-- Search bar -->
      <div class="user-toolbar animate-fade-in">
        <form method="GET" action="" style="display:flex;gap:var(--space-3);flex:1">
          <input type="hidden" name="role"
                 value="<?= htmlspecialchars($filterRole !== 'all' ? $filterRole : '') ?>">
          <input type="text"
                 name="search"
                 class="form-control user-search"
                 placeholder="Search by name or registration number..."
                 value="<?= htmlspecialchars($search) ?>"
                 autocomplete="off">
          <button type="submit" class="btn btn-secondary btn-sm">Search</button>
          <?php if ($search): ?>
            <a href="?<?= $filterRole !== 'all' ? 'role='.$filterRole : '' ?>"
               class="btn btn-ghost btn-sm">Clear</a>
          <?php endif; ?>
        </form>
        <span class="text-sm text-muted">
          <?= $totalRows ?> user<?= $totalRows !== 1 ? 's' : '' ?>
        </span>
      </div>

      <!-- Users table -->
      <div class="card animate-fade-in" style="animation-delay:0.1s">
        <?php if (empty($users)): ?>
          <div class="empty-state" style="padding:var(--space-12) 0">
            <span class="empty-icon">👥</span>
            <p class="empty-title">No users found</p>
            <p class="empty-text">
              <?= $search
                  ? "No users match \"" . htmlspecialchars($search) . "\". Try a different search."
                  : 'No users in this category yet.' ?>
            </p>
            <button class="btn btn-primary btn-sm" onclick="openCreateModal()">
              + Create First User
            </button>
          </div>

        <?php else: ?>
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>User</th>
                  <th>Reg. Number</th>
                  <th>Role</th>
                  <th>Contact</th>
                  <th>Last Login</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($users as $u):
                  $roleColors = [
                    'admin'    => 'badge-danger',
                    'lecturer' => 'badge-info',
                    'student'  => 'badge-success',
                    'parent'   => 'badge-warning',
                  ];
                ?>
                  <tr id="user-row-<?= $u['id'] ?>">
                    <td>
                      <div style="display:flex;align-items:center;gap:var(--space-3)">
                        <div class="user-avatar-sm"
                             style="background:var(--color-accent-light);
                                    color:var(--color-accent)">
                          <?= strtoupper(substr($u['full_name'], 0, 1)) ?>
                        </div>
                        <div>
                          <div class="font-medium text-sm">
                            <?= htmlspecialchars($u['full_name']) ?>
                          </div>
                          <?php if ($u['email']): ?>
                            <div class="text-xs text-muted">
                              <?= htmlspecialchars($u['email']) ?>
                            </div>
                          <?php endif; ?>
                        </div>
                      </div>
                    </td>
                    <td class="font-mono text-xs">
                      <?= htmlspecialchars($u['reg_number']) ?>
                    </td>
                    <td>
                      <span class="badge <?= $roleColors[$u['role']] ?? 'badge-neutral' ?>">
                        <?= ucfirst($u['role']) ?>
                      </span>
                    </td>
                    <td class="text-sm text-muted">
                      <?= $u['phone']
                          ? htmlspecialchars($u['phone'])
                          : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td class="text-xs text-muted">
                      <?= $u['last_login']
                          ? date('d M Y, H:i', strtotime($u['last_login']))
                          : '<span class="text-muted">Never</span>' ?>
                    </td>
                    <td>
                      <span class="badge <?= $u['is_active'] ? 'badge-success' : 'badge-neutral' ?>"
                            id="status-badge-<?= $u['id'] ?>">
                        <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                      </span>
                    </td>
                    <td>
                      <div style="display:flex;gap:var(--space-2)">
                        <button class="btn btn-ghost btn-sm"
                                title="Edit contact details"
                                onclick="openEditModal(
                                  <?= $u['id'] ?>,
                                  '<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>',
                                  '<?= htmlspecialchars($u['email'] ?? '', ENT_QUOTES) ?>',
                                  '<?= htmlspecialchars($u['phone'] ?? '', ENT_QUOTES) ?>'
                                )">
                          ✏️
                        </button>
                        <button class="btn btn-ghost btn-sm"
                                title="Reset password"
                                onclick="openResetModal(
                                  <?= $u['id'] ?>,
                                  '<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>'
                                )">
                          🔐
                        </button>
                        <?php if ($u['id'] !== $user['id']): ?>
                          <button class="btn btn-ghost btn-sm"
                                  title="<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>"
                                  onclick="toggleActive(
                                    <?= $u['id'] ?>,
                                    <?= $u['is_active'] ? 'false' : 'true' ?>
                                  )">
                            <?= $u['is_active'] ? '🚫' : '✅' ?>
                          </button>
                        <?php endif; ?>
                        <?php if ($u['role'] === 'parent'): ?>
                          <button class="btn btn-ghost btn-sm"
                                  title="Link to student"
                                  onclick="openLinkModal(
                                    <?= $u['id'] ?>,
                                    '<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>'
                                  )">
                            🔗
                          </button>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Pagination -->
          <?php if ($totalPages > 1):
            $baseQs = http_build_query(array_filter([
                'role'   => $filterRole !== 'all' ? $filterRole : null,
                'search' => $search ?: null,
            ]));
            $sep = $baseQs ? '&' : '';
          ?>
            <div class="pagination">
              <a href="?<?= $baseQs.$sep ?>page=<?= max(1,$page-1) ?>"
                 class="page-btn <?= $page<=1?'disabled':'' ?>">‹</a>
              <?php for ($p=max(1,$page-2); $p<=min($totalPages,$page+2); $p++): ?>
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


<!-- ── Create User Modal ─────────────────────────────────────────────────── -->
<div class="modal-backdrop" id="create-modal" hidden>
  <div class="modal" style="max-width:540px">
    <div class="modal-header">
      <h2 class="modal-title">Create New User</h2>
      <button class="modal-close" onclick="closeModal('create-modal')">✕</button>
    </div>
    <div class="modal-body">
      <div data-error-container="create"
           class="alert alert-error" style="margin-bottom:var(--space-4)"></div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">
            Full Name <span class="required">*</span>
          </label>
          <input type="text" id="c-name" class="form-control"
                 placeholder="e.g. Jane Mwangi">
        </div>
        <div class="form-group">
          <label class="form-label">
            Role <span class="required">*</span>
          </label>
          <select id="c-role" class="form-control" onchange="onRoleChange()">
            <option value="student">Student</option>
            <option value="lecturer">Lecturer</option>
            <option value="parent">Parent</option>
            <option value="admin">Admin</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">
            Reg. Number <span class="required">*</span>
          </label>
          <div style="display:flex;gap:var(--space-2)">
            <input type="text" id="c-reg" class="form-control"
                   placeholder="e.g. STU2025001"
                   autocapitalize="characters"
                   style="flex:1">
            <button type="button" id="c-reg-refresh"
                    title="Suggest next number"
                    onclick="suggestRegNumber()"
                    class="btn btn-ghost btn-sm"
                    style="flex-shrink:0;padding:0 var(--space-3)">🔄</button>
          </div>
          <div id="c-reg-hint" class="form-hint" style="margin-top:var(--space-1)"></div>
        </div>
        <div class="form-group">
          <label class="form-label">Phone</label>
          <input type="tel" id="c-phone" class="form-control"
                 placeholder="e.g. 0722000001">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Email</label>
        <input type="email" id="c-email" class="form-control"
               placeholder="user@school.local">
      </div>

      <!-- ── Parent-student link (slides in when role = parent) ──────────── -->
      <div id="c-parent-section" style="display:none">
        <hr style="margin:var(--space-4) 0;border:none;
                   border-top:1px solid var(--color-border-light)">
        <p class="text-sm font-medium" style="margin-bottom:var(--space-3)">
          🔗 Link to Student
          <span class="text-muted" style="font-weight:normal">
            — optional, can be done later via the 🔗 button
          </span>
        </p>
        <div class="form-group">
          <label class="form-label">Student</label>
          <input type="text"
                 id="c-student-search"
                 class="form-control"
                 placeholder="Type name or reg number to search…"
                 autocomplete="off"
                 oninput="searchStudentsCreate(this.value)">
          <div id="c-student-results"
               style="border:1px solid var(--color-border);
                      border-top:none;
                      border-radius:0 0 var(--radius-md) var(--radius-md);
                      max-height:180px;overflow-y:auto;display:none;
                      background:var(--color-bg)">
          </div>
          <input type="hidden" id="c-student-id">
          <div id="c-student-selected"
               class="text-sm"
               style="margin-top:var(--space-2);color:var(--color-success)">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Relationship</label>
          <select id="c-relationship" class="form-control">
            <option value="Parent">Parent</option>
            <option value="Mother">Mother</option>
            <option value="Father">Father</option>
            <option value="Guardian">Guardian</option>
            <option value="Sibling">Sibling</option>
          </select>
        </div>
        <hr style="margin:var(--space-4) 0;border:none;
                   border-top:1px solid var(--color-border-light)">
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">
            Password <span class="required">*</span>
          </label>
          <input type="password" id="c-pass" class="form-control"
                 placeholder="Min 8 chars">
        </div>
        <div class="form-group">
          <label class="form-label">
            Confirm Password <span class="required">*</span>
          </label>
          <input type="password" id="c-pass2" class="form-control"
                 placeholder="Repeat password">
        </div>
      </div>
      <div class="alert alert-info">
        <span class="alert-icon">ℹ</span>
        <span>Password must be at least 8 characters with uppercase,
              lowercase, number and special character.</span>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary"
              onclick="closeModal('create-modal')">Cancel</button>
      <button class="btn btn-primary" id="create-user-btn"
              onclick="createUser()">Create User</button>
    </div>
  </div>
</div>


<!-- ── Edit Contact Modal ────────────────────────────────────────────────── -->
<div class="modal-backdrop" id="edit-modal" hidden>
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title">Edit Contact Details</h2>
      <button class="modal-close" onclick="closeModal('edit-modal')">✕</button>
    </div>
    <div class="modal-body">
      <div data-error-container="edit"
           class="alert alert-error" style="margin-bottom:var(--space-4)"></div>
      <input type="hidden" id="e-id">
      <div class="form-group">
        <label class="form-label">Email</label>
        <input type="email" id="e-email" class="form-control">
      </div>
      <div class="form-group">
        <label class="form-label">Phone</label>
        <input type="tel" id="e-phone" class="form-control">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary"
              onclick="closeModal('edit-modal')">Cancel</button>
      <button class="btn btn-primary" id="edit-user-btn"
              onclick="saveContact()">Save Changes</button>
    </div>
  </div>
</div>


<!-- ── Reset Password Modal ──────────────────────────────────────────────── -->
<div class="modal-backdrop" id="reset-modal" hidden>
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title">Reset Password</h2>
      <button class="modal-close" onclick="closeModal('reset-modal')">✕</button>
    </div>
    <div class="modal-body">
      <div data-error-container="reset"
           class="alert alert-error" style="margin-bottom:var(--space-4)"></div>
      <input type="hidden" id="r-id">
      <div class="alert alert-warning" style="margin-bottom:var(--space-4)">
        <span class="alert-icon">⚠️</span>
        <div>
          Resetting password for
          <strong id="r-name"></strong>.
          The user must be informed of the new password.
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">
            New Password <span class="required">*</span>
          </label>
          <input type="password" id="r-pass" class="form-control">
        </div>
        <div class="form-group">
          <label class="form-label">
            Confirm <span class="required">*</span>
          </label>
          <input type="password" id="r-pass2" class="form-control">
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary"
              onclick="closeModal('reset-modal')">Cancel</button>
      <button class="btn btn-danger" id="reset-btn"
              onclick="resetPassword()">Reset Password</button>
    </div>
  </div>
</div>


<!-- ── Link Parent to Student Modal ─────────────────────────────────────── -->
<div class="modal-backdrop" id="link-modal" hidden>
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title">Link Parent to Student</h2>
      <button class="modal-close" onclick="closeModal('link-modal')">✕</button>
    </div>
    <div class="modal-body">
      <div data-error-container="link"
           class="alert alert-error" style="margin-bottom:var(--space-4)"></div>
      <input type="hidden" id="l-parent-id">
      <div class="alert alert-info" style="margin-bottom:var(--space-4)">
        <span class="alert-icon">ℹ</span>
        <span>Linking <strong id="l-parent-name"></strong> to a student
              account so they can monitor attendance and marks.</span>
      </div>
      <div class="form-group">
        <label class="form-label">
          Student <span class="required">*</span>
        </label>
        <input type="text" id="l-student-search" class="form-control"
               placeholder="Type name or reg number to search..."
               autocomplete="off"
               oninput="searchStudents(this.value)">
        <div id="l-student-results"
             style="border:1px solid var(--color-border);
                    border-top:none;border-radius:0 0 var(--radius-md) var(--radius-md);
                    max-height:200px;overflow-y:auto;display:none;
                    background:white">
        </div>
        <input type="hidden" id="l-student-id">
        <div id="l-student-selected" class="text-sm text-accent"
             style="margin-top:var(--space-2)"></div>
      </div>
      <div class="form-group">
        <label class="form-label">Relationship</label>
        <select id="l-relationship" class="form-control">
          <option value="Parent">Parent</option>
          <option value="Mother">Mother</option>
          <option value="Father">Father</option>
          <option value="Guardian">Guardian</option>
          <option value="Sibling">Sibling</option>
        </select>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary"
              onclick="closeModal('link-modal')">Cancel</button>
      <button class="btn btn-primary" id="link-btn"
              onclick="linkParent()">Link Accounts</button>
    </div>
  </div>
</div>


<!-- ── Bulk Add Lecturers Modal ──────────────────────────────────────────── -->
<div class="modal-backdrop" id="bulk-lec-modal" hidden>
  <div class="modal" style="max-width:680px">
    <div class="modal-header">
      <h2 class="modal-title">📋 Bulk Add Lecturers</h2>
      <button class="modal-close" onclick="closeModal('bulk-lec-modal')">✕</button>
    </div>
    <div class="modal-body">
      <div data-error-container="bulk-lec"
           class="alert alert-error" style="margin-bottom:var(--space-4)"></div>

      <div class="alert alert-info" style="margin-bottom:var(--space-5)">
        <span class="alert-icon">ℹ</span>
        <div>
          Upload a CSV — one lecturer per row. Staff IDs (LEC001…) are generated
          automatically. Default password: <strong>Lecturer@1</strong>
          — each lecturer will be prompted to change it on first login.
          <br><br>
          <code style="font-size:12px;background:var(--color-bg-subtle);
                        padding:4px 8px;border-radius:4px;display:inline-block">
            full_name, email, phone
          </code>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">CSV File <span class="required">*</span></label>
        <input type="file" id="bl-lec-csv" class="form-control" accept=".csv,text/csv">
      </div>

      <!-- Credentials report (shown after upload) -->
      <div id="bl-lec-result" style="display:none;margin-top:var(--space-4)">
        <div id="bl-lec-summary" class="alert alert-success"
             style="margin-bottom:var(--space-3)"></div>
        <div id="bl-lec-creds" style="display:none">
          <div style="display:flex;justify-content:space-between;align-items:center;
                      margin-bottom:var(--space-2)">
            <p class="text-sm font-medium">📄 Credentials to distribute:</p>
            <button class="btn btn-ghost btn-sm" onclick="printCreds('lec')">🖨 Print</button>
          </div>
          <div style="max-height:260px;overflow-y:auto;
                      border:1px solid var(--color-border);border-radius:var(--radius-md)">
            <table id="bl-lec-creds-table"
                   style="width:100%;font-size:12px;border-collapse:collapse">
              <thead style="background:var(--color-bg-subtle);position:sticky;top:0">
                <tr>
                  <th style="padding:8px;text-align:left">Name</th>
                  <th style="padding:8px;text-align:left">Staff ID</th>
                  <th style="padding:8px;text-align:left">Email</th>
                  <th style="padding:8px;text-align:left">Temp Password</th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>
        </div>
        <div id="bl-lec-errors" style="display:none;margin-top:var(--space-3)">
          <p class="text-sm font-medium text-danger" style="margin-bottom:var(--space-2)">
            ❌ Rows skipped:
          </p>
          <ul id="bl-lec-errors-list"
              style="font-size:12px;color:var(--color-danger);padding-left:var(--space-4);
                     list-style:disc;line-height:1.8"></ul>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('bulk-lec-modal')">Close</button>
      <button class="btn btn-primary" id="bl-lec-btn" onclick="bulkAddLecturers()">
        Upload &amp; Create
      </button>
    </div>
  </div>
</div>


<!-- ── Bulk Add Parents Modal ────────────────────────────────────────────── -->
<div class="modal-backdrop" id="bulk-par-modal" hidden>
  <div class="modal" style="max-width:700px">
    <div class="modal-header">
      <h2 class="modal-title">📋 Bulk Add Parents</h2>
      <button class="modal-close" onclick="closeModal('bulk-par-modal')">✕</button>
    </div>
    <div class="modal-body">
      <div data-error-container="bulk-par"
           class="alert alert-error" style="margin-bottom:var(--space-4)"></div>

      <div class="alert alert-info" style="margin-bottom:var(--space-5)">
        <span class="alert-icon">ℹ</span>
        <div>
          Upload a CSV — one parent-student pair per row.
          Parent IDs (PAR001…) are generated automatically.
          Default password: <strong>Parent@1</strong> — parents will be
          prompted to change it on first login.
          <br><br>
          <code style="font-size:12px;background:var(--color-bg-subtle);
                        padding:4px 8px;border-radius:4px;display:inline-block">
            full_name, email, phone, student_reg_number, relationship
          </code>
          <br>
          <span class="text-xs text-muted" style="display:block;margin-top:var(--space-2)">
            email, phone, student_reg_number and relationship are optional.
            A parent with two children appears on two rows with the same email — only
            one account is created and both links are made.
          </span>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">CSV File <span class="required">*</span></label>
        <input type="file" id="bl-par-csv" class="form-control" accept=".csv,text/csv">
      </div>

      <!-- Credentials report -->
      <div id="bl-par-result" style="display:none;margin-top:var(--space-4)">
        <div id="bl-par-summary" class="alert alert-success"
             style="margin-bottom:var(--space-3)"></div>
        <div id="bl-par-creds" style="display:none">
          <div style="display:flex;justify-content:space-between;align-items:center;
                      margin-bottom:var(--space-2)">
            <p class="text-sm font-medium">📄 Credentials to distribute:</p>
            <button class="btn btn-ghost btn-sm" onclick="printCreds('par')">🖨 Print</button>
          </div>
          <div style="max-height:260px;overflow-y:auto;
                      border:1px solid var(--color-border);border-radius:var(--radius-md)">
            <table id="bl-par-creds-table"
                   style="width:100%;font-size:12px;border-collapse:collapse">
              <thead style="background:var(--color-bg-subtle);position:sticky;top:0">
                <tr>
                  <th style="padding:8px;text-align:left">Name</th>
                  <th style="padding:8px;text-align:left">Parent ID</th>
                  <th style="padding:8px;text-align:left">Email / Phone</th>
                  <th style="padding:8px;text-align:left">Linked Student(s)</th>
                  <th style="padding:8px;text-align:left">Temp Password</th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>
        </div>
        <div id="bl-par-errors" style="display:none;margin-top:var(--space-3)">
          <p class="text-sm font-medium text-danger" style="margin-bottom:var(--space-2)">
            ❌ Rows skipped:
          </p>
          <ul id="bl-par-errors-list"
              style="font-size:12px;color:var(--color-danger);padding-left:var(--space-4);
                     list-style:disc;line-height:1.8"></ul>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('bulk-par-modal')">Close</button>
      <button class="btn btn-primary" id="bl-par-btn" onclick="bulkAddParents()">
        Upload &amp; Create
      </button>
    </div>
  </div>
</div>


<!-- ── Bulk Link Parents Modal ───────────────────────────────────────────── -->
<div class="modal-backdrop" id="bulk-link-modal" hidden>
  <div class="modal" style="max-width:560px">
    <div class="modal-header">
      <h2 class="modal-title">🔗 Bulk Link Parents to Students</h2>
      <button class="modal-close" onclick="closeModal('bulk-link-modal')">✕</button>
    </div>
    <div class="modal-body">
      <div data-error-container="bulk-link"
           class="alert alert-error" style="margin-bottom:var(--space-4)"></div>

      <div class="alert alert-info" style="margin-bottom:var(--space-5)">
        <span class="alert-icon">ℹ</span>
        <div>
          Upload a CSV with one parent-student pair per row. The
          <strong>relationship</strong> column is optional (defaults to "Parent").
          <br><br>
          <code style="font-size:12px;background:var(--color-bg-subtle);
                        padding:4px 8px;border-radius:4px;display:inline-block">
            parent_reg_number, student_reg_number, relationship
          </code>
          <br>
          <code style="font-size:12px;background:var(--color-bg-subtle);
                        padding:4px 8px;border-radius:4px;display:inline-block;margin-top:4px">
            PAR001, STU2024003, Mother<br>
            PAR001, STU2025011, Mother<br>
            PAR002, STU2024007, Father
          </code>
          <br><br>
          A header row is optional. Already-linked pairs are skipped. One parent can
          link to many children; one student can have many parents — all rows are
          processed independently.
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">CSV File <span class="required">*</span></label>
        <input type="file" id="bl-csv" class="form-control" accept=".csv,text/csv">
      </div>

      <!-- Result summary (shown after upload) -->
      <div id="bl-summary" style="display:none;margin-top:var(--space-4)">
        <div id="bl-summary-ok" class="alert alert-success"
             style="margin-bottom:var(--space-3)"></div>
        <div id="bl-errors" style="display:none">
          <p class="text-sm font-medium text-danger" style="margin-bottom:var(--space-2)">
            ❌ Rows skipped due to errors:
          </p>
          <div style="max-height:200px;overflow-y:auto;
                      border:1px solid var(--color-border);
                      border-radius:var(--radius-md);padding:var(--space-2)">
            <table style="width:100%;font-size:12px;border-collapse:collapse">
              <thead>
                <tr style="color:var(--color-text-muted);text-align:left">
                  <th style="padding:4px 8px">Row</th>
                  <th style="padding:4px 8px">Parent</th>
                  <th style="padding:4px 8px">Student</th>
                  <th style="padding:4px 8px">Reason</th>
                </tr>
              </thead>
              <tbody id="bl-errors-body"></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('bulk-link-modal')">Close</button>
      <button class="btn btn-primary" id="bl-btn" onclick="bulkLinkParents()">
        Upload &amp; Link
      </button>
    </div>
  </div>
</div>


<script src="<?= BASE_URL ?>/public/assets/js/ajax.js"></script>
<script>
const BASE_URL = <?= json_encode(BASE_URL) ?>;

// ── Modal helpers ─────────────────────────────────────────────────────────────
function openModal(id) {
  document.getElementById(id).hidden = false;
  document.body.style.overflow = 'hidden';
}
function closeModal(id) {
  document.getElementById(id).hidden = true;
  document.body.style.overflow = '';
}

// Close on backdrop click
document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
  backdrop.addEventListener('click', e => {
    if (e.target === backdrop) closeModal(backdrop.id);
  });
});

function getErrEl(key) {
  return document.querySelector(`[data-error-container="${key}"]`);
}
function setErr(key, msg) {
  const el = getErrEl(key);
  el.textContent = msg; el.hidden = false;
}
function clearErr(key) {
  const el = getErrEl(key);
  el.textContent = ''; el.hidden = true;
}

// ── Reg-number suggestion ─────────────────────────────────────────────────────
let _regSuggestCache = {};   // role → { suggested, auto_only, hint }

async function suggestRegNumber() {
  const role    = document.getElementById('c-role').value;
  const regInput = document.getElementById('c-reg');
  const hintEl  = document.getElementById('c-reg-hint');
  const btn     = document.getElementById('c-reg-refresh');

  btn.disabled  = true;
  hintEl.textContent = 'Looking up next number…';

  try {
    const data = await Api.get(`${BASE_URL}/api/admin/suggest_reg.php`, { role });
    _regSuggestCache[role] = data;

    regInput.value        = data.suggested;
    hintEl.textContent    = data.hint;
    hintEl.style.color    = data.auto_only
      ? 'var(--color-success)'
      : 'var(--color-warning, #C47B12)';
  } catch {
    hintEl.textContent = 'Could not fetch suggestion.';
    hintEl.style.color = '';
  } finally {
    btn.disabled = false;
  }
}

async function onRoleChange() {
  const role = document.getElementById('c-role').value;

  // Show the parent-link section only for the parent role
  const parentSection = document.getElementById('c-parent-section');
  parentSection.style.display = role === 'parent' ? 'block' : 'none';

  // Clear any previously selected student when switching away from parent
  if (role !== 'parent') {
    document.getElementById('c-student-search').value        = '';
    document.getElementById('c-student-id').value            = '';
    document.getElementById('c-student-selected').textContent = '';
    document.getElementById('c-student-results').style.display = 'none';
  }

  // Reg-number suggestion (cached or fresh)
  const hintEl = document.getElementById('c-reg-hint');
  if (_regSuggestCache[role]) {
    const data = _regSuggestCache[role];
    document.getElementById('c-reg').value = data.suggested;
    hintEl.textContent = data.hint;
    hintEl.style.color = data.auto_only
      ? 'var(--color-success)'
      : 'var(--color-warning, #C47B12)';
  } else {
    await suggestRegNumber();
  }
}

// ── Create user ───────────────────────────────────────────────────────────────
function openCreateModal() {
  clearErr('create');
  _regSuggestCache = {};   // clear cache so numbers are always fresh

  // Reset all text/password/email inputs
  ['c-name','c-reg','c-phone','c-email','c-pass','c-pass2',
   'c-student-search','c-student-id']
    .forEach(id => { document.getElementById(id).value = ''; });

  // Reset reg-number hint
  document.getElementById('c-reg-hint').textContent = '';

  // Reset parent-link section
  document.getElementById('c-student-selected').textContent  = '';
  document.getElementById('c-student-results').style.display = 'none';

  // Reset role to "student" (default) and hide parent section
  document.getElementById('c-role').value = 'student';
  document.getElementById('c-parent-section').style.display = 'none';

  openModal('create-modal');
  suggestRegNumber();
  setTimeout(() => document.getElementById('c-name').focus(), 100);
}

async function createUser() {
  clearErr('create');
  const name      = document.getElementById('c-name').value.trim();
  const reg       = document.getElementById('c-reg').value.trim();
  const role      = document.getElementById('c-role').value;
  const phone     = document.getElementById('c-phone').value.trim();
  const email     = document.getElementById('c-email').value.trim();
  const pass      = document.getElementById('c-pass').value;
  const pass2     = document.getElementById('c-pass2').value;
  const btn       = document.getElementById('create-user-btn');
  const studentId = role === 'parent'
    ? document.getElementById('c-student-id').value
    : '';
  const rel       = role === 'parent'
    ? document.getElementById('c-relationship').value
    : '';

  if (!name || !reg || !role || !pass) {
    setErr('create', 'Name, registration number, role and password are required.');
    return;
  }
  if (pass !== pass2) {
    setErr('create', 'Passwords do not match.');
    return;
  }

  await Api.withLoading(btn, async () => {
    try {
      const payload = {
        full_name:  name,
        reg_number: reg,
        role, phone, email,
        password:   pass,
      };

      // Include student link if a student was selected in the parent section
      if (role === 'parent' && studentId) {
        payload.student_id   = parseInt(studentId, 10);
        payload.relationship = rel;
      }

      const data = await Api.post(`${BASE_URL}/api/admin/users_create.php`, payload);
      Toast.show('success', data.message);
      closeModal('create-modal');
      setTimeout(() => window.location.reload(), 800);
    } catch (err) {
      setErr('create', err.message);
    }
  });
}

// ── Edit contact ──────────────────────────────────────────────────────────────
function openEditModal(id, name, email, phone) {
  clearErr('edit');
  document.getElementById('e-id').value    = id;
  document.getElementById('e-email').value = email;
  document.getElementById('e-phone').value = phone;
  openModal('edit-modal');
}

async function saveContact() {
  clearErr('edit');
  const id    = document.getElementById('e-id').value;
  const email = document.getElementById('e-email').value.trim();
  const phone = document.getElementById('e-phone').value.trim();
  const btn   = document.getElementById('edit-user-btn');

  await Api.withLoading(btn, async () => {
    try {
      const data = await Api.post(`${BASE_URL}/api/admin/users_update.php`, {
        user_id: parseInt(id),
        email:   email || null,
        phone:   phone || null,
      });
      Toast.show('success', data.message);
      closeModal('edit-modal');
    } catch (err) {
      setErr('edit', err.message);
    }
  });
}

// ── Reset password ────────────────────────────────────────────────────────────
function openResetModal(id, name) {
  clearErr('reset');
  document.getElementById('r-id').value  = id;
  document.getElementById('r-name').textContent = name;
  document.getElementById('r-pass').value  = '';
  document.getElementById('r-pass2').value = '';
  openModal('reset-modal');
}

async function resetPassword() {
  clearErr('reset');
  const id    = document.getElementById('r-id').value;
  const pass  = document.getElementById('r-pass').value;
  const pass2 = document.getElementById('r-pass2').value;
  const btn   = document.getElementById('reset-btn');

  if (!pass) { setErr('reset', 'Please enter a new password.'); return; }
  if (pass !== pass2) { setErr('reset', 'Passwords do not match.'); return; }

  await Api.withLoading(btn, async () => {
    try {
      const data = await Api.post(`${BASE_URL}/api/admin/users_reset_password.php`, {
        target_user_id: parseInt(id),
        new_password:   pass,
      });
      Toast.show('success', data.message);
      closeModal('reset-modal');
    } catch (err) {
      setErr('reset', err.message);
    }
  });
}

// ── Toggle active ─────────────────────────────────────────────────────────────
async function toggleActive(userId, activate) {
  const action = activate ? 'activate' : 'deactivate';
  if (!confirm(`${action.charAt(0).toUpperCase() + action.slice(1)} this account?`)) return;

  try {
    const data = await Api.post(`${BASE_URL}/api/admin/users_toggle_active.php`, {
      user_id: userId,
      active:  activate,
    });
    Toast.show('success', data.message);

    const badge = document.getElementById(`status-badge-${userId}`);
    if (badge) {
      badge.textContent = activate ? 'Active' : 'Inactive';
      badge.className   = 'badge ' + (activate ? 'badge-success' : 'badge-neutral');
    }
  } catch (err) {
    Api.showError(err);
  }
}

// ── Student search — create modal (separate state from the link modal) ────────
let _searchTimerCreate = null;

async function searchStudentsCreate(q) {
  clearTimeout(_searchTimerCreate);
  const resultsEl = document.getElementById('c-student-results');

  if (q.length < 2) {
    resultsEl.style.display = 'none';
    return;
  }

  _searchTimerCreate = setTimeout(async () => {
    try {
      const data = await Api.get(
        `${BASE_URL}/api/admin/students_search.php`, { q }
      );
      _renderStudentResultsCreate(data.students || []);
    } catch { /* silent — show nothing on network error */ }
  }, 300);
}

function _renderStudentResultsCreate(students) {
  const el = document.getElementById('c-student-results');

  if (!students.length) {
    el.innerHTML =
      '<div style="padding:12px;color:var(--color-text-muted);font-size:13px">' +
      'No students found</div>';
    el.style.display = 'block';
    return;
  }

  el.innerHTML = students.map(s =>
    `<div onclick="selectStudentCreate(${s.id},
                   '${escHtml(s.reg_number)} — ${escHtml(s.full_name)}')"
          style="padding:10px 14px;cursor:pointer;font-size:13px;
                 border-bottom:1px solid var(--color-border-light)"
          onmouseenter="this.style.background='var(--color-bg-subtle)'"
          onmouseleave="this.style.background=''">
       <strong>${escHtml(s.reg_number)}</strong> — ${escHtml(s.full_name)}
     </div>`
  ).join('');

  el.style.display = 'block';
}

function selectStudentCreate(id, label) {
  document.getElementById('c-student-id').value             = id;
  document.getElementById('c-student-selected').textContent = '✓ ' + label;
  document.getElementById('c-student-results').style.display = 'none';
  document.getElementById('c-student-search').value         = label;
}

// ── Link parent to student ────────────────────────────────────────────────────
function openLinkModal(parentId, parentName) {
  clearErr('link');
  document.getElementById('l-parent-id').value           = parentId;
  document.getElementById('l-parent-name').textContent   = parentName;
  document.getElementById('l-student-search').value      = '';
  document.getElementById('l-student-id').value          = '';
  document.getElementById('l-student-selected').textContent = '';
  document.getElementById('l-student-results').style.display = 'none';
  openModal('link-modal');
}

let searchTimer = null;
async function searchStudents(q) {
  clearTimeout(searchTimer);
  const resultsEl = document.getElementById('l-student-results');

  if (q.length < 2) {
    resultsEl.style.display = 'none';
    return;
  }

  searchTimer = setTimeout(async () => {
    try {
      const data = await Api.get(
        `${BASE_URL}/api/admin/students_search.php`,
        { q }
      );
      renderStudentResults(data.students || []);
    } catch { /* silent */ }
  }, 300);
}

function renderStudentResults(students) {
  const el = document.getElementById('l-student-results');
  if (!students.length) {
    el.innerHTML = '<div style="padding:12px;color:var(--color-text-muted);font-size:13px">No students found</div>';
    el.style.display = 'block';
    return;
  }
  el.innerHTML = students.map(s =>
    `<div onclick="selectStudent(${s.id}, '${escHtml(s.reg_number)} — ${escHtml(s.full_name)}')"
          style="padding:10px 14px;cursor:pointer;font-size:13px;
                 border-bottom:1px solid var(--color-border-light)"
          onmouseenter="this.style.background='var(--color-bg-subtle)'"
          onmouseleave="this.style.background=''">
       <strong>${escHtml(s.reg_number)}</strong> — ${escHtml(s.full_name)}
     </div>`
  ).join('');
  el.style.display = 'block';
}

function selectStudent(id, label) {
  document.getElementById('l-student-id').value           = id;
  document.getElementById('l-student-selected').textContent = '✓ Selected: ' + label;
  document.getElementById('l-student-results').style.display = 'none';
  document.getElementById('l-student-search').value        = label;
}

async function linkParent() {
  clearErr('link');
  const parentId   = document.getElementById('l-parent-id').value;
  const studentId  = document.getElementById('l-student-id').value;
  const rel        = document.getElementById('l-relationship').value;
  const btn        = document.getElementById('link-btn');

  if (!studentId) {
    setErr('link', 'Please search for and select a student.');
    return;
  }

  await Api.withLoading(btn, async () => {
    try {
      const data = await Api.post(`${BASE_URL}/api/admin/link_parent.php`, {
        parent_id:    parseInt(parentId),
        student_id:   parseInt(studentId),
        relationship: rel,
      });
      Toast.show('success', data.message);
      closeModal('link-modal');
    } catch (err) {
      setErr('link', err.message);
    }
  });
}

function escHtml(str) {
  return String(str ?? '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Bulk Add Lecturers ────────────────────────────────────────────────────────
function openBulkLecturersModal() {
  clearErr('bulk-lec');
  document.getElementById('bl-lec-csv').value     = null;
  document.getElementById('bl-lec-result').style.display = 'none';
  openModal('bulk-lec-modal');
}

async function bulkAddLecturers() {
  clearErr('bulk-lec');
  const csvFile = document.getElementById('bl-lec-csv').files[0];
  const btn     = document.getElementById('bl-lec-btn');
  if (!csvFile) { setErr('bulk-lec', 'Please select a CSV file.'); return; }

  document.getElementById('bl-lec-result').style.display = 'none';
  const fd = new FormData();
  fd.append('csv_file', csvFile);

  await Api.withLoading(btn, async () => {
    try {
      const data = await Api.upload(`${BASE_URL}/api/admin/users_bulk_lecturers.php`, fd);
      _showBulkResult('lec', data);
      if (data.created > 0) setTimeout(() => window.location.reload(), 4000);
    } catch (err) { setErr('bulk-lec', err.message); }
  });
}

// ── Bulk Add Parents ──────────────────────────────────────────────────────────
function openBulkParentsModal() {
  clearErr('bulk-par');
  document.getElementById('bl-par-csv').value     = null;
  document.getElementById('bl-par-result').style.display = 'none';
  openModal('bulk-par-modal');
}

async function bulkAddParents() {
  clearErr('bulk-par');
  const csvFile = document.getElementById('bl-par-csv').files[0];
  const btn     = document.getElementById('bl-par-btn');
  if (!csvFile) { setErr('bulk-par', 'Please select a CSV file.'); return; }

  document.getElementById('bl-par-result').style.display = 'none';
  const fd = new FormData();
  fd.append('csv_file', csvFile);

  await Api.withLoading(btn, async () => {
    try {
      const data = await Api.upload(`${BASE_URL}/api/admin/users_bulk_parents.php`, fd);
      _showBulkResult('par', data);
      if (data.created > 0) setTimeout(() => window.location.reload(), 4000);
    } catch (err) { setErr('bulk-par', err.message); }
  });
}

// ── Shared: render credentials table + error list ─────────────────────────────
function _showBulkResult(type, data) {
  const resultDiv  = document.getElementById(`bl-${type}-result`);
  const summaryEl  = document.getElementById(`bl-${type}-summary`);
  const credsDiv   = document.getElementById(`bl-${type}-creds`);
  const credsTbody = document.querySelector(`#bl-${type}-creds-table tbody`);
  const errDiv     = document.getElementById(`bl-${type}-errors`);
  const errList    = document.getElementById(`bl-${type}-errors-list`);

  summaryEl.textContent        = data.message;
  resultDiv.style.display      = 'block';

  if (data.accounts && data.accounts.length) {
    credsTbody.innerHTML = data.accounts.map(a => {
      const contact = [a.email, a.phone].filter(Boolean).join(' / ') || '—';
      if (type === 'par') {
        const students = (a.linked_to || []).join(', ') || '—';
        return `<tr style="border-top:1px solid var(--color-border-light)">
          <td style="padding:6px 8px">${escHtml(a.name)}</td>
          <td style="padding:6px 8px;font-family:monospace;font-weight:600">${escHtml(a.reg_number)}</td>
          <td style="padding:6px 8px">${escHtml(contact)}</td>
          <td style="padding:6px 8px;font-family:monospace">${escHtml(students)}</td>
          <td style="padding:6px 8px;font-family:monospace;color:var(--color-accent)">${escHtml(a.temp_pass)}</td>
        </tr>`;
      } else {
        return `<tr style="border-top:1px solid var(--color-border-light)">
          <td style="padding:6px 8px">${escHtml(a.name)}</td>
          <td style="padding:6px 8px;font-family:monospace;font-weight:600">${escHtml(a.reg_number)}</td>
          <td style="padding:6px 8px">${escHtml(contact)}</td>
          <td style="padding:6px 8px;font-family:monospace;color:var(--color-accent)">${escHtml(a.temp_pass)}</td>
        </tr>`;
      }
    }).join('');
    credsDiv.style.display = 'block';
  } else {
    credsDiv.style.display = 'none';
  }

  if (data.errors && data.errors.length) {
    errList.innerHTML = data.errors.map(e =>
      `<li>Row ${e.row} — <strong>${escHtml(e.name)}</strong>: ${escHtml(e.reason)}</li>`
    ).join('');
    errDiv.style.display = 'block';
  } else {
    errDiv.style.display = 'none';
  }
}

// ── Print credentials table ───────────────────────────────────────────────────
function printCreds(type) {
  const table = document.getElementById(`bl-${type}-creds-table`);
  const title = type === 'lec' ? 'Lecturer Credentials' : 'Parent Credentials';
  const win   = window.open('', '_blank');
  win.document.write(`
    <html><head><title>${title}</title>
    <style>
      body { font-family: sans-serif; font-size: 13px; padding: 20px; }
      h2   { margin-bottom: 12px; }
      table{ border-collapse: collapse; width: 100%; }
      th,td{ border: 1px solid #ccc; padding: 6px 10px; text-align: left; }
      th   { background: #f5f5f5; }
      .note{ margin-top:16px; font-size:11px; color:#666; }
    </style></head><body>
    <h2>${title} — ${new Date().toLocaleDateString()}</h2>
    ${table.outerHTML}
    <p class="note">⚠️ Users must change their temporary password after first login.</p>
    </body></html>`);
  win.document.close();
  win.print();
}

// ── Bulk Link Parents ─────────────────────────────────────────────────────────
function openBulkLinkModal() {
  clearErr('bulk-link');
  document.getElementById('bl-csv').value       = null;
  document.getElementById('bl-summary').style.display = 'none';
  openModal('bulk-link-modal');
}

async function bulkLinkParents() {
  clearErr('bulk-link');
  const csvFile = document.getElementById('bl-csv').files[0];
  const btn     = document.getElementById('bl-btn');

  if (!csvFile) { setErr('bulk-link', 'Please select a CSV file.'); return; }

  // Hide previous result
  document.getElementById('bl-summary').style.display = 'none';

  const formData = new FormData();
  formData.append('csv_file', csvFile);

  await Api.withLoading(btn, async () => {
    try {
      const data = await Api.upload(
        `${BASE_URL}/api/admin/parent_link_bulk.php`, formData
      );

      // Show summary
      document.getElementById('bl-summary-ok').textContent = data.message;
      document.getElementById('bl-summary').style.display  = 'block';

      if (data.errors && data.errors.length) {
        const tbody = document.getElementById('bl-errors-body');
        tbody.innerHTML = data.errors.map(e =>
          `<tr style="border-top:1px solid var(--color-border-light)">
             <td style="padding:4px 8px;color:var(--color-text-muted)">${e.row}</td>
             <td style="padding:4px 8px;font-family:monospace">${escHtml(e.parent)}</td>
             <td style="padding:4px 8px;font-family:monospace">${escHtml(e.student)}</td>
             <td style="padding:4px 8px;color:var(--color-danger)">${escHtml(e.reason)}</td>
           </tr>`
        ).join('');
        document.getElementById('bl-errors').style.display = 'block';
      } else {
        document.getElementById('bl-errors').style.display = 'none';
      }

      // Reload page after a short delay only if something was actually linked
      if (data.linked > 0) {
        setTimeout(() => window.location.reload(), 2500);
      }
    } catch (err) {
      setErr('bulk-link', err.message);
    }
  });
}
</script>

</body>
</html>