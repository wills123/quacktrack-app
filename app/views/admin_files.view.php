<?php
$title = 'File Management - Admin';
$diskUsage = $diskUsage ?? [];
$fileStats = $fileStats ?? [];
$files = $files ?? [];
$sort = $sort ?? 'name';
$order = $order ?? 'asc';
$totalPages = $totalPages ?? 1;
$page = $page ?? 1;
$main_class = 'container admin-page';
require __DIR__ . '/header.php';
?>

<style>
.file-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.file-stat-card {
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 0.375rem;
    padding: 1rem;
    text-align: center;
}

.file-stat-card h3 {
    margin: 0 0 0.5rem 0;
    font-size: 0.875rem;
    text-transform: uppercase;
    color: var(--text-secondary);
}

.file-stat-card .stat-value {
    font-size: 1.5rem;
    font-weight: bold;
    color: var(--text-primary);
}

.file-stat-card .stat-detail {
    font-size: 0.75rem;
    color: var(--text-secondary);
    margin-top: 0.25rem;
}

.disk-usage {
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 0.375rem;
    padding: 1rem;
    margin-bottom: 2rem;
}

.disk-usage-bar {
    width: 100%;
    height: 20px;
    background: var(--border);
    border-radius: 10px;
    overflow: hidden;
    margin: 0.5rem 0;
}

.disk-usage-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--color-success), var(--color-warning), var(--color-error));
    transition: width 0.3s ease;
}

.file-list {
    max-height: 600px;
    overflow-y: auto;
    border: 1px solid var(--border);
    border-radius: 0.375rem;
}

.file-item {
    display: flex;
    align-items: center;
    padding: 0.75rem;
    border-bottom: 1px solid var(--border);
    transition: background-color 0.2s;
}

.file-item:hover {
    background-color: var(--surface-2);
}

.file-item:last-child {
    border-bottom: none;
}

.file-icon {
    width: 40px;
    height: 40px;
    margin-right: 1rem;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--border);
    border-radius: 0.375rem;
    font-size: 1.2rem;
}

.file-info {
    flex-grow: 1;
}

.file-name {
    font-weight: 500;
    margin-bottom: 0.25rem;
}

.file-details {
    font-size: 0.75rem;
    color: var(--text-secondary);
}

.file-actions {
    display: flex;
    gap: 0.5rem;
}

