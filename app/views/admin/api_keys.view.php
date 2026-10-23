<?php
$title = 'API Key Management';
$newApiKey = $newApiKey ?? null;
$newApiKeyName = $newApiKeyName ?? '';
$main_class = 'container admin-page';
require __DIR__ . '/../header.php';
?>

<?php foreach (flashes() as [$t,$m]) : ?>
  <div class="flash <?=h($t)?>"><?=h($m)?></div>
<?php endforeach; ?>

<div class="admin-header">
  <h1>API Key Management</h1>
</div>
<p class="muted" style="margin-bottom: 24px;">Generate and manage API keys for external integrations like Power BI.</p>

<?php if ($newApiKey) : ?>
<div class="apikey-created-banner">
  <div class="apikey-created-header">
    <span class="apikey-created-icon">&#x1f511;</span>
    <strong>New API Key Created: <?= h($newApiKeyName) ?></strong>
  </div>
  <p class="apikey-created-warning">Copy this key now. It will <strong>never be shown again</strong>.</p>
  <div class="apikey-created-value">
    <code id="newKeyDisplay"><?= h($newApiKey) ?></code>
    <button type="button" class="btn btn-primary btn-sm" onclick="copyApiKey()" id="copyKeyBtn">Copy</button>
  </div>
</div>
<?php endif; ?>

