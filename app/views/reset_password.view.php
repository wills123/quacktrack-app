<?php
$title = 'Reset Password — Quack Track';
$token_valid = $token_valid ?? false;
$user = $user ?? [];
$token = $token ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?></title>
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
      <p class="auth-brand-accent-text">Choose a strong password — at least 12 characters, with upper and lower case, a number and a symbol. You can sign in after resetting it.</p>
    </div>
  </aside>

  <!-- Form panel -->
  <div class="auth-form-panel">
    <div class="auth-card">

      <div class="auth-card-header">
        <h1 class="auth-card-title">Set a new password</h1>
        <p class="auth-card-sub">Enter and confirm your new password below.</p>
      </div>

      <div class="auth-card-body">

        <?php if ($token_valid && $user) : ?>
          <div class="auth-user-strip">
            Resetting password for
            <strong><?= h($user['email']) ?></strong>
          </div>
        <?php endif; ?>

        <?php foreach (flashes() as [$type, $message]) : ?>
          <div class="auth-flash <?= h($type) ?>" role="alert"><?= h($message) ?></div>
        <?php endforeach; ?>

        <?php if ($token_valid) : ?>
          <form method="POST" action="/reset_password.php?token=<?= h($token) ?>">
            <?= csrf_field() ?>

            <div class="auth-field" style="margin-bottom:14px">
              <label class="auth-label" for="password">New Password</label>
              <input class="auth-input"
                     type="password" id="password" name="password"
                     required autocomplete="new-password"
                     placeholder="At least 12 characters"
                     minlength="12">
              <span class="auth-pw-hint">12+ characters with upper and lower case, a number and a symbol.</span>
            </div>

            <div class="auth-field" style="margin-bottom:20px">
              <label class="auth-label" for="confirm_password">Confirm New Password</label>
              <input class="auth-input"
                     type="password" id="confirm_password" name="confirm_password"
                     required autocomplete="new-password"
                     placeholder="Repeat your new password"
                     minlength="12">
            </div>

            <button type="submit" class="auth-submit">Reset Password</button>
          </form>
        <?php endif; ?>

      </div>

      <div class="auth-card-footer">
        <p class="auth-footer-text">
          <a href="/login" class="auth-footer-link">&larr; Back to sign in</a>
        </p>
      </div>

    </div>
  </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const password        = document.getElementById('password');
  const confirmPassword = document.getElementById('confirm_password');
  const form            = password?.closest('form');

  if (!password || !confirmPassword || !form) return;

  function validatePasswords() {
    if (confirmPassword.value && password.value !== confirmPassword.value) {
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

  password.addEventListener('input', validatePasswords);
  confirmPassword.addEventListener('input', validatePasswords);
});
</script>

</body>
</html>
