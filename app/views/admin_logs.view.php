<?php
$title = 'System Logs - Admin';
$logStats = $logStats ?? [];
$systemInfo = $systemInfo ?? [];
$main_class = 'container admin-page';
require __DIR__ . '/header.php';
?>

<style>
.log-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.log-stat-card {
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 0.375rem;
    padding: 1rem;
    text-align: center;
}

.log-stat-card h3 {
    margin: 0 0 0.5rem 0;
    font-size: 0.875rem;
    text-transform: uppercase;
    color: var(--text-secondary);
}

.log-stat-card .stat-value {
    font-size: 1.5rem;
    font-weight: bold;
    color: var(--text-primary);
}

.log-stat-card .stat-size {
    font-size: 0.75rem;
    color: var(--text-secondary);
    margin-top: 0.25rem;
}

.log-entry {
    background: var(--surface-2);
    border-left: 4px solid var(--color-error);
    padding: 0.75rem;
    margin-bottom: 0.5rem;
    border-radius: 0 0.375rem 0.375rem 0;
    font-family: monospace;
    font-size: 0.875rem;
}

.log-entry.security {
    border-left-color: var(--color-warning);
}

.log-entry-header {
    font-weight: bold;
    margin-bottom: 0.25rem;
}

.log-entry-details {
    color: var(--text-secondary);
    font-size: 0.75rem;
}

.system-info {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 0.5rem;
    background: var(--surface-2);
    padding: 1rem;
    border-radius: 0.375rem;
    font-family: monospace;
    font-size: 0.875rem;
}

.system-info-item {
    display: flex;
    justify-content: space-between;
    padding: 0.25rem 0;
    border-bottom: 1px solid var(--border);
}

