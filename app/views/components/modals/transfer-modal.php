<?php

/**
 * Stock Transfer Modal Component
 *
 * Reusable modal for transferring stock between stores
 *
 * @param array $stores Array of stores with 'id' and 'name' keys
 * @param string $modal_id Optional modal ID (default: 'transferModal')
 * @param string $form_id Optional form ID (default: 'transferForm')
 */

// Set defaults
$modal_id = $modal_id ?? 'transferModal';
$form_id = $form_id ?? 'transferForm';
$stores = $stores ?? [];
$max_note_length = $max_note_length ?? 500;
?>

<!-- Transfer Stock Modal -->
<div id="<?= h($modal_id) ?>" class="modal" role="dialog" aria-labelledby="<?= h($modal_id) ?>-title" aria-hidden="true" style="display: none;">
  <div class="modal-content" tabindex="-1">
    <button class="close-button" aria-label="Close transfer modal" type="button">&times;</button>
    <h2 id="<?= h($modal_id) ?>-title">Transfer Stock</h2>
    <p>Transfer <span id="transferProductName"></span> from location <span id="transferSourceStore"></span></p>

    <form method="post" action="/api/stock.php" id="<?= h($form_id) ?>" novalidate>
      <?=csrf_field()?>
      <input type="hidden" name="action" value="transfer">
      <input type="hidden" name="product_id" id="transferProdId">
      <input type="hidden" name="from_store_id" id="transferFromStoreId">

      <div class="form-group">
        <label for="transferQuantity" class="required">Quantity to Transfer</label>
        <input type="number" name="quantity" id="transferQuantity" required
               min="1" max="10000" step="1" aria-describedby="transferQtyHelp">
        <div id="transferQtyHelp" class="form-help">
          Available: <span id="transferAvailableQty">0</span> units
        </div>
      </div>

      <div class="form-group">
        <label for="transferToStoreId" class="required">Transfer To Location</label>
        <select name="to_store_id" id="transferToStoreId" required aria-describedby="transferStoreHelp">
          <option value="">Select destination location</option>
          <?php foreach ($stores as $s) :
                ?>
            <option value="<?= (int)$s['id'] ?>"><?= h($s['name']) ?></option>
                <?php
          endforeach; ?>
        </select>
        <div id="transferStoreHelp" class="form-help">
          Select the location to transfer stock to
        </div>
      </div>

      <div class="form-group">
        <label for="transferNote">Notes</label>
        <input type="text" name="note" id="transferNote" maxlength="<?= (int)$max_note_length ?>"
               placeholder="Optional note about this transfer"
               aria-describedby="transferNoteHelp">
        <div id="transferNoteHelp" class="form-help">
          Optional: Add any details about this transfer (max <?= (int)$max_note_length ?> characters)
        </div>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Transfer Stock</button>
        <button type="button" class="btn btn-secondary transfer-cancel-btn">Cancel</button>
      </div>
    </form>
  </div>
</div>
