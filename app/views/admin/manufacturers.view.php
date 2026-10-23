<?php
$title = 'Product Manufacturers';
$manufacturers = $manufacturers ?? [];
$main_class = 'container admin-page';
require __DIR__ . '/../header.php';
?>

<?php foreach (flashes() as [$t,$m]) : ?>
  <div class="flash <?=h($t)?>"><?=h($m)?></div>
<?php endforeach; ?>

<h1>Product Manufacturers</h1>

<div class="admin-intro">
  <p>Manage product manufacturers to maintain a consistent list across your inventory. This helps with filtering and reporting.</p>
  <p><strong>Note:</strong> Manufacturers in use by products cannot be deleted. Reassign products before deletion.</p>
</div>

<!-- Add New Manufacturer -->
<div class="add-section">
  <h2>Manage Manufacturers</h2>
  <button id="addManufacturerBtn" class="btn btn-primary">+ Add Manufacturer</button>
</div>

<h2>Existing Manufacturers (<?= count($manufacturers) ?>)</h2>

<?php if (empty($manufacturers)) : ?>
  <p>No manufacturers configured. Add one to get started.</p>
<?php else : ?>
  <table class="table">
    <thead>
      <tr>
        <th>Sort</th>
        <th>Name</th>
        <th>Description</th>
        <th>Website</th>
        <th>Products</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($manufacturers as $manufacturer) : ?>
        <tr>
          <td data-label="Sort"><?= h($manufacturer['sort_order']) ?></td>
          <td data-label="Name"><strong><?= h($manufacturer['name']) ?></strong></td>
          <td data-label="Description"><?= h($manufacturer['description'] ?: 'N/A') ?></td>
          <td data-label="Website">
            <?php if ($manufacturer['website']) : ?>
              <a href="<?= h($manufacturer['website']) ?>" target="_blank" rel="noopener noreferrer">
                <?= h(parse_url($manufacturer['website'], PHP_URL_HOST) ?: $manufacturer['website']) ?> ↗
              </a>
            <?php else : ?>
              <span class="text-muted">N/A</span>
            <?php endif; ?>
          </td>
          <td data-label="Products">
            <?php if ($manufacturer['product_count'] > 0) : ?>
              <span class="badge badge-info"><?= $manufacturer['product_count'] ?> product<?= $manufacturer['product_count'] != 1 ? 's' : '' ?></span>
            <?php else : ?>
              <span class="text-muted">None</span>
            <?php endif; ?>
          </td>
          <td data-label="Status">
            <span class="badge <?= $manufacturer['is_active'] ? 'badge-success' : 'badge-inactive' ?>">
              <?= $manufacturer['is_active'] ? 'Active' : 'Inactive' ?>
            </span>
          </td>
          <td data-label="Actions">
            <button
              class="btn btn-secondary btn-sm js-manufacturer-edit"
              data-id="<?= (int)$manufacturer['id'] ?>"
              data-name="<?= h($manufacturer['name']) ?>"
              data-description="<?= h($manufacturer['description'] ?? '') ?>"
              data-website="<?= h($manufacturer['website'] ?? '') ?>"
              data-active="<?= $manufacturer['is_active'] ?>"
              data-sort="<?= h($manufacturer['sort_order']) ?>"
            >Edit</button>

            <form method="post" action="/admin/manufacturers" style="display:inline-block;" class="delete-manufacturer-form">
              <?=csrf_field()?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$manufacturer['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm" <?= $manufacturer['product_count'] > 0 ? 'disabled title="Cannot delete: Manufacturer is in use"' : '' ?>>Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<!-- Add/Edit Modal -->
<div id="manufacturerModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h2 id="modalTitle">Add Manufacturer</h2>
      <button class="close-button" type="button" aria-label="Close modal">&times;</button>
    </div>
    <div class="modal-body">
      <form method="post" action="/admin/manufacturers" id="manufacturerForm">
        <?=csrf_field()?>
        <input type="hidden" name="action" id="formAction" value="add">
        <input type="hidden" name="id" id="manufacturerId">

        <div class="form-group">
          <label>Manufacturer Name <span class="required">*</span>
            <input type="text" name="name" id="manufacturerName" required maxlength="100" placeholder="e.g., Acme Tools, Smith Electric">
          </label>
          <small>Maximum 100 characters</small>
        </div>

        <div class="form-group">
          <label>Description
            <textarea name="description" id="manufacturerDescription" rows="3" placeholder="Optional description or notes about this manufacturer"></textarea>
          </label>
          <small>Optional. Additional information about the manufacturer.</small>
        </div>

        <div class="form-group">
          <label>Website
            <input type="url" name="website" id="manufacturerWebsite" placeholder="https://example.com">
          </label>
          <small>Optional. Must be a valid URL (e.g., https://example.com).</small>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Sort Order
              <input type="number" name="sort_order" id="manufacturerSortOrder" value="0" min="0">
            </label>
            <small>Lower numbers appear first</small>
          </div>
          <div class="form-group checkbox-group">
            <label>
              <input type="checkbox" name="is_active" id="manufacturerActive" checked> Active
            </label>
            <small>Inactive manufacturers won't appear in product filters</small>
          </div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="if(window.ModalManager) ModalManager.hide(document.getElementById('manufacturerModal')); else document.getElementById('manufacturerModal').style.display='none';">Cancel</button>
      <button type="submit" class="btn btn-primary" onclick="document.getElementById('manufacturerForm').submit()">Save Manufacturer</button>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const manufacturerModal = document.getElementById('manufacturerModal');

  // Add manufacturer button
  document.getElementById('addManufacturerBtn')?.addEventListener('click', () => {
    document.getElementById('modalTitle').textContent = 'Add Manufacturer';
    document.getElementById('formAction').value = 'add';
    document.getElementById('manufacturerForm').reset();
    document.getElementById('manufacturerId').value = '';
    document.getElementById('manufacturerActive').checked = true;
    if (window.ModalManager) {
      ModalManager.show(manufacturerModal);
    } else {
      manufacturerModal.style.display = 'block';
    }
  });

  // Edit buttons
  document.querySelectorAll('.js-manufacturer-edit').forEach(btn => {
    btn.addEventListener('click', () => {
      document.getElementById('modalTitle').textContent = 'Edit Manufacturer';
      document.getElementById('formAction').value = 'edit';
      document.getElementById('manufacturerId').value = btn.dataset.id;
      document.getElementById('manufacturerName').value = btn.dataset.name;
      document.getElementById('manufacturerDescription').value = btn.dataset.description || '';
      document.getElementById('manufacturerWebsite').value = btn.dataset.website || '';
      document.getElementById('manufacturerSortOrder').value = btn.dataset.sort;
      document.getElementById('manufacturerActive').checked = btn.dataset.active === '1';
      if (window.ModalManager) {
        ModalManager.show(manufacturerModal);
      } else {
        manufacturerModal.style.display = 'block';
      }
    });
  });

  // Close button
  document.querySelectorAll('.close-button').forEach(btn => {
    btn.addEventListener('click', function() {
      const modal = this.closest('.modal');
      if (window.ModalManager) {
        ModalManager.hide(modal);
      } else {
        modal.style.display = 'none';
      }
    });
  });

  // Delete confirmations
  document.querySelectorAll('.delete-manufacturer-form').forEach(form => {
    form.addEventListener('submit', function(e) {
      e.preventDefault();

      QuackNotify.confirm('Delete this manufacturer? Products using this manufacturer will no longer be associated with it.', {
        title: 'Delete Manufacturer',
        confirmText: 'Delete',
        cancelText: 'Cancel',
        confirmStyle: 'danger'
      }).then(confirmed => {
        if (confirmed) this.submit();
      });
    });
  });
});
</script>

