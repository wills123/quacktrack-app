<?php
$product = $product ?? [];
$title        = 'Item Details — ' . ($product['name'] ?? 'Van Stock');
$main_class   = 'main-content';
$extra_styles = ['/assets/opdash.css', '/assets/pages/stock_item.css'];
$total_qty = $total_qty ?? 0;
$low_stock_threshold = $low_stock_threshold ?? 0;
$locations = $locations ?? [];
$view_user = $view_user ?? [];
$viewing_other_user = $viewing_other_user ?? false;
$all_stores = $all_stores ?? [];
$max_note_length = $max_note_length ?? 1000;
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
<?php

// Compute status
$data_status = 'in';
if ($total_qty <= 0) {
    $data_status  = 'out';
    $badge_label  = 'Out of stock';
    $badge_cls    = 'out';
} elseif ($total_qty <= $low_stock_threshold) {
    $data_status  = 'low';
    $badge_label  = 'Low stock';
    $badge_cls    = 'low';
} else {
    $badge_label  = 'In stock';
    $badge_cls    = 'in';
}

// Location proportion for bar
$max_loc_qty = 0;
foreach ($locations as $loc) {
    if ((int)$loc['quantity'] > $max_loc_qty) {
        $max_loc_qty = (int)$loc['quantity'];
    }
}

// Reason → CSS class map
function reason_class(string $reason): string
{
    $map = [
        'use'    => 'reason-use',
        'used'   => 'reason-use',
        'add'    => 'reason-add',
        'added'  => 'reason-add',
        'transfer' => 'reason-xfer',
        'xfer'   => 'reason-xfer',
        'adjust' => 'reason-adj',
        'adjustment' => 'reason-adj',
        'return' => 'reason-return',
    ];
    return $map[strtolower($reason)] ?? 'reason-other';
}

$thumb_initials = strtoupper(substr($product['name'] ?? '', 0, 2));
$back_url = '/stock' . ($viewing_other_user ? '?van_user_id=' . (int)$view_user['id'] : '');
$store_label = static function (array $store): string {
    $store_number = trim((string)($store['store_number'] ?? ''));
    $store_name = trim((string)($store['store_name'] ?? $store['name'] ?? ''));

    if ($store_number === '') {
        return $store_name ?: 'Unassigned';
    }

    return $store_name !== '' ? 'Store ' . $store_number . ' — ' . $store_name : 'Store ' . $store_number;
};

$__flashes = flashes();
?>

