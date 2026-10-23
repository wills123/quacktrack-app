<?php
$title = 'Stock Settings';
$current_settings = $current_settings ?? [];
$main_class = 'container admin-page';
require __DIR__ . '/../header.php';
?>

<?php foreach (flashes() as [$t,$m]) : ?>
  <div class="flash <?=h($t)?>"><?=h($m)?></div>
<?php endforeach; ?>

<h1>Stock Operation Settings</h1>

<p>Configure validation limits and rate limiting for stock operations. These settings help ensure security and prevent abuse while remaining flexible for your operational needs.</p>

<form method="post" action="/admin/stock_settings" class="settings-form">
  <?=csrf_field()?>
  <input type="hidden" name="action" value="save_settings">

  <h2>Validation Limits</h2>
  <div class="settings-section">
    <label>
      Maximum Stock Quantity per Operation
      <input
        type="number"
        name="stock_max_quantity"
        value="<?= (int)$current_settings['stock_max_quantity'] ?>"
        min="1"
        max="1000000"
        required
      >
      <small class="help-text">Maximum quantity allowed for add/adjust/transfer operations (1-1,000,000). Default: 10,000</small>
    </label>

    <label>
      Maximum Note Length (characters)
      <input
        type="number"
        name="stock_max_note_length"
        value="<?= (int)$current_settings['stock_max_note_length'] ?>"
        min="10"
        max="5000"
        required
      >
      <small class="help-text">Maximum characters allowed in operation notes (10-5,000). Default: 500</small>
    </label>
  </div>

  <h2>Rate Limiting</h2>
  <div class="settings-section">
    <label>
      Operations per Window
      <input
        type="number"
        name="stock_rate_limit_per_minute"
        value="<?= (int)$current_settings['stock_rate_limit_per_minute'] ?>"
        min="1"
        max="1000"
        required
      >
      <small class="help-text">Maximum stock operations allowed per user within the rate limit window (1-1,000). Default: 50</small>
    </label>

    <label>
      Rate Limit Window (seconds)
      <input
        type="number"
        name="stock_rate_limit_window_seconds"
        value="<?= (int)$current_settings['stock_rate_limit_window_seconds'] ?>"
        min="1"
        max="3600"
        required
      >
      <small class="help-text">Time window for rate limiting in seconds (1-3,600). Default: 60 (1 minute)</small>
    </label>

    <div class="info-box">
      <strong>Current Rate Limit:</strong>
      <?= (int)$current_settings['stock_rate_limit_per_minute'] ?> operations
      per <?= (int)$current_settings['stock_rate_limit_window_seconds'] ?> seconds
    </div>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-primary">Save Settings</button>
    <a href="/admin" class="btn btn-secondary">Cancel</a>
  </div>
</form>

<style>
.settings-form {
  max-width: 800px;
}

.settings-section {
  margin-bottom: 2rem;
  padding: 1.5rem;
  background: var(--surface-2);
  border-radius: 4px;
}

.settings-section label {
  display: block;
  margin-bottom: 1.5rem;
}

.settings-section label:last-child {
  margin-bottom: 0;
}

.help-text {
  display: block;
  margin-top: 0.5rem;
  color: var(--text-secondary);
  font-size: 0.9em;
  line-height: 1.4;
}

.info-box {
  padding: 1rem;
  background: var(--badge-info-bg);
  border-left: 4px solid var(--color-info);
  margin-top: 1rem;
  border-radius: 4px;
}

.form-actions {
  margin-top: 2rem;
  padding-top: 1rem;
  border-top: 1px solid var(--border);
}

.form-actions .btn {
  margin-right: 0.5rem;
}
</style>

<?php require __DIR__ . '/../footer.php'; ?>