.btn-group {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

.section {
    margin-bottom: 2rem;
    padding: 1rem;
    border: 1px solid var(--border);
    border-radius: 0.375rem;
}

.section h2 {
    margin-top: 0;
    margin-bottom: 1rem;
    font-size: 1.25rem;
    color: var(--text-primary);
}
</style>

<?php foreach (flashes() as [$t,$m]) : ?>
  <div class="flash <?=h($t)?>"><?=h($m)?></div>
<?php endforeach; ?>

<p><a href="/admin.php?tab=logs" class="btn btn-secondary btn-sm">&larr; Back to Admin Dashboard</a></p>

<h1>System Logs & Monitoring</h1>

<!-- Log Statistics -->
<div class="section">
    <h2>Log Statistics (Today)</h2>
    <div class="log-stats">
        <?php foreach ($logStats as $type => $stats) : ?>
            <div class="log-stat-card">
                <h3><?= ucfirst($type) ?> Logs</h3>
                <div class="stat-value"><?= number_format($stats['lines']) ?></div>
                <div class="stat-size">
                    <?= $stats['size'] > 0 ? number_format($stats['size'] / 1024, 1) . ' KB' : '0 KB' ?>
                </div>
                <?php if ($stats['last_modified']) : ?>
                    <div class="stat-size">
                        <?php
                            // Convert to California timezone
                            $dt = new DateTime();
                            $dt->setTimestamp($stats['last_modified']);
                            $dt->setTimezone(new DateTimeZone('America/Los_Angeles'));
                        ?>
                        Last: <?= $dt->format('H:i:s T') ?>
                    </div>
                <?php endif; ?>

                <!-- Log Actions -->
                <div class="btn-group" style="margin-top: 0.5rem;">
                    <?php if ($stats['size'] > 0) : ?>
                        <form method="post" style="display: inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="download_logs">
                            <input type="hidden" name="log_type" value="<?= h($type) ?>">
                            <button type="submit" class="btn btn-sm btn-secondary">Download</button>
                        </form>
                        <form method="post" style="display: inline;" class="clear-logs-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="clear_logs">
                            <input type="hidden" name="log_type" value="<?= h($type) ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Clear</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Recent Errors -->
<div class="section">
    <h2>Recent Errors (Last 10)</h2>
    <?php if (empty($recentErrors)) : ?>
        <p>No recent errors found.</p>
    <?php else : ?>
        <?php foreach ($recentErrors as $error) : ?>
            <div class="log-entry">
                <div class="log-entry-header">
                    [<?= h(format_log_timestamp($error->timestamp ?? 'Unknown time')) ?>] <?= h($error->context ?? 'Unknown context') ?>
                </div>
                <div><?= h($error->message ?? 'No message') ?></div>
                <?php if (isset($error->file) && isset($error->line)) : ?>
                    <div class="log-entry-details">
                        File: <?= h($error->file) ?>:<?= h($error->line) ?>
                        <?php if (isset($error->user_id)) : ?>
                            | User: <?= h($error->user_id) ?>
                        <?php endif; ?>
                        <?php if (isset($error->ip)) : ?>
                            | IP: <?= h($error->ip) ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Recent Security Events -->
<div class="section">
    <h2>Recent Security Events (Last 10)</h2>
    <?php if (empty($recentSecurity)) : ?>
        <p>No recent security events found.</p>
    <?php else : ?>
        <?php foreach ($recentSecurity as $event) : ?>
            <div class="log-entry security">
                <div class="log-entry-header">
                    [<?= h(format_log_timestamp($event->timestamp ?? 'Unknown time')) ?>] <?= h($event->event ?? 'Unknown event') ?>
                </div>
                <?php if (isset($event->details) && is_object($event->details)) : ?>
                    <div><?= h(json_encode($event->details, JSON_PRETTY_PRINT)) ?></div>
                <?php endif; ?>
                <div class="log-entry-details">
                    <?php if (isset($event->user_id)) : ?>
                        User: <?= h($event->user_id) ?> |
                    <?php endif; ?>
                    IP: <?= h($event->ip ?? 'Unknown') ?>
                    <?php if (isset($event->user_agent)) : ?>
                        | UA: <?= h(substr($event->user_agent, 0, 50)) ?>...
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- System Information -->
<div class="section">
    <h2>System Information</h2>
    <div class="system-info">
        <?php foreach ($systemInfo as $key => $value) : ?>
            <div class="system-info-item">
                <span><?= h(str_replace('_', ' ', ucfirst($key))) ?>:</span>
                <span>
                    <?php if (in_array($key, ['current_memory_usage', 'peak_memory_usage', 'disk_free_space', 'disk_total_space'])) : ?>
                        <?= number_format($value / 1024 / 1024, 1) ?> MB
                    <?php else : ?>
                        <?= h($value) ?>
                    <?php endif; ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Disk Usage Warning -->
<?php
$diskUsagePercent = (($systemInfo['disk_total_space'] - $systemInfo['disk_free_space']) / $systemInfo['disk_total_space']) * 100;
if ($diskUsagePercent > 80) :
    ?>
    <div class="flash warning">
        <strong>Warning:</strong> Disk usage is at <?= number_format($diskUsagePercent, 1) ?>%.
        Consider cleaning up old files or expanding storage.
    </div>
<?php endif; ?>

<!-- Memory Usage Warning -->
<?php
$memoryUsagePercent = ($systemInfo['current_memory_usage'] / (int)str_replace('M', '', $systemInfo['memory_limit']) / 1024 / 1024) * 100;
if ($memoryUsagePercent > 80) :
    ?>
    <div class="flash warning">
        <strong>Warning:</strong> Memory usage is at <?= number_format($memoryUsagePercent, 1) ?>%.
        Consider optimizing memory usage or increasing memory limit.
    </div>
<?php endif; ?>

<script>
// Auto-refresh page every 30 seconds
setTimeout(() => {
    window.location.reload();
}, 30000);

// Add refresh button
document.addEventListener('DOMContentLoaded', function() {
    const refreshBtn = document.createElement('button');
    refreshBtn.textContent = 'Refresh Now';
    refreshBtn.className = 'btn btn-primary';
    refreshBtn.style.position = 'fixed';
    refreshBtn.style.bottom = '20px';
    refreshBtn.style.right = '20px';
    refreshBtn.style.zIndex = '1000';
    refreshBtn.onclick = () => window.location.reload();
    document.body.appendChild(refreshBtn);

    // Handle clear logs confirmations
    document.querySelectorAll('.clear-logs-form').forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            QuackNotify.confirm('Are you sure you want to clear these logs?', {
                title: 'Clear Logs',
                confirmText: 'Clear',
                cancelText: 'Cancel',
                confirmStyle: 'danger'
            }).then(confirmed => {
                if (confirmed) {
                    this.submit();
                }
            });
        });
    });
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