<div class="item-page">

  <?php if (!empty($__flashes)) : ?>
    <div class="item-flashes">
        <?php foreach ($__flashes as [$type, $msg]) : ?>
        <div class="flash <?= h($type) ?>"><?= h($msg) ?></div>
        <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Masthead -->
  <div class="page-masthead item-masthead">
    <div class="item-masthead-inner">
      <div class="item-masthead-brand">

        <!-- Thumbnail -->
        <?php if (!empty($product['image_path'])) : ?>
          <img src="<?= h(image_url($product['image_path'])) ?>" alt="" class="item-masthead-thumb">
        <?php else : ?>
          <div class="item-masthead-thumb-placeholder" aria-hidden="true"><?= h($thumb_initials) ?></div>
        <?php endif; ?>

        <div class="item-masthead-text">
          <div class="item-masthead-eyebrow">
            <a href="<?= h($back_url) ?>">← Van Stock</a>
            <span class="item-masthead-eyebrow-sep">/</span>
            <span>Item Detail</span>
          </div>
          <h1 class="item-masthead-title"><?= h($product['name']) ?></h1>
          <div class="item-masthead-sub">
            <?php if (!empty($product['product_id'])) : ?>
                <?= h($product['product_id']) ?>
            <?php endif; ?>
            <?php if (!empty($product['category_name'])) : ?>
              &nbsp;·&nbsp;<?= h($product['category_name']) ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="item-masthead-actions">
        <?php if ($viewing_other_user) : ?>
          <span class="masthead-note">Admin view — actions disabled</span>
        <?php else : ?>
          <span class="item-status-badge <?= $badge_cls ?>"><?= h($badge_label) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Stat strip -->
  <div class="item-stats">
    <div class="item-stat">
      <div class="item-stat-value<?= $data_status === 'out' ? ' danger' : ($data_status === 'low' ? ' warn' : '') ?>"><?= number_format($total_qty) ?></div>
      <div class="item-stat-label">Total Quantity</div>
    </div>
    <div class="item-stat">
      <div class="item-stat-value"><?= count($locations) ?></div>
      <div class="item-stat-label">Locations</div>
    </div>
    <div class="item-stat">
      <div class="item-stat-value<?= $low_stock_threshold >= $total_qty && $total_qty > 0 ? ' warn' : '' ?>"><?= number_format($low_stock_threshold) ?></div>
      <div class="item-stat-label">Low Stock Threshold</div>
    </div>
    <div class="item-stat">
      <div class="item-stat-value"><?= !empty($last_updated_at) ? h(date('M j', strtotime($last_updated_at))) : '—' ?></div>
      <div class="item-stat-label">Last Updated</div>
    </div>
  </div>

  <!-- Body -->
  <div class="item-body">

    <!-- Left: main column -->
    <div class="item-main">

      <!-- Location Distribution -->
      <div class="item-card">
        <div class="item-card-header">
          <span class="item-card-title">Location Distribution</span>
        </div>
        <div class="item-card-body" style="padding: 0;">
          <?php if (empty($locations)) : ?>
            <div class="item-empty">No stock at any location.</div>
          <?php else : ?>
            <table class="location-table">
              <thead>
                <tr>
                  <th>Location</th>
                  <th>Quantity</th>
                  <th class="location-bar-cell">Share</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($locations as $loc) :
                    $lqty = (int)$loc['quantity'];
                    $pct  = $max_loc_qty > 0 ? round(($lqty / $max_loc_qty) * 100) : 0;
                    ?>
                <tr>
                  <td><?= h($store_label($loc)) ?></td>
                  <td><span class="location-qty"><?= number_format($lqty) ?></span></td>
                  <td class="location-bar-cell">
                    <div class="location-bar-track">
                      <div class="location-bar-fill" style="width:<?= $pct ?>%"></div>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

      <!-- Recent Activity -->
      <div class="item-card">
        <div class="item-card-header">
          <span class="item-card-title">Recent Activity</span>
          <span style="font-size:11px;color:var(--text-muted);">Last 10 transactions</span>
        </div>
        <div class="item-card-body" style="padding: 0;">
          <?php if (empty($transactions)) : ?>
            <div class="item-empty">No transactions recorded for this item yet.</div>
          <?php else : ?>
            <table class="activity-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Qty</th>
                  <th>Reason</th>
                  <th>From</th>
                  <th>To</th>
                  <th>User</th>
                  <th>Notes</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($transactions as $row) :
                    $qty    = (int)$row['quantity_change'];
                    $reason = strtolower($row['reason'] ?? '');
                    ?>
                <tr>
                  <td class="activity-date"><?= h(date('M j, Y', strtotime($row['created_at']))) ?></td>
                  <td>
                    <span class="activity-delta <?= $qty >= 0 ? 'pos' : 'neg' ?>">
                      <?= $qty >= 0 ? '+' . $qty : $qty ?>
                    </span>
                  </td>
                  <td>
                    <span class="activity-reason <?= reason_class($reason) ?>">
                      <?= h(ucfirst($row['reason'] ?? 'Other')) ?>
                    </span>
                  </td>
                  <td><?= h($row['source_store_name'] ?? '—') ?></td>
                  <td><?= h($row['target_store_name'] ?? '—') ?></td>
                  <td><?= h($row['user_display'] ?? $row['user_email'] ?? '') ?></td>
                  <td>
                    <?php if (!empty($row['note'])) : ?>
                      <span class="activity-note" title="<?= h($row['note']) ?>"><?= h($row['note']) ?></span>
                    <?php else : ?>
                      <span style="color:var(--text-muted);font-size:11px;">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- /item-main -->

    <!-- Right: rail -->
    <div class="item-rail">

      <?php if ($viewing_other_user) : ?>
        <div class="item-card">
          <div class="item-card-header">
            <span class="item-card-title">Transfer Stock</span>
          </div>
          <div class="item-card-body">
            <div class="item-admin-notice">
              You are viewing <strong><?= h($view_user['display_name']) ?></strong>'s van as an admin. Transfers are disabled.
            </div>
          </div>
        </div>

      <?php else : ?>
        <!-- Transfer card with tabs -->
        <div class="item-card">
          <div class="item-card-header">
            <span class="item-card-title">Transfer Stock</span>
          </div>
          <div class="item-card-body">
            <div class="transfer-section">

              <!-- Tab bar -->
              <div class="transfer-tab-bar">
                <button class="transfer-tab is-active" data-tab="within" type="button">Within Van</button>
                <button class="transfer-tab" data-tab="tovan" type="button">To Another Van</button>
              </div>

              <!-- Panel: Transfer within van -->
              <div class="transfer-panel is-active" id="tab-within">
                <form class="detail-form-ajax" method="post" action="/api/stock.php">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="transfer">
                  <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">

                  <div class="item-form-group">
                    <label for="transferSourceStore">Source Location</label>
                    <select id="transferSourceStore" name="from_store_id" required>
                      <option value="">Select location</option>
                      <?php foreach ($locations as $location) : ?>
                        <option value="<?= (int)$location['store_id'] ?>">
                            <?= h($store_label($location)) ?> (<?= number_format((int)$location['quantity']) ?>)
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="item-form-group">
                    <label for="transferTargetStore">Destination</label>
                    <select id="transferTargetStore" name="to_store_id" required>
                      <option value="">Select location</option>
                      <?php foreach ($all_stores as $store) : ?>
                        <option value="<?= (int)$store['id'] ?>"><?= h($store_label($store)) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="item-form-group">
                    <label for="transferQty">Quantity</label>
                    <input type="number" id="transferQty" name="quantity" min="1" step="1" inputmode="numeric" placeholder="0" required>
                  </div>

                  <div class="item-form-group">
                    <label for="transferNote">Notes</label>
                    <input type="text" id="transferNote" name="note"
                           maxlength="<?= (int)$max_note_length ?>"
                           placeholder="Optional">
                    <span class="item-form-help">Max <?= (int)$max_note_length ?> characters</span>
                  </div>

                  <button class="item-form-btn" type="submit">Transfer</button>
                </form>
              </div>

              <!-- Panel: Transfer to another van -->
              <div class="transfer-panel" id="tab-tovan">
                <?php if (empty($van_users)) : ?>
                  <p style="font-size:13px;color:var(--text-muted);">No other vans available.</p>
                <?php else : ?>
                  <form class="detail-form-ajax" method="post" action="/api/stock.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="transfer_van">
                    <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">

                    <div class="item-form-group">
                      <label for="transferVanSource">Source Location</label>
                      <select id="transferVanSource" name="from_store_id" required>
                        <option value="">Select location</option>
                        <?php foreach ($locations as $location) : ?>
                          <option value="<?= (int)$location['store_id'] ?>">
                            <?= h($store_label($location)) ?> (<?= number_format((int)$location['quantity']) ?>)
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>

                    <div class="item-form-group">
                      <label for="transferTargetVan">Target Van</label>
                      <select id="transferTargetVan" name="target_user_id" required>
                        <option value="">Select van</option>
                        <?php foreach ($van_users as $user) :
                            $display = $user['username']
                            ?: trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))
                            ?: $user['email'];
                            ?>
                          <option value="<?= (int)$user['id'] ?>"><?= h($display) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>

                    <div class="item-form-group">
                      <label for="transferVanDest">Destination Location</label>
                      <select id="transferVanDest" name="to_store_id" required>
                        <option value="">Select location</option>
                        <?php foreach ($all_stores as $store) : ?>
                          <option value="<?= (int)$store['id'] ?>"><?= h($store_label($store)) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>

                    <div class="item-form-group">
                      <label for="transferVanQty">Quantity</label>
                      <input type="number" id="transferVanQty" name="quantity" min="1" step="1" inputmode="numeric" placeholder="0" required>
                    </div>

                    <div class="item-form-group">
                      <label for="transferVanNote">Notes</label>
                      <input type="text" id="transferVanNote" name="note"
                             maxlength="<?= (int)$max_note_length ?>"
                             placeholder="Optional">
                    </div>

                    <button class="item-form-btn" type="submit">Transfer to Van</button>
                  </form>
                <?php endif; ?>
              </div>

            </div>
          </div>
        </div>

      <?php endif; ?>

    </div><!-- /item-rail -->

  </div><!-- /item-body -->

</div><!-- /item-page -->

<script>
// Tab switching for transfer card
document.querySelectorAll('.transfer-tab').forEach(function(tab) {
  tab.addEventListener('click', function() {
    var target = this.dataset.tab;
    document.querySelectorAll('.transfer-tab').forEach(function(t) { t.classList.remove('is-active'); });
    document.querySelectorAll('.transfer-panel').forEach(function(p) { p.classList.remove('is-active'); });
    this.classList.add('is-active');
    var panel = document.getElementById('tab-' + target);
    if (panel) panel.classList.add('is-active');
  });
});
</script>

<script src="/assets/stock.js"></script>

<?php require __DIR__ . '/footer.php'; ?>