.file-preview {
    width: 40px;
    height: 40px;
    object-fit: cover;
    border-radius: 0.375rem;
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

.cleanup-form {
    display: flex;
    gap: 1rem;
    align-items: end;
    margin-bottom: 1rem;
}

.form-group {
    margin-bottom: 0;
}

.warning-high { color: var(--badge-out-text); }
.warning-medium { color: var(--badge-low-text); }
.warning-low { color: var(--badge-in-text); }
</style>

<?php foreach (flashes() as [$t,$m]) : ?>
  <div class="flash <?=h($t)?>"><?=h($m)?></div>
<?php endforeach; ?>

<p><a href="/admin.php?tab=files" class="btn btn-secondary btn-sm">&larr; Back to Admin Dashboard</a></p>

<h1>File Management</h1>

<!-- Disk Usage -->
<div class="section">
    <h2>Disk Usage</h2>
    <?php if (isset($diskUsage['error'])) : ?>
        <div class="flash error">Error getting disk usage: <?= h($diskUsage['error']) ?></div>
    <?php else : ?>
        <div class="disk-usage">
            <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                <span>Used: <?= number_format($diskUsage['used'] / 1024 / 1024 / 1024, 2) ?> GB</span>
                <span>Free: <?= number_format($diskUsage['free'] / 1024 / 1024 / 1024, 2) ?> GB</span>
                <span>Total: <?= number_format($diskUsage['total'] / 1024 / 1024 / 1024, 2) ?> GB</span>
            </div>
            <div class="disk-usage-bar">
                <div class="disk-usage-fill" style="width: <?= number_format($diskUsage['usage_percent'], 1) ?>%"></div>
            </div>
            <div style="text-align: center; font-size: 0.875rem; color: var(--text-secondary);">
                <?= number_format($diskUsage['usage_percent'], 1) ?>% used
                <?php if ($diskUsage['usage_percent'] > 90) : ?>
                    <span class="warning-high">⚠️ Critical</span>
                <?php elseif ($diskUsage['usage_percent'] > 75) : ?>
                    <span class="warning-medium">⚠️ Warning</span>
                <?php else : ?>
                    <span class="warning-low">✓ Good</span>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- File Statistics -->
<div class="section">
    <h2>File Statistics</h2>
    <div class="file-stats">
        <div class="file-stat-card">
            <h3>Total Files</h3>
            <div class="stat-value"><?= number_format($fileStats['total_files']) ?></div>
        </div>
        <div class="file-stat-card">
            <h3>Total Size</h3>
            <div class="stat-value"><?= number_format($fileStats['total_size'] / 1024 / 1024, 1) ?> MB</div>
        </div>
        <div class="file-stat-card">
            <h3>Image Files</h3>
            <div class="stat-value"><?= number_format($fileStats['image_files']) ?></div>
            <div class="stat-detail"><?= $fileStats['total_files'] > 0 ? number_format(($fileStats['image_files'] / $fileStats['total_files']) * 100, 1) : 0 ?>%</div>
        </div>
        <div class="file-stat-card">
            <h3>Documents</h3>
            <div class="stat-value"><?= number_format($fileStats['document_files']) ?></div>
            <div class="stat-detail"><?= $fileStats['total_files'] > 0 ? number_format(($fileStats['document_files'] / $fileStats['total_files']) * 100, 1) : 0 ?>%</div>
        </div>
        <?php if ($fileStats['oldest_file']) : ?>
        <div class="file-stat-card">
            <h3>Oldest File</h3>
            <div class="stat-value" style="font-size: 0.875rem;"><?= date('Y-m-d', $fileStats['oldest_file']) ?></div>
            <div class="stat-detail"><?= number_format((time() - $fileStats['oldest_file']) / 86400) ?> days ago</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- File Management Actions -->
<div class="section">
    <h2>File Management Actions</h2>

    <div class="cleanup-form">
        <form method="post" style="display: flex; gap: 1rem; align-items: end;" class="cleanup-files-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cleanup_old_files">
            <div class="form-group">
                <label for="max_age">Delete files older than:</label>
                <select name="max_age" id="max_age">
                    <option value="7">7 days</option>
                    <option value="30" selected>30 days</option>
                    <option value="60">60 days</option>
                    <option value="90">90 days</option>
                    <option value="180">180 days</option>
                    <option value="365">1 year</option>
                </select>
            </div>
            <button type="submit" class="btn btn-warning">Cleanup Old Files</button>
        </form>
    </div>
</div>

<!-- File List -->
<div class="section">
    <h2>Uploaded Files (Showing <?= count($files) ?> of <?= $fileStats['total_files'] ?>)</h2>

    <!-- Sorting and Pagination Controls -->
    <?php if ($fileStats['total_files'] > 0) : ?>
    <div style="margin-bottom: 20px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
        <form method="get" action="/admin_files.php" style="display: flex; gap: 10px; align-items: center;">
            <label>
                Sort by:
                <select name="sort" onchange="this.form.submit()">
                    <option value="modified" <?= $sort === 'modified' ? 'selected' : '' ?>>Last Modified</option>
                    <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>File Name</option>
                    <option value="size" <?= $sort === 'size' ? 'selected' : '' ?>>File Size</option>
                </select>
            </label>
            <label>
                <select name="order" onchange="this.form.submit()">
                    <option value="desc" <?= $order === 'desc' ? 'selected' : '' ?>>Descending</option>
                    <option value="asc" <?= $order === 'asc' ? 'selected' : '' ?>>Ascending</option>
                </select>
            </label>
        </form>
    </div>
    <?php endif; ?>

    <?php if (empty($files)) : ?>
        <p>No files found in the upload directory.</p>
    <?php else : ?>
        <div class="file-list">
            <?php foreach ($files as $file) : ?>
                <div class="file-item">
                    <div class="file-icon">
                        <?php if (strpos($file['type'], 'image/') === 0) : ?>
                            <img src="<?= h($file['path']) ?>" alt="Preview" class="file-preview">
                        <?php elseif (strpos($file['type'], 'application/pdf') === 0) : ?>
                            📄
                        <?php elseif (strpos($file['type'], 'text/') === 0) : ?>
                            📝
                        <?php elseif (strpos($file['type'], 'application/') === 0) : ?>
                            📊
                        <?php else : ?>
                            📁
                        <?php endif; ?>
                    </div>

                    <div class="file-info">
                        <div class="file-name"><?= h($file['name']) ?></div>
                        <div class="file-details">
                            <?= number_format($file['size'] / 1024, 1) ?> KB •
                            <?= h($file['type']) ?> •
                            <?= date('Y-m-d H:i:s', $file['modified']) ?>
                            (<?= number_format((time() - $file['modified']) / 86400, 1) ?> days ago)
                        </div>
                    </div>

                    <div class="file-actions">
                        <?php if (strpos($file['type'], 'image/') === 0) : ?>
                            <a href="<?= h($file['path']) ?>" target="_blank" class="btn btn-sm btn-secondary">View</a>
                        <?php endif; ?>
                        <form method="post" style="display: inline;" class="delete-file-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_file">
                            <input type="hidden" name="filename" value="<?= h($file['name']) ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination Controls -->
        <?php if ($totalPages > 1) : ?>
        <div style="display: flex; gap: 10px; align-items: center; margin-top: 20px; flex-wrap: wrap;">
            <span>Page <?= $page ?> of <?= $totalPages ?></span>

            <?php if ($page > 1) : ?>
                <a href="<?= "/admin_files.php?" . http_build_query(['page' => 1, 'sort' => $sort, 'order' => $order]) ?>" class="btn btn-secondary btn-sm">First</a>
                <a href="<?= "/admin_files.php?" . http_build_query(['page' => $page - 1, 'sort' => $sort, 'order' => $order]) ?>" class="btn btn-secondary btn-sm">Previous</a>
            <?php endif; ?>

            <!-- Page number selector -->
            <form method="get" action="/admin_files.php" style="display: flex; gap: 5px; align-items: center;">
                <input type="hidden" name="sort" value="<?= h($sort) ?>">
                <input type="hidden" name="order" value="<?= h($order) ?>">
                <label style="display: flex; gap: 5px; align-items: center;">
                    Go to page:
                    <input type="number" name="page" min="1" max="<?= $totalPages ?>" value="<?= $page ?>" style="width: 60px;">
                </label>
                <button type="submit" class="btn btn-secondary btn-sm">Go</button>
            </form>

            <?php if ($page < $totalPages) : ?>
                <a href="<?= "/admin_files.php?" . http_build_query(['page' => $page + 1, 'sort' => $sort, 'order' => $order]) ?>" class="btn btn-secondary btn-sm">Next</a>
                <a href="<?= "/admin_files.php?" . http_build_query(['page' => $totalPages, 'sort' => $sort, 'order' => $order]) ?>" class="btn btn-secondary btn-sm">Last</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- File Upload Test -->
<div class="section">
    <h2>File Upload Test</h2>
    <form method="post" enctype="multipart/form-data" action="/admin_files.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="test_upload">
        <div class="form-group">
            <label for="test_file">Test File Upload:</label>
            <input type="file" id="test_file" name="image" accept="image/*">
            <div class="form-help">Upload a test image to verify file handling is working correctly</div>
        </div>
        <button type="submit" class="btn btn-primary">Test Upload</button>
    </form>
</div>

<script>
// Auto-refresh page every 60 seconds
setTimeout(() => {
    window.location.reload();
}, 60000);

// Add refresh button and confirmation handlers
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

    // Handle cleanup files confirmation
    const cleanupForm = document.querySelector('.cleanup-files-form');
    if (cleanupForm) {
        cleanupForm.addEventListener('submit', function(e) {
            e.preventDefault();

            QuackNotify.confirm('Are you sure you want to delete old files?', {
                title: 'Delete Old Files',
                confirmText: 'Delete',
                cancelText: 'Cancel',
                confirmStyle: 'danger'
            }).then(confirmed => {
                if (confirmed) {
                    this.submit();
                }
            });
        });
    }

    // Handle individual file delete confirmations
    document.querySelectorAll('.delete-file-form').forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            QuackNotify.confirm('Are you sure you want to delete this file?', {
                title: 'Delete File',
                confirmText: 'Delete',
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

// File info modal (if needed)
function showFileInfo(filename) {
    fetch('/admin_files.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_file_info&filename=' + encodeURIComponent(filename) + '&csrf=' + encodeURIComponent('<?= csrf_token() ?>')
    })
    .then(response => response.json())
    .then(data => {
        if (window.QuackNotify) {
            QuackNotify.info('File Info:\n' + JSON.stringify(data, null, 2));
        } else {
            alert('File Info:\n' + JSON.stringify(data, null, 2));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        if (window.QuackNotify) {
            QuackNotify.error('Failed to get file information');
        } else {
            alert('Failed to get file information');
        }
    });
}
</script>

<?php require __DIR__ . '/footer.php'; ?>
