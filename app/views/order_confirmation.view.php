<?php
$title = 'Order Confirmation';
$main_class = 'main-content';
$order = $order ?? [];
$order_items = $order_items ?? [];
$purchasing_email = $purchasing_email ?? '';
require __DIR__ . '/header.php';

$itemCount = count($order_items);
$unitCount = 0;
foreach ($order_items as $it) {
    $unitCount += (int)$it['quantity'];
}
$storedVan = !empty($order['receive_to_van']);
$storedRegion = !empty($order['receive_to_region']);
$destination = $storedVan && $storedRegion ? 'Van & region stock' : ($storedRegion ? 'Region stock' : 'Van stock');

// Data for the "resend email" action, JSON-encoded so quotes/newlines can't break the script.
$emailData = [
    'to' => $purchasing_email,
    'orderNumber' => (string)$order['order_number'],
    'store' => (string)($order['store_name'] ?? ''),
    'region' => (string)($_SESSION['region'] ?? 'Unknown'),
    'date' => date('Y-m-d'),
    'notes' => (string)($order['notes'] ?? ''),
    'items' => array_map(static fn ($i) => [
        'sku' => (string)$i['sku'],
        'name' => (string)$i['name'],
        'quantity' => (int)$i['quantity'],
        'notes' => (string)($i['notes'] ?? ''),
    ], $order_items),
];
?>
<div class="stock-page confirmation-page">

  <!-- Masthead -->
  <div class="page-masthead stock-masthead">
    <div class="stock-masthead-inner">
      <div class="stock-masthead-brand">
        <h1 class="stock-masthead-title">Order Sent</h1>
        <p class="stock-masthead-sub">Order <?= h($order['order_number']) ?><?= !empty($order['store_name']) ? ' for ' . h($order['store_name']) : '' ?></p>
      </div>
      <div class="masthead-tabs stock-masthead-actions">
        <a href="/orders" class="masthead-tab">Order History</a>
      </div>
    </div>
  </div>

  <div class="confirmation-body">

    <section class="confirmation-hero" aria-labelledby="confirmationHeading">
      <span class="confirmation-hero-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
      </span>
      <div>
        <h2 id="confirmationHeading">Your order has been placed</h2>
        <p>We opened an email to purchasing with the order details. Send it from your email app to get the order moving.</p>
      </div>
    </section>

    <!-- Order summary -->
    <div class="order-card">
      <div class="order-card-header">
        <div class="order-card-identity">
          <span class="order-number"><?= h($order['order_number']) ?></span>
          <span class="order-date"><?= h(date('M j, Y \a\t g:i A', strtotime($order['created_at']))) ?></span>
        </div>
        <span class="qt-status-badge qt-status-badge-<?= h($order['status']) ?>"><?= h(ucfirst(str_replace('_', ' ', $order['status']))) ?></span>
      </div>
      <div class="order-card-meta">
        <div class="order-meta-row">
          <span class="order-meta-label">Store</span>
          <span class="order-meta-value"><?= h($order['store_name'] ?? 'N/A') ?></span>
        </div>
        <div class="order-meta-row">
          <span class="order-meta-label">Items</span>
          <span class="order-meta-value"><?= $unitCount ?> unit<?= $unitCount !== 1 ? 's' : '' ?> across <?= $itemCount ?> line<?= $itemCount !== 1 ? 's' : '' ?></span>
        </div>
        <div class="order-meta-row">
          <span class="order-meta-label">Receives into</span>
          <span class="order-meta-value"><?= h($destination) ?></span>
        </div>
        <?php if (!empty($order['notes'])) : ?>
          <div class="order-meta-row">
            <span class="order-meta-label">Notes</span>
            <span class="order-meta-value order-meta-notes"><?= h($order['notes']) ?></span>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- What happens next -->
    <div class="order-card">
      <h3 class="confirmation-section-title">What happens next</h3>
      <ol class="confirmation-steps">
        <li><strong>Send the email.</strong> Review the message that opened in your email app and send it to purchasing. Didn’t open? Use “Resend email” below.</li>
        <li><strong>Wait for delivery.</strong> Purchasing processes the order and ships it.</li>
        <li><strong>Receive it.</strong> When the order arrives, record it on the <a href="/receiving">Receiving</a> page: supplies go into stock and tracked assets are matched to their Snipe-IT record.</li>
      </ol>
    </div>

    <!-- Items -->
    <div class="order-card">
      <h3 class="confirmation-section-title">Items ordered (<?= $itemCount ?>)</h3>
      <div class="confirmation-items">
        <div class="orders-items-list">
          <?php foreach ($order_items as $item) : ?>
            <div class="orders-item">
                <?php if (!empty($item['image_path'])) : ?>
                <img class="orders-item-img" src="<?= h(image_url($item['image_path'])) ?>" alt="<?= h($item['name']) ?>">
                <?php else : ?>
                <div class="orders-item-img orders-item-no-img">No Image</div>
                <?php endif; ?>
              <div class="orders-item-info">
                <p class="orders-item-name"><?= h($item['name']) ?></p>
                <p class="orders-item-sku">SKU: <?= h($item['sku']) ?></p>
                <p class="orders-item-qty">Qty: <?= (int)$item['quantity'] ?></p>
                <?php if (!empty($item['notes'])) : ?>
                  <p class="orders-item-notes"><?= h($item['notes']) ?></p>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="confirmation-actions">
      <a href="/products" class="orders-btn-primary">Continue Shopping</a>
      <a href="/receiving" class="orders-btn-secondary">Go to Receiving</a>
      <a href="/orders" class="orders-btn-secondary">Order History</a>
      <button type="button" id="resendEmailBtn" class="orders-btn-secondary">Resend email</button>
    </div>
  </div>
