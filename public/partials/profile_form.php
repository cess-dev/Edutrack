<?php
/**
 * EduTrack — Profile Form Partial
 *
 * Shared by all four portal profile pages.
 * Expects $profile (array from UserModel::findById) and $csrfToken in scope.
 */
?>

<!-- Identity card -->
<div class="card animate-fade-in" style="margin-bottom:var(--space-5)">
  <div style="display:flex;align-items:center;gap:var(--space-5)">
    <div style="width:64px;height:64px;border-radius:50%;
                background:linear-gradient(135deg,var(--color-accent),var(--color-primary));
                color:white;font-family:var(--font-heading);
                font-size:var(--text-2xl);display:grid;
                place-items:center;flex-shrink:0">
      <?= strtoupper(substr($profile['full_name'] ?? 'U', 0, 1)) ?>
    </div>
    <div>
      <div style="font-size:var(--text-xl);font-weight:var(--weight-semibold);
                  color:var(--color-primary);margin-bottom:var(--space-1)">
        <?= htmlspecialchars($profile['full_name'] ?? '') ?>
      </div>
      <div class="text-sm text-muted">
        <span class="font-mono"><?= htmlspecialchars($profile['reg_number'] ?? '') ?></span>
        &nbsp;·&nbsp;
        <span class="badge badge-info" style="font-size:var(--text-xs)">
          <?= ucfirst($profile['role'] ?? '') ?>
        </span>
      </div>
      <?php if ($profile['last_login']): ?>
        <div class="text-xs text-muted" style="margin-top:var(--space-1)">
          Last login: <?= date('d M Y, H:i', strtotime($profile['last_login'])) ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Contact details -->
<div class="card animate-fade-in" style="margin-bottom:var(--space-5);animation-delay:0.05s">
  <div class="card-header">
    <div class="card-title">Contact Details</div>
  </div>
  <div data-error-container="contact"
       class="alert alert-error" style="margin-bottom:var(--space-4)"></div>
  <div data-success-container="contact"
       class="alert alert-success" style="margin-bottom:var(--space-4);display:none"></div>

  <div class="form-group">
    <label class="form-label">Email Address</label>
    <input type="email"
           id="contact-email"
           class="form-control"
           value="<?= htmlspecialchars($profile['email'] ?? '') ?>"
           placeholder="your@email.com">
  </div>
  <div class="form-group">
    <label class="form-label">Phone Number</label>
    <input type="tel"
           id="contact-phone"
           class="form-control"
           value="<?= htmlspecialchars($profile['phone'] ?? '') ?>"
           placeholder="e.g. 0722000001">
  </div>
  <div class="form-actions" style="padding-top:var(--space-4)">
    <button class="btn btn-primary" id="save-contact-btn"
            onclick="saveContact()">
      Save Contact Details
    </button>
  </div>
</div>

<!-- Change password -->
<div class="card animate-fade-in" style="animation-delay:0.1s">
  <div class="card-header">
    <div class="card-title">Change Password</div>
  </div>
  <div data-error-container="password"
       class="alert alert-error" style="margin-bottom:var(--space-4)"></div>
  <div data-success-container="password"
       class="alert alert-success" style="margin-bottom:var(--space-4);display:none"></div>

  <div class="form-group">
    <label class="form-label">
      Current Password <span class="required">*</span>
    </label>
    <input type="password" id="curr-pass" class="form-control"
           autocomplete="current-password">
  </div>
  <div class="form-group">
    <label class="form-label">
      New Password <span class="required">*</span>
    </label>
    <input type="password" id="new-pass" class="form-control"
           autocomplete="new-password"
           placeholder="Min 8 chars, uppercase, number, special char">
  </div>
  <div class="form-group">
    <label class="form-label">
      Confirm New Password <span class="required">*</span>
    </label>
    <input type="password" id="new-pass2" class="form-control"
           autocomplete="new-password">
  </div>
  <div class="form-actions" style="padding-top:var(--space-4)">
    <button class="btn btn-primary" id="change-pass-btn"
            onclick="changePassword()">
      Change Password
    </button>
  </div>
</div>