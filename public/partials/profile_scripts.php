<script>
/**
 * EduTrack — Profile Page Scripts
 * Shared by all four portal profile pages.
 * Requires ajax.js to be loaded before this partial.
 */
const BASE_URL = <?= json_encode(BASE_URL) ?>;

function getErr(key)  { return document.querySelector(`[data-error-container="${key}"]`);   }
function getOk(key)   { return document.querySelector(`[data-success-container="${key}"]`); }

function showErr(key, msg) {
  const e = getErr(key), o = getOk(key);
  if (e) { e.textContent = msg; e.hidden = false; }
  if (o) { o.style.display = 'none'; }
}

function showOk(key, msg) {
  const e = getErr(key), o = getOk(key);
  if (e) { e.textContent = ''; e.hidden = true; }
  if (o) { o.textContent = msg; o.style.display = 'flex'; }
}

function clearAll(key) {
  const e = getErr(key), o = getOk(key);
  if (e) { e.textContent = ''; e.hidden = true; }
  if (o) { o.style.display = 'none'; }
}

// ── Save contact details ──────────────────────────────────────────────────────
async function saveContact() {
  clearAll('contact');
  const email = document.getElementById('contact-email').value.trim();
  const phone = document.getElementById('contact-phone').value.trim();
  const btn   = document.getElementById('save-contact-btn');

  await Api.withLoading(btn, async () => {
    try {
      const data = await Api.post(`${BASE_URL}/api/profile/update_contact.php`, {
        email: email || null,
        phone: phone || null,
      });
      showOk('contact', data.message || 'Contact details updated.');
    } catch (err) {
      showErr('contact', err.message || 'Failed to save. Please try again.');
    }
  });
}

// ── Change password ───────────────────────────────────────────────────────────
async function changePassword() {
  clearAll('password');
  const curr  = document.getElementById('curr-pass').value;
  const newP  = document.getElementById('new-pass').value;
  const newP2 = document.getElementById('new-pass2').value;
  const btn   = document.getElementById('change-pass-btn');

  if (!curr || !newP || !newP2) {
    showErr('password', 'All three password fields are required.');
    return;
  }

  if (newP !== newP2) {
    showErr('password', 'New passwords do not match.');
    return;
  }

  await Api.withLoading(btn, async () => {
    try {
      const data = await Api.post(`${BASE_URL}/api/profile/change_password.php`, {
        current_password: curr,
        new_password:     newP,
      });
      showOk('password', data.message || 'Password changed successfully.');
      document.getElementById('curr-pass').value  = '';
      document.getElementById('new-pass').value   = '';
      document.getElementById('new-pass2').value  = '';
    } catch (err) {
      showErr('password', err.message || 'Failed to change password.');
    }
  });
}
</script>