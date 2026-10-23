<?php
$title        = 'Dashboard';
$main_class   = 'main-content';
$cart_count = $cart_count ?? 0;
$low_stock_threshold = $low_stock_threshold ?? 0;
$extra_styles = ['/assets/opdash.css'];

// Conditionally load profile completion CSS
if ($needs_profile_completion ?? false) {
    $extra_styles[] = '/assets/profile-completion.css';
}

require __DIR__ . '/header.php';

// Apply theme before first paint — no flash
?>
<script>
(function(){
  var t = localStorage.getItem('kit_theme');
  if (t === 'dark' || (t === null && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.documentElement.setAttribute('data-kit-theme', 'dark');
  }
  var m = document.querySelector('.app-main');
  if (m) m.classList.add('od-active');
}());
</script>
<?php

// Relative time helper
function dashRelativeTime(string $timestamp): string
{
    $diff = time() - strtotime($timestamp);
    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . 'm ago';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . 'h ago';
    }
    if ($diff < 604800) {
        return floor($diff / 86400) . 'd ago';
    }
    return date('M j', strtotime($timestamp));
}

$userName  = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
if ($userName === '') {
    $userName = $_SESSION['display_name'] ?? 'there';
}
?>

<div class="dash-page" id="dashPage">

  <?php $__flashes = flashes(); if (!empty($__flashes)) : ?>
  <div class="dash-flashes">
        <?php foreach ($__flashes as [$type, $msg]) : ?>
      <div class="flash <?= h($type) ?>"><?= h($msg) ?></div>
        <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Page header -->
  <div class="page-masthead dash-header">
    <div class="dash-header-inner">
      <div>
        <h1 class="dash-title">Hello, <?= h($userName) ?></h1>
        <p class="dash-subtitle">Your van stock snapshot &mdash; <?= date('l, F j') ?></p>
      </div>
      <div class="masthead-tabs dash-header-actions">
        <a href="/stock" class="masthead-tab">Van Stock</a>
        <a href="/cart" class="masthead-tab">Cart<?= $cart_count > 0 ? ' (' . $cart_count . ')' : '' ?></a>
      </div>
    </div>
  </div>

  <!-- Stat strip -->
  <div class="page-stat-rail dash-stats">
    <div class="page-stat dash-stat">
      <div class="page-stat-value dash-stat-value accent"><?= (int)($stats['total_items'] ?? 0) ?></div>
      <div class="page-stat-label dash-stat-label">Products on van</div>
    </div>
    <div class="page-stat dash-stat">
      <div class="page-stat-value dash-stat-value"><?= number_format((int)($stats['total_qty'] ?? 0)) ?></div>
      <div class="page-stat-label dash-stat-label">Total units</div>
    </div>
    <div class="page-stat dash-stat">
      <div class="page-stat-value dash-stat-value<?= (int)($stats['low_count'] ?? 0) > 0 ? ' warn' : '' ?>"><?= (int)($stats['low_count'] ?? 0) ?></div>
      <div class="page-stat-label dash-stat-label">Low stock</div>
    </div>
    <div class="page-stat dash-stat">
      <div class="page-stat-value dash-stat-value<?= (int)($stats['out_count'] ?? 0) > 0 ? ' danger' : '' ?>"><?= (int)($stats['out_count'] ?? 0) ?></div>
      <div class="page-stat-label dash-stat-label">Out of stock</div>
    </div>
  </div>

  <!-- Two-column body -->
  <div class="dash-body">

    <!-- Left: main column -->
    <div class="dash-main">

      <!-- Van Stock Summary -->
      <div class="dash-card">
        <div class="dash-card-header">
          <span class="dash-card-title">Van Stock Summary</span>
          <a href="/stock" class="dash-card-link">Manage →</a>
        </div>
        <div class="dash-card-body">
          <?php if (empty($van_stock_summary)) : ?>
            <div class="dash-empty">
              <div class="dash-empty-icon">📦</div>
              <div class="dash-empty-text">Your van stock is empty.</div>
              <a href="/stock" class="od-btn od-btn--primary">Add Items</a>
            </div>
          <?php else : ?>
            <table class="dash-table">
              <thead>
                <tr>
                  <th>Product</th>
                  <th>Location</th>
                  <th style="text-align:right;">Qty</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($van_stock_summary as $item) :
                    $qty   = (int)$item['total_qty'];
                    $qClass = $qty === 0 ? 'out' : ($qty <= $low_stock_threshold ? 'low' : '');
                    ?>
                <tr>
                  <td>
                    <div class="td-name"><?= h($item['name']) ?></div>
                    <?php if (!empty($item['sku'])) : ?>
                      <div class="td-sku"><?= h($item['sku']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="td-stores"><?= h($item['stores'] ?? '—') ?></td>
                  <td style="text-align:right;">
                    <span class="dash-qty-badge <?= $qClass ?>"><?= $qty ?></span>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

      <!-- Recent Activity -->
      <div class="dash-card">
        <div class="dash-card-header">
          <span class="dash-card-title">Recent Activity</span>
          <a href="/reports" class="dash-card-link">View all →</a>
        </div>
        <div class="dash-card-body">
          <?php if (empty($recent_transactions)) : ?>
            <div class="dash-empty">
              <div class="dash-empty-icon">📋</div>
              <div class="dash-empty-text">No recent activity yet.</div>
            </div>
          <?php else : ?>
            <div class="dash-activity">
              <?php foreach ($recent_transactions as $tx) :
                    $isPos = (int)$tx['change_qty'] > 0;
                    ?>
              <div class="dash-activity-row">
                <span class="dash-activity-delta <?= $isPos ? 'pos' : 'neg' ?>">
                    <?= $isPos ? '+' : '' ?><?= (int)$tx['change_qty'] ?>
                </span>
                <div class="dash-activity-info">
                  <div class="dash-activity-name"><?= h($tx['name']) ?></div>
                    <?php if (!empty($tx['reason'])) : ?>
                    <div class="dash-activity-meta"><?= h($tx['reason']) ?></div>
                    <?php endif; ?>
                </div>
                <span class="dash-activity-time"><?= dashRelativeTime((string)$tx['created_at']) ?></span>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- /dash-main -->

    <!-- Right: rail -->
    <div class="dash-rail">

      <!-- Quick Actions -->
      <div class="dash-card">
        <div class="dash-card-header">
          <span class="dash-card-title">Quick Actions</span>
        </div>
        <div class="dash-card-body">
          <div class="dash-quick-grid">
            <a href="/stock" class="dash-quick-btn">
              <span class="dash-quick-icon">🚚</span>
              <span>Van Stock</span>
            </a>
            <a href="/products" class="dash-quick-btn">
              <span class="dash-quick-icon">📦</span>
              <span>Products</span>
            </a>
            <a href="/orders" class="dash-quick-btn">
              <span class="dash-quick-icon">🛒</span>
              <span>Orders</span>
            </a>
            <a href="/region-storage" class="dash-quick-btn">
              <span class="dash-quick-icon">🏭</span>
              <span>Storage</span>
            </a>
          </div>
        </div>
      </div>

      <!-- Alerts card — only shown when there are low/out items -->
      <?php if (!empty($alert_items)) : ?>
      <div class="dash-card">
        <div class="dash-card-header">
          <span class="dash-card-title">Stock Alerts</span>
          <a href="/stock" class="dash-card-link">Fix →</a>
        </div>
        <div class="dash-card-body">
          <div class="dash-alerts">
            <?php foreach ($alert_items as $item) :
                $qty    = (int)$item['total_qty'];
                $qClass = $qty === 0 ? 'out' : 'low';
                ?>
            <div class="dash-alert-row">
              <span class="dash-alert-name"><?= h($item['name']) ?></span>
              <span class="dash-qty-badge <?= $qClass ?>"><?= $qty ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /dash-rail -->

  </div><!-- /dash-body -->

</div><!-- /dash-page -->

<?php if ($needs_profile_completion ?? false) : ?>
    <?php require __DIR__ . '/components/modals/profile-completion-modal.php'; ?>
  <script src="/assets/profile-completion.js"></script>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
