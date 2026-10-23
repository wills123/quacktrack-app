<?php
$title = 'Sign In — Quack Track';
?>
<!DOCTYPE html>
<html lang="en" <?php
  // Honour saved theme preference so the page renders in dark mode immediately
  // (same inline script pattern used on all redesigned pages, but here we set
  //  the attribute directly on <html> before any CSS is parsed)
?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?></title>
  <meta name="description" content="Sign in to your Quack Track account">
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
      <p class="auth-brand-accent-text">Track van stock, place orders, manage IT assets, and coordinate store billing — all in one place.</p>
    </div>
  </aside>

  <!-- Form panel -->
  <div class="auth-form-panel">
    <div class="auth-card">

      <div class="auth-card-header">
        <h1 class="auth-card-title">Welcome back</h1>
        <p class="auth-card-sub">Sign in to access your account</p>
      </div>

      <div class="auth-card-body">

        <?php if (snipe_oauth_is_configured()) : ?>
            <?php $oauthUrl = snipe_oauth_authorize_url('login'); ?>
          <a href="<?= h($oauthUrl) ?>" class="auth-oauth-btn">
            <svg class="auth-oauth-icon" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true">
              <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/>
            </svg>
            Sign in with Snipe IT
          </a>
          <div class="auth-divider"><span>or use email</span></div>
        <?php endif; ?>

        <?php foreach (flashes() as [$type, $message]) : ?>
          <div class="auth-flash <?= h($type) ?>" role="alert"><?= h($message) ?></div>
        <?php endforeach; ?>

        <form method="post" action="/login" autocomplete="off" novalidate>
          <?= csrf_field() ?>

          <div class="auth-field" style="margin-bottom:14px">
            <label class="auth-label" for="email">
              Email Address <span class="required">*</span>
            </label>
            <input class="auth-input"
                   type="email" id="email" name="email"
                   required autocomplete="email" autofocus
                   placeholder="you@example.com"
                   aria-describedby="email-help">
            <span id="email-help" class="auth-field-help">Enter your registered email address</span>
          </div>

          <div class="auth-field" style="margin-bottom:20px">
            <label class="auth-label" for="password">
              Password <span class="required">*</span>
            </label>
            <input class="auth-input"
                   type="password" id="password" name="password"
                   required autocomplete="current-password"
                   placeholder="Enter your password">
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

          <button type="submit" class="auth-submit">Sign In</button>
        </form>

      </div>

      <div class="auth-card-footer">
        <p class="auth-footer-text">
          Don't have an account?
          <a href="/register" class="auth-footer-link">Create one here</a>
        </p>
        <p class="auth-footer-text">
          <a href="/forgot_password.php" class="auth-footer-link">Forgot your password?</a>
        </p>
      </div>

    </div>
  </div>

</div>

<script src="/assets/login.js"></script>
</body>
</html>