<div class="apikey-grid">
  <div class="form-section">
    <h3>Generate New Key</h3>
    <form method="post" action="/admin/api_keys">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="generate_key">
      <div class="form-group">
        <label for="key_name">Key Name <span class="required">*</span></label>
        <input type="text" id="key_name" name="name" required placeholder="e.g. Power BI Production">
      </div>
      <div class="form-group">
        <label for="key_desc">Description</label>
        <input type="text" id="key_desc" name="description" placeholder="e.g. Used by BI team for survey dashboards">
      </div>
      <div class="form-group">
        <label for="key_scopes">Scopes</label>
        <select id="key_scopes" name="scopes">
          <option value="powerbi:read">Power BI Read-Only (powerbi:read)</option>
          <option value="quickqueue:orders">QuickQueue Orders (quickqueue:orders)</option>
          <option value="quackmap:read">Quack-Map Read-Only (quackmap:read)</option>
        </select>
      </div>
      <div class="form-group">
        <label for="key_expires">Expires In</label>
        <select id="key_expires" name="expires_in_days">
          <option value="0">Never</option>
          <option value="30">30 days</option>
          <option value="90" selected>90 days</option>
          <option value="180">180 days</option>
          <option value="365">1 year</option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary" style="margin-top: 8px;">Generate Key</button>
    </form>
  </div>

  <div class="form-section">
    <h3>Power BI Setup Guide</h3>
    <div class="apikey-guide">
      <div class="apikey-guide-step">
        <span class="apikey-step-num">1</span>
        <span>Generate an API key using the form.</span>
      </div>
      <div class="apikey-guide-step">
        <span class="apikey-step-num">2</span>
        <span>In Power BI Desktop, choose <em>Get Data &rarr; Web</em>.</span>
      </div>
      <div class="apikey-guide-step">
        <span class="apikey-step-num">3</span>
        <div>
          Select <em>Advanced</em> and enter:
          <ul class="apikey-guide-list">
            <li>URL: <code>/api/powerbi?table=meta</code></li>
            <li>HTTP Header: <code>X-Api-Key</code> = your key</li>
          </ul>
        </div>
      </div>
      <div class="apikey-guide-step">
        <span class="apikey-step-num">4</span>
        <div>
          <span>Load each table: <code>submissions</code>, <code>answers</code>, <code>stores</code>, <code>regions</code>, <code>versions</code>, <code>categories</code>, <code>questions</code></span>
          <div class="muted" style="margin-top: 6px; font-size: 12px;">
            <strong>Privacy:</strong> <code>submissions</code> includes submitter name and <code>answers</code> includes free-text notes by default. Treat the API key as sensitive.
          </div>
        </div>
      </div>
      <div class="apikey-guide-step">
        <span class="apikey-step-num">5</span>
        <span>Create relationships in Power BI Model view to link tables by their ID columns.</span>
      </div>

      <div class="apikey-tables-section">
        <strong>Available Tables</strong>
        <div class="apikey-tables-grid">
          <div class="apikey-table-item">
            <code>submissions</code>
            <span>Survey submissions with scores</span>
          </div>
          <div class="apikey-table-item">
            <code>answers</code>
            <span>Individual question answers</span>
          </div>
          <div class="apikey-table-item">
            <code>stores</code>
            <span>Store dimension table</span>
          </div>
          <div class="apikey-table-item">
            <code>regions</code>
            <span>Region dimension table</span>
          </div>
          <div class="apikey-table-item">
            <code>versions</code>
            <span>Survey template versions</span>
          </div>
          <div class="apikey-table-item">
            <code>categories</code>
            <span>Question categories</span>
          </div>
          <div class="apikey-table-item">
            <code>questions</code>
            <span>Template questions</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="form-section" style="margin-top: 8px;">
  <h3>Existing API Keys</h3>
  <?php if (empty($apiKeys)) : ?>
    <div class="empty-state">
      <p>No API keys have been created yet.</p>
    </div>
  <?php else : ?>
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Key Prefix</th>
            <th>Scopes</th>
            <th>Status</th>
            <th>Last Used</th>
            <th>Requests</th>
            <th>Expires</th>
            <th>Created By</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($apiKeys as $key) : ?>
                <?php
                $isExpired = $key['expires_at'] !== null && strtotime($key['expires_at']) < time();
                $isActive  = (int)$key['is_active'] && !$isExpired;
                ?>
            <tr class="<?= !$isActive ? 'apikey-inactive-row' : '' ?>">
              <td data-label="Name">
                <strong><?= h($key['name']) ?></strong>
                <?php if ($key['description']) : ?>
                  <br><small class="text-muted"><?= h($key['description']) ?></small>
                <?php endif; ?>
              </td>
              <td data-label="Prefix"><code><?= h($key['key_prefix']) ?>...</code></td>
              <td data-label="Scopes"><code class="apikey-scope"><?= h($key['scopes']) ?></code></td>
              <td data-label="Status">
                <?php if ($isExpired) : ?>
                  <span class="apikey-status apikey-status--expired">Expired</span>
                <?php elseif ((int)$key['is_active']) : ?>
                  <span class="apikey-status apikey-status--active">Active</span>
                <?php else : ?>
                  <span class="apikey-status apikey-status--revoked">Revoked</span>
                <?php endif; ?>
              </td>
              <td data-label="Last Used">
                <?php if ($key['last_used_at']) : ?>
                    <?= h($key['last_used_at']) ?>
                    <?php if ($key['last_used_ip']) : ?>
                    <br><small class="text-muted">from <?= h($key['last_used_ip']) ?></small>
                    <?php endif; ?>
                <?php else : ?>
                  <span class="text-muted">Never</span>
                <?php endif; ?>
              </td>
              <td data-label="Requests"><?= number_format((int)$key['request_count']) ?></td>
              <td data-label="Expires">
                <?php if ($key['expires_at']) : ?>
                    <?= h($key['expires_at']) ?>
                <?php else : ?>
                  <span class="text-muted">Never</span>
                <?php endif; ?>
              </td>
              <td data-label="Created By"><?= h($key['created_by_name'] ?? 'Unknown') ?></td>
              <td data-label="Actions">
                <div class="actions">
                  <?php if ((int)$key['is_active']) : ?>
                    <form method="post" action="/admin/api_keys"
                      data-confirm="Revoke this API key? Any integration using it will stop working."
                      data-confirm-title="Revoke API Key"
                      data-confirm-confirm-text="Revoke"
                      data-confirm-style="warning">
                        <?=csrf_field()?>
                      <input type="hidden" name="action" value="revoke_key">
                      <input type="hidden" name="key_id" value="<?= (int)$key['id'] ?>">
                      <button type="submit" class="btn btn-warning btn-sm">Revoke</button>
                    </form>
                  <?php else : ?>
                    <form method="post" action="/admin/api_keys">
                      <?=csrf_field()?>
                      <input type="hidden" name="action" value="activate_key">
                      <input type="hidden" name="key_id" value="<?= (int)$key['id'] ?>">
                      <button type="submit" class="btn btn-secondary btn-sm">Reactivate</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" action="/admin/api_keys"
                    data-confirm="Permanently delete this API key? This cannot be undone."
                    data-confirm-title="Delete API Key"
                    data-confirm-confirm-text="Delete"
                    data-confirm-style="danger">
                    <?=csrf_field()?>
                    <input type="hidden" name="action" value="delete_key">
                    <input type="hidden" name="key_id" value="<?= (int)$key['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<script>
function copyApiKey() {
  var el = document.getElementById('newKeyDisplay');
  var btn = document.getElementById('copyKeyBtn');
  if (!el) return;

  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(el.textContent.trim()).then(function() {
      btn.textContent = 'Copied!';
      setTimeout(function() { btn.textContent = 'Copy'; }, 2000);
    });
  } else {
    var range = document.createRange();
    range.selectNodeContents(el);
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
    document.execCommand('copy');
    btn.textContent = 'Copied!';
    setTimeout(function() { btn.textContent = 'Copy'; }, 2000);
  }
}
</script>

<?php require __DIR__ . '/../footer.php'; ?>
