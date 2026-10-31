<?php

/**
 * Sign out. Only a POST carrying the session's CSRF token ends the session (audit finding H6), so another site cannot
 * sign a user out with a link or an image. A GET shows a one-button form that posts here.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    if (empty($_SESSION['uid'])) {
        redirect('/login.php');
    }
    header('Cache-Control: no-store');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign out - QuackTrack</title>
  <link rel="stylesheet" href="/assets/tokens.css">
  <link rel="stylesheet" href="/assets/pages/auth.css">
</head>
<body>
<div class="auth-page">
  <main class="auth-card">
    <div class="auth-card-header">
      <h1 class="auth-card-title">Sign out?</h1>
    </div>
    <div class="auth-card-body">
      <form method="post" action="/logout">
        <?= csrf_field() ?>
        <button type="submit" class="auth-submit">Sign out</button>
      </form>
    </div>
    <div class="auth-card-footer">
      <p class="auth-footer-text"><a href="/" class="auth-footer-link">&larr; Back to Dashboard</a></p>
    </div>
  </main>
</div>
</body>
</html>
    <?php
    exit;
}

csrf_verify();
Logger::security('user_logout', [
    'user_id' => $_SESSION['uid'] ?? null,
    'session_duration' => isset($_SESSION['login_time']) ? (time() - $_SESSION['login_time']) : null
]);

destroy_session();
redirect('/login.php');
