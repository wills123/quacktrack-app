<?php

/**
 * Optional bulk-add modal component.
 * Include this file from any view that needs the bulk-add UI + logic.
 */

?>
<div id="bulkAddModal" class="modal">
  <div class="modal-content">
    <span class="close-button" aria-label="Close bulk add modal">&times;</span>
    <h2>Bulk Add Items</h2>
    <p>Enter multiple SKUs and quantities (one per line):</p>
    <textarea id="bulkAddText" placeholder="SKU123 5&#10;SKU456 2&#10;SKU789 1" rows="10"></textarea>
    <button id="processBulkAdd" class="btn-primary">Add All to Cart</button>
  </div>
</div>

<script>
(function() {
  const bulkAddModal = document.getElementById('bulkAddModal');
  if (!bulkAddModal) return;

  const closeBtn = bulkAddModal.querySelector('.close-button');
  const processBtn = document.getElementById('processBulkAdd');

  function toggleModal(show) {
    bulkAddModal.style.display = show ? 'block' : 'none';
  }

  window.openBulkAddModal = () => toggleModal(true);

  closeBtn?.addEventListener('click', () => toggleModal(false));

  processBtn?.addEventListener('click', async () => {
    const textarea = document.getElementById('bulkAddText');
    const text = textarea?.value.trim() ?? '';
    if (!text) return;

    const items = [];
    text.split('\n').forEach(line => {
      const parts = line.trim().split(/\s+/);
      if (parts.length >= 2) {
        const sku = parts[0];
        const quantity = parseInt(parts[1], 10);
        if (quantity > 0) {
          items.push({ sku, quantity });
        }
      }
    });

    if (!items.length) {
      QuackNotify?.warning('No valid items found. Format: SKU123 5');
      return;
    }

    try {
      const response = await secureFetch('/api/cart.php?action=bulk_add', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ items })
      });
      const data = await response.json();
      if (!data.success) {
        throw new Error(data.error || 'Bulk add failed');
      }
      toggleModal(false);
      location.reload();
    } catch (err) {
      QuackNotify?.error(err.message || 'Bulk add failed');
    }
  });
})();
</script>
