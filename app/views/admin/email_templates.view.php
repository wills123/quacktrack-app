<?php
$title = 'Email Template Management';
$emails = $emails ?? [];
$main_class = 'container admin-page';
require __DIR__ . '/../header.php';
?>

<?php foreach (flashes() as [$t,$m]) : ?>
  <div class="flash <?=h($t)?>"><?=h($m)?></div>
<?php endforeach; ?>

<h1>Email Template Management</h1>

<div class="template-intro">
  <p>Manage purchasing email addresses and customize email templates sent during checkout. Each email can have its own subject, body, and CC list.</p>
  <p><strong>Available Variables:</strong> {{ORDER_NUMBER}}, {{STORE_NAME}}, {{REGION}}, {{DATE}}, {{ITEMS_LIST}}, {{ORDER_NOTES}}, {{USER_NAME}}, {{USER_EMAIL}}, {{TOTAL_ITEMS}}</p>
</div>

<!-- Add New Email Template -->
<div class="add-template-section">
  <h2>Add New Purchasing Email</h2>
  <button id="addTemplateBtn" class="btn btn-primary">+ Add Purchasing Email</button>
</div>

<h2>Existing Templates (<?= count($emails) ?>)</h2>

<?php if (empty($emails)) : ?>
  <p>No purchasing emails configured. Add one to get started.</p>
