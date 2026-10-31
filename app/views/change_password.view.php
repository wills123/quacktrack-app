<?php
$page_title = $page_title ?? 'Change Password';
$is_forced_reset = $is_forced_reset ?? false;
$display_name = $display_name ?? '';
$error = $error ?? null;
$success = $success ?? null;
$doc_title = h($page_title) . ' — Quack Track';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $doc_title ?></title>
  <link rel="icon" type="image/png" href="/assets/logo2.png">
  <link rel="stylesheet" href="/assets/tokens.css">
  <link rel="stylesheet" href="/assets/pages/auth.css">
  <script>
    (function(){
      var t = localStorage.getItem('kit_theme');
      if (t === 'dark' || (t === null && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.setAttribute('data-kit-theme', 'dark');
      }
    }());
  </script>
</head>
<body>

<div class="auth-page">

  <!-- Brand panel -->
  <aside class="auth-brand" aria-hidden="true">
    <div class="auth-brand-logo">
      <img src="/assets/logo2.png" alt="">
    </div>
    <div class="auth-brand-wordmark">
      <p class="auth-brand-name">Quack Track</p>
      <p class="auth-brand-tagline">Inventory &amp; order management<br>for field technicians.</p>
    </div>
    <div class="auth-brand-accent">
      <p class="auth-brand-accent-text">Use at least 12 characters with a mix of uppercase, lowercase, numbers, and symbols for a strong password.</p>
    </div>
  </aside>

  <!-- Form panel -->
  <div class="auth-form-panel">
    <div class="auth-card">

      <div class="auth-card-header">
        <h1 class="auth-card-title"><?= h($page_title) ?></h1>
        <p class="auth-card-sub">
          <?php if ($is_forced_reset) : ?>
            You must set a new password before continuing.
          <?php else : ?>
            Update your password to keep your account secure.
          <?php endif; ?>
        </p>
      </div>

      <div class="auth-card-body">

        <?php if ($is_forced_reset) : ?>
          <div class="auth-forced-notice" role="alert">
            <svg class="auth-forced-notice-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
              <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
            </svg>
            <div>
              <strong>Password Reset Required</strong>
              An administrator has requested that you change your password. Please choose a new, secure password to regain access to your account.
            </div>
          </div>
        <?php endif; ?>

        <div class="auth-user-strip">
          Signed in as <strong><?= h($display_name ?: ($user['email'] ?? '')) ?></strong>
        </div>

        <?php if ($error) : ?>
          <div class="auth-flash error" role="alert"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($success) : ?>
          <div class="auth-flash success" role="alert"><?= h($success) ?></div>
        <?php endif; ?>

        <form method="POST" action="/change_password.php" id="change-password-form">
          <?= csrf_field() ?>

          <div class="auth-field" style="margin-bottom:14px">
            <label class="auth-label" for="current_password">
              Current Password <span class="required">*</span>
            </label>
            <input class="auth-input"
                   type="password" id="current_password" name="current_password"
                   required autocomplete="current-password"
                   placeholder="Enter your current password">
          </div>

          <div class="auth-field" style="margin-bottom:6px">
            <label class="auth-label" for="new_password">
              New Password <span class="required">*</span>
            </label>
            <input class="auth-input"
                   type="password" id="new_password" name="new_password"
                   required autocomplete="new-password"
                   placeholder="At least 12 characters"
                   minlength="12">
            <div class="auth-strength-meter">
              <div class="auth-strength-bar" id="strength-bar"></div>
            </div>
          </div>

          <div class="auth-pw-requirements" style="margin-bottom:14px">
            <strong>Password requirements</strong>
            <ul>
              <li>At least 12 characters long</li>
              <li>At least one uppercase letter (A–Z)</li>
              <li>At least one lowercase letter (a–z)</li>
              <li>At least one number (0–9)</li>
              <li>At least one special character (!@#$%^&amp;*)</li>
              <li>Cannot reuse your current password</li>
            </ul>
          </div>

          <div class="auth-field" style="margin-bottom:20px">
            <label class="auth-label" for="confirm_password">
              Confirm New Password <span class="required">*</span>
            </label>
            <input class="auth-input"
                   type="password" id="confirm_password" name="confirm_password"
                   required autocomplete="new-password"
                   placeholder="Repeat your new password"
                   minlength="12">
          </div>

          <button type="submit" class="auth-submit" id="submit-btn">Change Password</button>
        </form>

      </div>

      <div class="auth-card-footer">
        <?php if (!$is_forced_reset) : ?>
          <p class="auth-footer-text">
            <a href="/" class="auth-footer-link">&larr; Back to Dashboard</a>
          </p>
        <?php else : ?>
          <form method="post" action="/logout" class="auth-footer-text">
            <?= csrf_field() ?>
            <button type="submit" class="auth-footer-link auth-footer-button">Sign out instead</button>
          </form>
        <?php endif; ?>
      </div>

    </div>
  </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const currentPassword = document.getElementById('current_password');
  const newPassword     = document.getElementById('new_password');
  const confirmPassword = document.getElementById('confirm_password');
  const form            = document.getElementById('change-password-form');
  const submitBtn       = document.getElementById('submit-btn');
  const strengthBar     = document.getElementById('strength-bar');

  function calculateStrength(pw) {
    let s = 0;
    if (pw.length >= 12) s++;
    if (pw.length >= 16) s++;
    if (/[a-z]/.test(pw)) s++;
    if (/[A-Z]/.test(pw)) s++;
    if (/[0-9]/.test(pw)) s++;
    if (/[^A-Za-z0-9]/.test(pw)) s++;
    return s;
  }

  newPassword.addEventListener('input', function() {
    const s = calculateStrength(this.value);
    strengthBar.className = 'auth-strength-bar';
    if (s === 0)      strengthBar.style.width = '0%';
    else if (s <= 3)  strengthBar.classList.add('weak');
    else if (s <= 5)  strengthBar.classList.add('medium');
    else              strengthBar.classList.add('strong');
    validatePasswords();
  });

  function validatePasswords() {
    // New must differ from current
    if (newPassword.value && currentPassword.value &&
        newPassword.value === currentPassword.value) {
      newPassword.setCustomValidity('New password must be different from your current password');
    } else {
      newPassword.setCustomValidity('');
    }

    // Confirm must match new
    if (confirmPassword.value && newPassword.value !== confirmPassword.value) {
      confirmPassword.setCustomValidity('Passwords do not match');
      confirmPassword.classList.add('invalid');
      confirmPassword.classList.remove('valid');
    } else if (confirmPassword.value) {
      confirmPassword.setCustomValidity('');
      confirmPassword.classList.add('valid');
      confirmPassword.classList.remove('invalid');
    } else {
      confirmPassword.setCustomValidity('');
      confirmPassword.classList.remove('valid', 'invalid');
    }
  }

  currentPassword.addEventListener('input', validatePasswords);
  newPassword.addEventListener('input', validatePasswords);
  confirmPassword.addEventListener('input', validatePasswords);

  form.addEventListener('submit', function(e) {
    validatePasswords();
    if (!form.checkValidity()) { e.preventDefault(); return; }
    submitBtn.disabled = true;
    submitBtn.textContent = 'Changing password…';
  });
});
</script>

</body>
</html>
