<?php
$title = 'Database Migrations';
$migrations = $migrations ?? [];
$main_class = 'container admin-page';
require __DIR__ . '/../header.php';
?>

<style>
  .migration-status {
    margin: 2rem 0;
  }

  .migration-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    margin: 0.5rem 0;
    background: var(--surface-2);
    border-radius: 8px;
    border-left: 4px solid var(--text-muted);
  }

  .migration-item.applied {
    background: var(--badge-in-bg);
    border-left-color: var(--color-success);
  }

  .migration-item.pending {
    background: var(--badge-low-bg);
    border-left-color: var(--color-warning);
  }

  .migration-filename {
    font-family: monospace;
    font-weight: 600;
  }

  .migration-date {
    font-size: 0.9em;
    color: var(--text-secondary);
  }

  .badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 4px;
    font-size: 0.85em;
    font-weight: 600;
  }

  .badge-success {
    background-color: var(--badge-in-bg);
    color: var(--badge-in-text);
  }

  .badge-warning {
    background-color: var(--color-warning);
    color: var(--on-accent);
  }

  .output-console {
    background: #1e1e1e;
    color: #d4d4d4;
    padding: 1.5rem;
    border-radius: 8px;
    font-family: 'Courier New', monospace;
    font-size: 0.9em;
    line-height: 1.6;
    max-height: 500px;
    overflow-y: auto;
    margin: 2rem 0;
  }

  .output-line {
    margin: 0.25rem 0;
  }

  .output-success {
    color: #4ec9b0;
  }

  .output-error {
    color: #f48771;
  }

  .output-info {
    color: #dcdcaa;
  }

  .output-skip {
    color: #808080;
  }

  .migration-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin: 2rem 0;
  }

  .summary-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 1.5rem;
    text-align: center;
  }

  .summary-number {
    font-size: 2.5em;
    font-weight: bold;
    color: var(--text-brand);
    margin: 0.5rem 0;
  }

  .summary-label {
    color: var(--text-secondary);
    font-size: 0.9em;
    text-transform: uppercase;
  }

  .warning-box {
    background: var(--badge-low-bg);
    border: 1px solid var(--color-warning);
    border-radius: 8px;
    padding: 1rem;
    margin: 1rem 0;
  }

  .warning-box strong {
    color: var(--badge-low-text);
  }
</style>

<?php foreach (flashes() as [$t,$m]) : ?>
  <div class="flash <?=h($t)?>"><?= h($m) ?></div>
<?php endforeach; ?>

<h1>Database Migrations</h1>

<p>Manage database schema migrations for Quack Track. Migrations are applied in order and tracked to prevent duplicate execution.</p>

<div class="migration-summary">
  <div class="summary-card">
    <div class="summary-label">Total Migrations</div>
    <div class="summary-number"><?= count($migrations) ?></div>
  </div>
  <div class="summary-card">
    <div class="summary-label">Applied</div>
    <div class="summary-number" style="color: var(--badge-in-text);">
      <?= count(array_filter($migrations, fn($m) => $m['applied'])) ?>
    </div>
  </div>
  <div class="summary-card">
    <div class="summary-label">Pending</div>
    <div class="summary-number" style="color: var(--badge-low-text);">
      <?= count(array_filter($migrations, fn($m) => !$m['applied'])) ?>
    </div>
  </div>
</div>

<div class="warning-box">
  <strong>⚠ Important:</strong> Running migrations will modify your database schema. Make sure you have a backup before proceeding. Migrations cannot be automatically rolled back.
</div>

<form method="post" action="/admin/migrations" id="migration-form" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="run_migrations">
  <button type="submit" class="btn btn-primary" id="run-migrations-btn">
    Run Pending Migrations
  </button>
</form>

<form method="post" action="/admin/migrations" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:10px;"
  data-confirm="Delete stale survey drafts older than 30 days?"
  data-confirm-title="Cleanup Stale Drafts"
  data-confirm-confirm-text="Delete"
  data-confirm-style="danger">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="cleanup_stale_drafts">
  <button type="submit" class="btn btn-warning">
    Cleanup Stale Survey Drafts (30d)
  </button>
  <a href="/admin" class="btn btn-secondary">Back to Admin Dashboard</a>
</form>

<?php if (!empty($output)) : ?>
<div class="output-console">
  <div style="margin-bottom: 1rem; border-bottom: 1px solid #333; padding-bottom: 0.5rem;">
    <strong>Migration Output:</strong>
  </div>
    <?php foreach ($output as $line) : ?>
    <div class="output-line output-<?= h($line['type']) ?>">
        <?= h($line['message']) ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<h2>Migration Status</h2>

<div class="migration-status">
  <?php if (empty($migrations)) : ?>
    <p>No migration files found.</p>
  <?php else : ?>
      <?php foreach ($migrations as $migration) : ?>
      <div class="migration-item <?= $migration['applied'] ? 'applied' : 'pending' ?>">
        <div>
          <div class="migration-filename"><?= h($migration['filename']) ?></div>
            <?php if ($migration['applied']) : ?>
            <div class="migration-date">
              Applied: <?= date('F j, Y g:i A', strtotime($migration['applied_at'])) ?>
            </div>
            <?php endif; ?>
        </div>
        <div>
            <?php if ($migration['applied']) : ?>
            <span class="badge badge-success">✓ Applied</span>
            <?php else : ?>
            <span class="badge badge-warning">⧗ Pending</span>
            <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
  <?php endif; ?>
</div>

<script>
  // Add confirmation dialog for running migrations
  document.getElementById('migration-form').addEventListener('submit', function(e) {
    const pendingCount = <?= count(array_filter($migrations, fn($m) => !$m['applied'])) ?>;

    if (pendingCount === 0) {
      e.preventDefault();
      if (window.QuackNotify) {
        QuackNotify.info('No pending migrations to apply.');
      } else {
        alert('No pending migrations to apply.');
      }
      return;
    }

    e.preventDefault();

    const message = `You are about to run ${pendingCount} pending migration(s). This will modify your database schema.\n\nDo you want to continue?`;

    QuackNotify.confirm(message, {
      title: 'Run Migrations',
      confirmText: 'Run Migrations',
      cancelText: 'Cancel',
      confirmStyle: 'danger'
    }).then(confirmed => {
      if (confirmed) {
        document.getElementById('run-migrations-btn').disabled = true;
        document.getElementById('run-migrations-btn').textContent = 'Running Migrations...';
        this.submit();
      }
    });
  });
</script>

<?php require __DIR__ . '/../footer.php'; ?>