<?php else : ?>
  <table class="table">
    <thead>
      <tr>
        <th>Sort</th>
        <th>Label</th>
        <th>Email</th>
        <th>CC Addresses</th>
        <th>Status</th>
        <th>Default</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($emails as $email) : ?>
        <tr>
          <td data-label="Sort"><?= h($email['sort_order']) ?></td>
          <td data-label="Label"><strong><?= h($email['label']) ?></strong></td>
          <td data-label="Email"><?= h($email['email']) ?></td>
          <td data-label="CC"><?= h($email['cc_addresses'] ?: 'None') ?></td>
          <td data-label="Status">
            <span class="badge <?= $email['is_active'] ? 'badge-success' : 'badge-inactive' ?>">
              <?= $email['is_active'] ? 'Active' : 'Inactive' ?>
            </span>
          </td>
          <td data-label="Default">
            <?= $email['is_default'] ? '<span class="badge badge-primary">Default</span>' : '' ?>
          </td>
          <td data-label="Actions">
            <button
              class="btn btn-secondary btn-sm js-template-edit"
              data-id="<?= (int)$email['id'] ?>"
              data-email="<?= h($email['email']) ?>"
              data-label="<?= h($email['label']) ?>"
              data-cc="<?= h($email['cc_addresses'] ?? '') ?>"
              data-subject="<?= h($email['subject_template']) ?>"
              data-body="<?= h($email['body_template']) ?>"
              data-active="<?= $email['is_active'] ?>"
              data-default="<?= $email['is_default'] ?>"
              data-sort="<?= h($email['sort_order']) ?>"
            >Edit</button>

            <button
              class="btn btn-info btn-sm js-template-preview"
              data-subject="<?= h($email['subject_template']) ?>"
              data-body="<?= h($email['body_template']) ?>"
            >Preview</button>

            <form method="post" action="/admin/email_templates" style="display:inline-block;" class="delete-template-form">
              <?=csrf_field()?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$email['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<!-- Add/Edit Modal -->
<div id="templateModal" class="modal">
  <div class="modal-content modal-large">
    <div class="modal-header">
      <h2 id="modalTitle">Add Purchasing Email</h2>
      <button class="close-button" type="button" aria-label="Close modal">&times;</button>
    </div>
    <div class="modal-body">
      <form method="post" action="/admin/email_templates" id="templateForm">
        <?=csrf_field()?>
        <input type="hidden" name="action" id="formAction" value="add">
        <input type="hidden" name="id" id="templateId">

        <div class="form-row">
          <div class="form-group">
            <label>Email Address <span class="required">*</span>
              <input type="email" name="email" id="templateEmail" required>
            </label>
          </div>
          <div class="form-group">
            <label>Display Label <span class="required">*</span>
              <input type="text" name="label" id="templateLabel" required>
            </label>
          </div>
        </div>

        <div class="form-group">
          <label>CC Addresses (comma-separated)
            <input type="text" name="cc_addresses" id="templateCC" placeholder="email1@example.com, email2@example.com">
          </label>
          <small>Optional. Multiple addresses separated by commas.</small>
        </div>

        <div class="form-group">
          <label>Subject Template <span class="required">*</span>
            <input type="text" name="subject_template" id="templateSubject" required>
          </label>
          <small>Use variables like {{ORDER_NUMBER}}, {{STORE_NAME}}, etc.</small>
        </div>

        <div class="form-group">
          <label>Body Template <span class="required">*</span>
            <textarea name="body_template" id="templateBody" rows="15" required></textarea>
          </label>
          <small>Use {{ITEMS_LIST}}, {{ORDER_NOTES}}, and other variables</small>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Sort Order
              <input type="number" name="sort_order" id="templateSortOrder" value="0" min="0">
            </label>
            <small>Lower numbers appear first</small>
          </div>
          <div class="form-group checkbox-group">
            <label>
              <input type="checkbox" name="is_active" id="templateActive" checked> Active
            </label>
            <label>
              <input type="checkbox" name="is_default" id="templateDefault"> Set as Default
            </label>
          </div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="if(window.ModalManager) ModalManager.hide(document.getElementById('templateModal')); else document.getElementById('templateModal').style.display='none';">Cancel</button>
      <button type="submit" class="btn btn-primary" onclick="document.getElementById('templateForm').submit()">Save Template</button>
    </div>
  </div>
</div>

<!-- Preview Modal -->
<div id="previewModal" class="modal">
  <div class="modal-content modal-large">
    <div class="modal-header">
      <h2>Email Preview (with sample data)</h2>
      <button class="close-button" type="button" aria-label="Close modal">&times;</button>
    </div>
    <div class="modal-body">
      <div class="preview-section">
        <h3>Subject:</h3>
        <div id="previewSubject" class="preview-box"></div>
      </div>
      <div class="preview-section">
        <h3>Body:</h3>
        <pre id="previewBody" class="preview-box"></pre>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="if(window.ModalManager) ModalManager.hide(document.getElementById('previewModal')); else document.getElementById('previewModal').style.display='none';">Close</button>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const templateModal = document.getElementById('templateModal');
  const previewModal = document.getElementById('previewModal');

  // Add template button
  document.getElementById('addTemplateBtn')?.addEventListener('click', () => {
    document.getElementById('modalTitle').textContent = 'Add Purchasing Email';
    document.getElementById('formAction').value = 'add';
    document.getElementById('templateForm').reset();
    document.getElementById('templateId').value = '';
    document.getElementById('templateActive').checked = true;
    if (window.ModalManager) {
      ModalManager.show(templateModal);
    } else {
      templateModal.style.display = 'block';
    }
  });

  // Edit buttons
  document.querySelectorAll('.js-template-edit').forEach(btn => {
    btn.addEventListener('click', () => {
      document.getElementById('modalTitle').textContent = 'Edit Purchasing Email';
      document.getElementById('formAction').value = 'edit';
      document.getElementById('templateId').value = btn.dataset.id;
      document.getElementById('templateEmail').value = btn.dataset.email;
      document.getElementById('templateLabel').value = btn.dataset.label;
      document.getElementById('templateCC').value = btn.dataset.cc || '';
      document.getElementById('templateSubject').value = btn.dataset.subject;
      document.getElementById('templateBody').value = btn.dataset.body;
      document.getElementById('templateSortOrder').value = btn.dataset.sort;
      document.getElementById('templateActive').checked = btn.dataset.active === '1';
      document.getElementById('templateDefault').checked = btn.dataset.default === '1';
      if (window.ModalManager) {
        ModalManager.show(templateModal);
      } else {
        templateModal.style.display = 'block';
      }
    });
  });

  // Preview buttons
  document.querySelectorAll('.js-template-preview').forEach(btn => {
    btn.addEventListener('click', () => {
      const subject = btn.dataset.subject;
      const body = btn.dataset.body;

      // Sample data for preview
      const sampleData = {
        '{{ORDER_NUMBER}}': 'ORD-12345678ABCD',
        '{{STORE_NAME}}': 'Sample Store',
        '{{REGION}}': 'North Region',
        '{{DATE}}': new Date().toISOString().split('T')[0],
        '{{DATE_TIME}}': new Date().toLocaleString(),
        '{{USER_NAME}}': 'John Doe',
        '{{USER_EMAIL}}': 'john.doe@example.com',
        '{{TOTAL_ITEMS}}': '5',
        '{{ITEMS_LIST}}': 'ITEMS:\n------\n\nSKU: PROD-001\nProduct: Sample Product\nQuantity: 2\n\nSKU: PROD-002\nProduct: Another Product\nQuantity: 3',
        '{{ORDER_NOTES}}': '========================\nORDER NOTES:\nPlease deliver before Friday\n'
      };

      let previewSubject = subject;
      let previewBody = body;

      Object.entries(sampleData).forEach(([key, value]) => {
        const escapedKey = key.replace(/[{}]/g, '\\$&');
        previewSubject = previewSubject.replace(new RegExp(escapedKey, 'g'), value);
        previewBody = previewBody.replace(new RegExp(escapedKey, 'g'), value);
      });

      document.getElementById('previewSubject').textContent = previewSubject;
      document.getElementById('previewBody').textContent = previewBody;
      if (window.ModalManager) {
        ModalManager.show(previewModal);
      } else {
        previewModal.style.display = 'block';
      }
    });
  });

  // Close buttons
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
  document.querySelectorAll('.delete-template-form').forEach(form => {
    form.addEventListener('submit', function(e) {
      e.preventDefault();

      QuackNotify.confirm('Delete this purchasing email template? Users who had this selected will need to choose another.', {
        title: 'Delete Template',
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
.template-intro {
  background: var(--surface-2);
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 1.5rem;
  margin-bottom: 2rem;
}

.add-template-section {
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

.badge-primary {
  background: var(--badge-info-bg);
  color: var(--badge-info-text);
}

.required {
  color: red;
}

.modal-large .modal-content {
  max-width: 800px;
}

.preview-section {
  margin-bottom: 1.5rem;
}

.preview-section h3 {
  margin-bottom: 0.5rem;
  font-size: 1rem;
}

.preview-box {
  background: var(--surface-2);
  border: 1px solid var(--border);
  border-radius: 4px;
  padding: 1rem;
  white-space: pre-wrap;
  font-family: monospace;
  font-size: 0.875rem;
}
</style>

<?php require __DIR__ . '/../footer.php'; ?>