<style>
.admin-intro {
  background: var(--surface-2);
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 1.5rem;
  margin-bottom: 2rem;
}

.add-section {
  margin-bottom: 2rem;
}

.form-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
}

.form-group {
  margin-bottom: 1rem;
}

.form-group label {
  display: block;
  margin-bottom: 0.5rem;
  font-weight: 600;
}

.form-group input,
.form-group textarea {
  width: 100%;
  padding: 0.5rem;
  border: 1px solid var(--border);
  border-radius: 4px;
}

.form-group small {
  display: block;
  color: var(--text-secondary);
  margin-top: 0.25rem;
  font-size: 0.875rem;
}

.checkbox-group {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.checkbox-group label {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-weight: normal;
}

.checkbox-group input[type="checkbox"] {
  width: auto;
}

.badge {
  display: inline-block;
  padding: 0.25rem 0.5rem;
  border-radius: 4px;
  font-size: 0.875rem;
  font-weight: 600;
}

.badge-success {
  background: var(--badge-in-bg);
  color: var(--badge-in-text);
}

.badge-inactive {
  background: var(--badge-neutral-bg);
  color: var(--text-secondary);
}

.badge-info {
  background: var(--badge-info-bg);
  color: var(--badge-info-text);
}

.required {
  color: red;
}

.text-muted {
  color: var(--text-secondary);
}
</style>

<?php require __DIR__ . '/../footer.php'; ?>
