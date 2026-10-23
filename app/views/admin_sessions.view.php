<?php
$title = 'Session Management - Admin';
$sessionStats = $sessionStats ?? [];
$main_class = 'container admin-page';
require __DIR__ . '/header.php';
?>

<style>
.session-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.session-stat-card {
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 0.375rem;
    padding: 1rem;
    text-align: center;
}

.session-stat-card h3 {
    margin: 0 0 0.5rem 0;
    font-size: 0.875rem;
    text-transform: uppercase;
    color: var(--text-secondary);
}

.session-stat-card .stat-value {
    font-size: 1.5rem;
    font-weight: bold;
    color: var(--text-primary);
}

.session-event {
    background: var(--surface-2);
    border-left: 4px solid var(--color-success);
    padding: 0.75rem;
    margin-bottom: 0.5rem;
    border-radius: 0 0.375rem 0.375rem 0;
    font-family: monospace;
    font-size: 0.875rem;
}

.session-event.logout {
    border-left-color: var(--text-muted);
}

.session-event.timeout {
    border-left-color: var(--color-warning);
}

.session-event.hijacking {
    border-left-color: var(--color-error);
}

.session-event-header {
    font-weight: bold;
    margin-bottom: 0.25rem;
}

.session-event-details {
    color: var(--text-secondary);
    font-size: 0.75rem;
}

.user-list {
    max-height: 400px;
    overflow-y: auto;
    border: 1px solid var(--border);
    border-radius: 0.375rem;
}

.user-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem;
    border-bottom: 1px solid var(--border);
}

.user-item:last-child {
    border-bottom: none;
}

.user-item .user-info {
    flex-grow: 1;
    color: var(--text-primary);
}

