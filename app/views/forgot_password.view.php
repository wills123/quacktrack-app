<?php
$title = 'Forgot Password — Quack Track';
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
  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
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
      <p class="auth-brand-accent-text">We'll send a secure link to your email so you can get back in quickly.</p>
    </div>
  </aside>

  <!-- Form panel -->
  <div class="auth-form-panel">
    <div class="auth-card">

      <div class="auth-card-header">
        <h1 class="auth-card-title">Forgot your password?</h1>
        <p class="auth-card-sub">Enter your email and we'll send you a reset link.</p>
      </div>

      <div class="auth-card-body">

        <?php foreach (flashes() as [$type, $message]) : ?>
          <div class="auth-flash <?= h($type) ?>" role="alert"><?= h($message) ?></div>
        <?php endforeach; ?>

        <form method="POST" action="/forgot_password.php">
          <?= csrf_field() ?>

          <div class="auth-field" style="margin-bottom:20px">
            <label class="auth-label" for="email">Email Address</label>
            <input class="auth-input"
                   type="email" id="email" name="email"
                   required autocomplete="email" autofocus
                   placeholder="you@example.com"
                   value="<?= h($_POST['email'] ?? '') ?>">
          </div>

          <?php if (is_turnstile_enabled()) : ?>
            <div class="auth-turnstile">
              <div class="cf-turnstile"
                   data-sitekey="<?= h(get_turnstile_site_key() ?: '0x4AAAAAACFLhqDm-a6VzXvH') ?>"
                   data-theme="auto"
                   data-size="normal"
                   aria-label="Security verification"></div>
            </div>
          <?php endif; ?>

          <button type="submit" class="auth-submit">Send Reset Link</button>
        </form>

      </div>

      <div class="auth-card-footer">
        <p class="auth-footer-text">
          <a href="/login" class="auth-footer-link">&larr; Back to sign in</a>
        </p>
      </div>

    </div>
  </div>

</div>

</body>
</html>
