<?php
$title = 'Register - Quack Track';
$available_regions = $available_regions ?? [];
$turnstileSiteKey = (get_turnstile_site_key() ?: '0x4AAAAAACFLhqDm-a6VzXvH');
$__flashes = flashes();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?></title>
  <meta name="description" content="Create your Quack Track account to manage inventory and orders">
  <link rel="icon" type="image/png" href="/assets/logo2.png">

  <!-- Stylesheets -->
  <link rel="stylesheet" href="/assets/tokens.css">
  <link rel="stylesheet" href="/assets/base.css">
  <link rel="stylesheet" href="/assets/register.css">

  <!-- Cloudflare Turnstile -->
  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
</head>
<body>
  <div class="register-page">
    <!-- Left Side Image -->
    <?php
    $leftImageUrl = get_register_left_image();
    if (!empty($leftImageUrl)) :
        ?>
      <div class="register-image-left" style="background-image: url('<?= h($leftImageUrl) ?>');"></div>
    <?php else : ?>
      <div class="register-image-left"></div>
    <?php endif; ?>

    <!-- Registration Form Container -->
    <div class="register-container">

      <!-- Header -->
      <div class="register-header">
        <h1>Create Your Account</h1>
        <p>Join Quack Track to manage your inventory</p>
      </div>

      <!-- Flash Messages -->
      <?php if (!empty($__flashes)) :
            ?>
        <div class="flash-container" style="padding: 1.5rem 1.5rem 0;">
            <?php foreach ($__flashes as [$type, $message]) :
                ?>
            <div class="flash <?= h($type) ?>" role="alert">
                <?= h($message) ?>
            </div>
            <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <!-- Registration Form -->
      <form method="post" action="/register" class="register-form" autocomplete="off" novalidate>
        <?= csrf_field() ?>

        <!-- Name Fields -->
        <div class="form-group">
          <label for="first_name" class="required">First Name</label>
          <input
            type="text"
            id="first_name"
            name="first_name"
            required
            placeholder="First name"
            autocomplete="given-name">
        </div>

        <div class="form-group">
          <label for="last_name" class="required">Last Name</label>
          <input
            type="text"
            id="last_name"
            name="last_name"
            required
            placeholder="Last name"
            autocomplete="family-name">
        </div>

        <div class="form-group">
          <label for="username" class="required">Username</label>
          <input
            type="text"
            id="username"
            name="username"
            required
            aria-describedby="username-help"
            placeholder="Choose a username"
            autocomplete="username">
          <div id="username-help" class="form-help">Letters, numbers, dots, underscores, and hyphens only</div>
        </div>

        <!-- Email Field -->
        <div class="form-group">
          <label for="email" class="required">Email Address</label>
          <input
            type="email"
            id="email"
            name="email"
            required
            aria-describedby="email-help"
            placeholder="you@example.com"
            autocomplete="email">
          <div id="email-help" class="form-help">We'll use this email for account communications</div>
        </div>

        <!-- Password Field -->
        <div class="form-group">
          <label for="password" class="required">Password</label>
          <input
            type="password"
            id="password"
            name="password"
            required
            minlength="8"
            aria-describedby="password-help"
            placeholder="Create a strong password"
            autocomplete="new-password">
          <div id="password-help" class="form-help">Choose a strong password with at least 8 characters</div>
          <!-- Password strength indicator will be inserted here by JavaScript -->
        </div>

        <!-- Confirm Password Field -->
        <div class="form-group">
          <label for="confirm" class="required">Confirm Password</label>
          <input
            type="password"
            id="confirm"
            name="confirm"
            required
            minlength="8"
            aria-describedby="confirm-help"
            placeholder="Re-enter your password"
            autocomplete="new-password">
          <div id="confirm-help" class="form-help">Enter the same password again to confirm</div>
        </div>

        <!-- Region Field -->
        <div class="form-group">
          <label for="region_id" class="required">Region</label>
          <select
            id="region_id"
            name="region_id"
            required
            aria-describedby="region-help"
            autocomplete="off">
            <option value="">Select your region</option>
            <?php foreach ($available_regions as $region) : ?>
              <option value="<?= (int)$region['id'] ?>"><?= h($region['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div id="region-help" class="form-help">Choose the geographical region you'll be working in</div>
        </div>

        <!-- Turnstile Widget (only shown if enabled) -->
        <?php if (is_turnstile_enabled()) : ?>
        <div class="turnstile-container">
          <div
            class="cf-turnstile"
            data-sitekey="<?= h($turnstileSiteKey) ?>"
            data-theme="light"
            data-size="normal"
            aria-label="Security verification">
          </div>
        </div>
        <?php endif; ?>

        <!-- Submit Button -->
        <button type="submit" class="btn-submit">
          Create Account
        </button>
      </form>

      <!-- Footer -->
      <div class="register-footer">
        <p>
          Already have an account?
          <a href="/login">Sign in here</a>
        </p>
      </div>

    </div>

    <!-- Right Side Image -->
    <?php
    $rightImageUrl = get_register_right_image();
    if (!empty($rightImageUrl)) :
        ?>
      <div class="register-image-right" style="background-image: url('<?= h($rightImageUrl) ?>');"></div>
    <?php else : ?>
      <div class="register-image-right"></div>
    <?php endif; ?>
  </div>

  <!-- JavaScript -->
  <script src="/assets/register.js"></script>
</body>
</html>