</div>

<dialog class="confirmation-dialog" id="manualEmailDialog" aria-labelledby="manualEmailTitle">
  <form method="dialog" class="confirmation-dialog-body">
    <h2 id="manualEmailTitle">Email app not opening?</h2>
    <p><strong>To:</strong> <span id="manualEmailTo"></span></p>
    <p><strong>Subject:</strong> <span id="manualEmailSubject"></span></p>
    <textarea id="manualEmailBody" readonly aria-label="Email body"></textarea>
    <div class="confirmation-dialog-actions">
      <button type="button" class="orders-btn-secondary" id="manualEmailCopy">Copy email body</button>
      <button type="submit" class="orders-btn-primary" value="close">Done</button>
    </div>
  </form>
</dialog>

<script>
(function () {
  const data = <?= json_encode($emailData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

  function buildEmail() {
    let body = 'Purchase Order Request\n========================\n\n';
    body += 'Order: ' + data.orderNumber + '\n';
    body += 'Store: ' + data.store + '\n';
    body += 'Region: ' + data.region + '\n';
    body += 'Date: ' + data.date + '\n========================\n\n';
    data.items.forEach((item) => {
      body += 'SKU: ' + item.sku + '\nProduct: ' + item.name + '\nQuantity: ' + item.quantity + '\n';
      if (item.notes) body += 'Notes: ' + item.notes + '\n';
      body += '\n';
    });
    if (data.notes) body += 'Order Notes: ' + data.notes + '\n\n';
    body += 'Thank you!';
    return { subject: 'Purchase Order Request - ' + data.store, body };
  }

  const dialog = document.getElementById('manualEmailDialog');

  function showManualOptions(email) {
    document.getElementById('manualEmailTo').textContent = data.to || '(choose a purchasing address)';
    document.getElementById('manualEmailSubject').textContent = email.subject;
    document.getElementById('manualEmailBody').value = email.body;
    dialog.showModal();
  }

  document.getElementById('resendEmailBtn').addEventListener('click', () => {
    const email = buildEmail();
    if (!data.to) { showManualOptions(email); return; }
    window.location.href = 'mailto:' + data.to + '?subject=' + encodeURIComponent(email.subject) + '&body=' + encodeURIComponent(email.body);
    // A mailto: link gives no feedback, so offer the copy-and-paste fallback shortly after.
    setTimeout(() => {
      if (!window.QuackNotify || typeof QuackNotify.confirm !== 'function') { showManualOptions(email); return; }
      QuackNotify.confirm('Did the email open with the body filled in?', {
        title: 'Email check',
        confirmText: 'Yes, it opened',
        cancelText: 'No, show me the text',
        confirmStyle: 'primary'
      }).then((opened) => { if (!opened) showManualOptions(email); });
    }, 2000);
  });

  document.getElementById('manualEmailCopy').addEventListener('click', async () => {
    const textarea = document.getElementById('manualEmailBody');
    try {
      await navigator.clipboard.writeText(textarea.value);
    } catch (_e) {
      textarea.select();
      document.execCommand('copy');
    }
    if (window.QuackNotify) QuackNotify.success('Email body copied. Paste it into your email app.');
  });
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
