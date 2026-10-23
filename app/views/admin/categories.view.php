<?php
$title = 'Product Categories';
$categories = $categories ?? [];
$main_class = 'container admin-page';
require __DIR__ . '/../header.php';
?>

<?php foreach (flashes() as [$t,$m]) : ?>
  <div class="flash <?=h($t)?>"><?=h($m)?></div>
<?php endforeach; ?>

<h1>Product Categories</h1>

<div class="admin-intro">
  <p>Manage product categories to organize your inventory. Categories help users filter and search products effectively.</p>
  <p><strong>Note:</strong> Categories in use by products cannot be deleted. Reassign products before deletion.</p>
</div>

<!-- Add New Category -->
<div class="add-section">
  <h2>Manage Categories</h2>
  <button id="addCategoryBtn" class="btn btn-primary">+ Add Category</button>
</div>

<h2>Existing Categories (<?= count($categories) ?>)</h2>

<?php if (empty($categories)) : ?>
  <p>No categories configured. Add one to get started.</p>
<?php else : ?>
  <table class="table">
    <thead>
      <tr>
        <th>Sort</th>
        <th>Name</th>
        <th>Description</th>
        <th>Products</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($categories as $category) : ?>
        <tr>
          <td data-label="Sort"><?= h($category['sort_order']) ?></td>
          <td data-label="Name"><strong><?= h($category['name']) ?></strong></td>
          <td data-label="Description"><?= h($category['description'] ?: 'N/A') ?></td>
          <td data-label="Products">
            <?php if ($category['product_count'] > 0) : ?>
              <span class="badge badge-info"><?= $category['product_count'] ?> product<?= $category['product_count'] != 1 ? 's' : '' ?></span>
            <?php else : ?>
              <span class="text-muted">None</span>
            <?php endif; ?>
          </td>
          <td data-label="Status">
            <span class="badge <?= $category['is_active'] ? 'badge-success' : 'badge-inactive' ?>">
              <?= $category['is_active'] ? 'Active' : 'Inactive' ?>
            </span>
          </td>
          <td data-label="Actions">
            <button
              class="btn btn-secondary btn-sm js-category-edit"
              data-id="<?= (int)$category['id'] ?>"
              data-name="<?= h($category['name']) ?>"
              data-description="<?= h($category['description'] ?? '') ?>"
              data-active="<?= $category['is_active'] ?>"
              data-sort="<?= h($category['sort_order']) ?>"
            >Edit</button>

            <form method="post" action="/admin/categories" style="display:inline-block;" class="delete-category-form">
              <?=csrf_field()?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$category['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm" <?= $category['product_count'] > 0 ? 'disabled title="Cannot delete: Category is in use"' : '' ?>>Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<!-- Add/Edit Modal -->
<div id="categoryModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h2 id="modalTitle">Add Category</h2>
      <button class="close-button" type="button" aria-label="Close modal">&times;</button>
    </div>
    <div class="modal-body">
      <form method="post" action="/admin/categories" id="categoryForm">
        <?=csrf_field()?>
        <input type="hidden" name="action" id="formAction" value="add">
        <input type="hidden" name="id" id="categoryId">

        <div class="form-group">
          <label>Category Name <span class="required">*</span>
            <input type="text" name="name" id="categoryName" required maxlength="100" placeholder="e.g., Electrical, Plumbing">
          </label>
          <small>Maximum 100 characters</small>
        </div>

        <div class="form-group">
          <label>Description
            <textarea name="description" id="categoryDescription" rows="3" placeholder="Optional description of this category"></textarea>
          </label>
          <small>Optional. Helps explain what products belong in this category.</small>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Sort Order
              <input type="number" name="sort_order" id="categorySortOrder" value="0" min="0">
            </label>
            <small>Lower numbers appear first</small>
          </div>
          <div class="form-group checkbox-group">
            <label>
              <input type="checkbox" name="is_active" id="categoryActive" checked> Active
            </label>
            <small>Inactive categories won't appear in product filters</small>
          </div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="if(window.ModalManager) ModalManager.hide(document.getElementById('categoryModal')); else document.getElementById('categoryModal').style.display='none';">Cancel</button>
      <button type="submit" class="btn btn-primary" onclick="document.getElementById('categoryForm').submit()">Save Category</button>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const categoryModal = document.getElementById('categoryModal');

  // Add category button
  document.getElementById('addCategoryBtn')?.addEventListener('click', () => {
    document.getElementById('modalTitle').textContent = 'Add Category';
    document.getElementById('formAction').value = 'add';
    document.getElementById('categoryForm').reset();
    document.getElementById('categoryId').value = '';
    document.getElementById('categoryActive').checked = true;
    if (window.ModalManager) {
      ModalManager.show(categoryModal);
    } else {
      categoryModal.style.display = 'block';
    }
  });

  // Edit buttons
  document.querySelectorAll('.js-category-edit').forEach(btn => {
    btn.addEventListener('click', () => {
      document.getElementById('modalTitle').textContent = 'Edit Category';
      document.getElementById('formAction').value = 'edit';
      document.getElementById('categoryId').value = btn.dataset.id;
      document.getElementById('categoryName').value = btn.dataset.name;
      document.getElementById('categoryDescription').value = btn.dataset.description || '';
      document.getElementById('categorySortOrder').value = btn.dataset.sort;
      document.getElementById('categoryActive').checked = btn.dataset.active === '1';
      if (window.ModalManager) {
        ModalManager.show(categoryModal);
      } else {
        categoryModal.style.display = 'block';
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
  document.querySelectorAll('.delete-category-form').forEach(form => {
    form.addEventListener('submit', function(e) {
      e.preventDefault();

      QuackNotify.confirm('Delete this category? Products using this category will no longer be associated with it.', {
        title: 'Delete Category',
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
