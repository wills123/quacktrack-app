<?php
$title      = 'Connect Snipe-IT — IT Assets';
$main_class = 'main-content';
require __DIR__ . '/header.php';
?>
<script>
(function(){
  var t = localStorage.getItem('kit_theme');
  if (t === 'dark' || (t === null && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.documentElement.setAttribute('data-kit-theme', 'dark');
  }
}());
</script>

<div class="snipe-connect-page">
  <div class="snipe-connect-card">

    <div class="snipe-connect-icon">
      <svg width="48" height="48" viewBox="0 0 48 48" fill="none" aria-hidden="true">
        <rect width="48" height="48" rx="12" fill="var(--color-accent, #4f6ef7)" opacity="0.12"/>
        <path d="M24 10a14 14 0 1 1 0 28A14 14 0 0 1 24 10zm0 3a11 11 0 1 0 0 22 11 11 0 0 0 0-22zm0 4a1.5 1.5 0 0 1 1.5 1.5v6.086l3.207 3.207a1.5 1.5 0 0 1-2.121 2.121l-3.5-3.5A1.5 1.5 0 0 1 22.5 25v-6.5A1.5 1.5 0 0 1 24 17z"
              fill="var(--color-accent, #4f6ef7)"/>
      </svg>
    </div>

    <h1 class="snipe-connect-title">Connect your Snipe-IT account</h1>

    <p class="snipe-connect-body">
      To use the Assets page, sign in with your Snipe-IT account.
      This links your identity so that every checkout, return, and update
      is recorded under your name in Snipe-IT's audit log.
    </p>

    <p class="snipe-connect-body snipe-connect-body--sub">
      Your Quack Track login stays unchanged — this is a one-time connection
      that renews automatically in the background.
    </p>

    <?php if ($oauthEnabled ?? false) : ?>
      <a href="/api/snipe-link-start.php" class="snipe-connect-btn">
        Sign in with Snipe-IT
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
          <path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </a>
    <?php else : ?>
      <div class="snipe-connect-disabled">
        <p>Snipe-IT OAuth is not enabled on this installation.</p>
        <p>Ask your administrator to set <code>SNIPE_IT_OAUTH_ENABLED=1</code> in the environment configuration.</p>
      </div>
    <?php endif; ?>

    <?php if (!empty($snipeBaseUrl)) : ?>
      <p class="snipe-connect-hint">
        Don't have a Snipe-IT account?
        <a href="<?= h($snipeBaseUrl) ?>" target="_blank" rel="noopener">Open Snipe-IT ↗</a>
        and ask your administrator to create one for you.
      </p>
    <?php endif; ?>

  </div>
</div>

<style>
.snipe-connect-page {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: calc(100vh - 120px);
  padding: 2rem 1rem;
}

.snipe-connect-card {
  background: var(--color-surface, #fff);
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: 16px;
  padding: 2.5rem 2rem;
  max-width: 440px;
  width: 100%;
  text-align: center;
  box-shadow: 0 4px 24px rgba(0,0,0,.06);
}

[data-kit-theme="dark"] .snipe-connect-card {
  background: var(--color-surface, #1e2433);
  border-color: var(--color-border, #2d3748);
  box-shadow: 0 4px 24px rgba(0,0,0,.3);
}

.snipe-connect-icon {
  margin-bottom: 1.25rem;
}

.snipe-connect-title {
  font-size: 1.375rem;
  font-weight: 700;
  color: var(--color-text-primary, #1a202c);
  margin: 0 0 1rem;
}

[data-kit-theme="dark"] .snipe-connect-title {
  color: var(--color-text-primary, #f0f4ff);
}

.snipe-connect-body {
  font-size: 0.9375rem;
  color: var(--color-text-secondary, #4a5568);
  line-height: 1.6;
  margin: 0 0 0.75rem;
}

.snipe-connect-body--sub {
  font-size: 0.875rem;
  color: var(--color-text-muted, #718096);
  margin-bottom: 1.75rem;
}

[data-kit-theme="dark"] .snipe-connect-body {
  color: var(--color-text-secondary, #a0aec0);
}

[data-kit-theme="dark"] .snipe-connect-body--sub {
  color: var(--color-text-muted, #718096);
}

.snipe-connect-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  background: var(--color-accent, #4f6ef7);
  color: #fff;
  font-weight: 600;
  font-size: 0.9375rem;
  padding: 0.75rem 1.5rem;
  border-radius: 8px;
  text-decoration: none;
  transition: opacity .15s, transform .15s;
  margin-bottom: 1.25rem;
}

.snipe-connect-btn:hover {
  opacity: .9;
  transform: translateY(-1px);
}

.snipe-connect-disabled {
  background: var(--color-warning-bg, #fffbeb);
  border: 1px solid var(--color-warning-border, #f6e05e);
  border-radius: 8px;
  padding: 1rem;
  margin-bottom: 1.25rem;
  font-size: 0.875rem;
  color: var(--color-text-secondary, #4a5568);
}

.snipe-connect-disabled code {
  background: rgba(0,0,0,.06);
  padding: 0.1em 0.35em;
  border-radius: 4px;
  font-size: 0.8125rem;
}

.snipe-connect-hint {
  font-size: 0.8125rem;
  color: var(--color-text-muted, #718096);
  margin: 0;
}

.snipe-connect-hint a {
  color: var(--color-accent, #4f6ef7);
  text-decoration: none;
}

.snipe-connect-hint a:hover {
  text-decoration: underline;
}
</style>

<?php require __DIR__ . '/footer.php'; ?>