.user-actions {
    display: flex;
    gap: 0.5rem;
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

.btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

.current-session {
    background-color: var(--badge-info-bg);
    border-left-color: var(--color-info);
}
</style>

<?php foreach (flashes() as [$t,$m]) : ?>
  <div class="flash <?=h($t)?>"><?=h($m)?></div>
<?php endforeach; ?>

<p><a href="/admin.php?tab=sessions" class="btn btn-secondary btn-sm">&larr; Back to Admin Dashboard</a></p>

<h1>Session Management</h1>

<!-- Session Statistics -->
<div class="section">
    <h2>Session Statistics</h2>
    <div class="session-stats">
        <div class="session-stat-card">
            <h3>Active Sessions</h3>
            <div class="stat-value"><?= number_format($sessionStats['active_sessions']) ?></div>
        </div>
        <div class="session-stat-card">
            <h3>Session Lifetime</h3>
            <div class="stat-value"><?= number_format($sessionStats['session_lifetime'] / 60) ?> min</div>
        </div>
        <div class="session-stat-card">
            <h3>Session Name</h3>
            <div class="stat-value" style="font-size: 1rem;"><?= h($sessionStats['session_name']) ?></div>
        </div>
        <div class="session-stat-card">
            <h3>Current Session</h3>
            <div class="stat-value" style="font-size: 0.75rem;"><?= h(substr($sessionStats['current_session_id'], 0, 8)) ?>...</div>
        </div>
    </div>

    <!-- Session Management Actions -->
    <div style="margin-top: 1rem;">
        <form method="post" style="display: inline;" class="cleanup-sessions-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cleanup_sessions">
            <button type="submit" class="btn btn-warning">Cleanup Expired Sessions</button>
        </form>
    </div>
</div>

<!-- Recent Session Events -->
<div class="section">
    <h2>Recent Session Events (Last 20)</h2>
    <?php if (empty($recentSecurity)) : ?>
        <p>No recent session events found.</p>
    <?php else : ?>
        <?php foreach ($recentSecurity as $event) : ?>
            <?php
            $eventClass = '';
            $eventIcon = '';
            switch ($event->event ?? '') {
                case 'successful_login':
                    $eventClass = 'login';
                    $eventIcon = '🔓';
                    break;
                case 'user_logout':
                    $eventClass = 'logout';
                    $eventIcon = '🔒';
                    break;
                case 'session_timeout':
                    $eventClass = 'timeout';
                    $eventIcon = '⏰';
                    break;
                case 'session_hijacking_attempt':
                    $eventClass = 'hijacking';
                    $eventIcon = '🚨';
                    break;
                default:
                    $eventIcon = 'ℹ️';
            }
            ?>
            <div class="session-event <?= $eventClass ?>">
                <div class="session-event-header">
                    <?= $eventIcon ?> [<?= h($event->timestamp ?? 'Unknown time') ?>] <?= h(ucfirst(str_replace('_', ' ', $event->event ?? 'Unknown event'))) ?>
                </div>
                <div class="session-event-details">
                    <?php if (isset($event->user_id)) : ?>
                        User ID: <?= h($event->user_id) ?> |
                    <?php endif; ?>
                    IP: <?= h($event->ip ?? 'Unknown') ?>
                    <?php if (isset($event->session_id)) : ?>
                        | Session: <?= h(substr($event->session_id, 0, 8)) ?>...
                    <?php endif; ?>
                    <?php if (isset($event->details) && is_object($event->details)) : ?>
                        <br>Details: <?= h(json_encode($event->details)) ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- User Management -->
<div class="section">
    <h2>User Management</h2>
    <p>Registered users in the system:</p>

    <?php if (empty($activeUsers)) : ?>
        <p>No users found.</p>
    <?php else : ?>
        <div class="user-list">
            <?php foreach ($activeUsers as $user) : ?>
                <div class="user-item <?= $user['id'] == $_SESSION['uid'] ? 'current-session' : '' ?>">
                    <div class="user-info">
                        <strong><?= h($user['display_name'] ?? $user['email'] ?? '') ?></strong>
                        <?php if ($user['region_name']) : ?>
                            <br><small>Region: <?= h($user['region_name']) ?></small>
                        <?php endif; ?>
                        <?php if ($user['id'] == $_SESSION['uid']) : ?>
                            <br><small><em>Current Session</em></small>
                        <?php endif; ?>
                    </div>
                    <div class="user-actions">
                        <?php if ($user['id'] != $_SESSION['uid']) : ?>
                            <form method="post" style="display: inline;" class="force-logout-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="force_logout_user">
                                <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Force Logout</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Session Configuration -->
<div class="section">
    <h2>Session Configuration</h2>
    <div class="system-info">
        <div class="system-info-item">
            <span>Session Save Path:</span>
            <span><?= h($sessionStats['session_save_path']) ?></span>
        </div>
        <div class="system-info-item">
            <span>Session Cookie Lifetime:</span>
            <span><?= ini_get('session.cookie_lifetime') ?> seconds</span>
        </div>
        <div class="system-info-item">
            <span>Session GC Probability:</span>
            <span><?= ini_get('session.gc_probability') ?>/<?= ini_get('session.gc_divisor') ?></span>
        </div>
        <div class="system-info-item">
            <span>Session Use Cookies:</span>
            <span><?= ini_get('session.use_cookies') ? 'Yes' : 'No' ?></span>
        </div>
        <div class="system-info-item">
            <span>Session HTTP Only:</span>
            <span><?= ini_get('session.cookie_httponly') ? 'Yes' : 'No' ?></span>
        </div>
        <div class="system-info-item">
            <span>Session Secure:</span>
            <span><?= ini_get('session.cookie_secure') ? 'Yes' : 'No' ?></span>
        </div>
    </div>
</div>

<script>
// Auto-refresh page every 60 seconds
setTimeout(() => {
    window.location.reload();
}, 60000);

// Add refresh button
document.addEventListener('DOMContentLoaded', function() {
    const refreshBtn = document.createElement('button');
    refreshBtn.textContent = 'Refresh';
    refreshBtn.className = 'btn btn-secondary';
    refreshBtn.style.position = 'fixed';
    refreshBtn.style.bottom = '20px';
    refreshBtn.style.right = '20px';
    refreshBtn.style.zIndex = '1000';
    refreshBtn.onclick = () => window.location.reload();
    document.body.appendChild(refreshBtn);

    // Handle session cleanup confirmation
    const cleanupForm = document.querySelector('.cleanup-sessions-form');
    if (cleanupForm) {
        cleanupForm.addEventListener('submit', function(e) {
            e.preventDefault();

            QuackNotify.confirm('Are you sure you want to cleanup expired sessions?', {
                title: 'Cleanup Sessions',
                confirmText: 'Cleanup',
                cancelText: 'Cancel',
                confirmStyle: 'warning'
            }).then(confirmed => {
                if (confirmed) {
                    this.submit();
                }
            });
        });
    }

    // Handle force logout confirmations
    document.querySelectorAll('.force-logout-form').forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            QuackNotify.confirm('Are you sure you want to force logout this user?', {
                title: 'Force Logout User',
                confirmText: 'Force Logout',
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
