<?php
$title = 'User Management';
$available_regions = $available_regions ?? [];
$main_class = 'container admin-page';
require __DIR__ . '/../header.php';
?>

<?php foreach (flashes() as [$t,$m]) : ?>
  <div class="flash <?=h($t)?>"><?=h($m)?></div>
<?php endforeach; ?>

<h1>User Management</h1>

<h2>Add New User</h2>
<form method="post" action="/admin/users">
  <?=csrf_field()?>
  <input type="hidden" name="action" value="add_user">
  <div class="grid">
    <label>First Name<input type="text" name="first_name" required></label>
    <label>Last Name<input type="text" name="last_name" required></label>
    <label>Username<input type="text" name="username" required></label>
    <label>Email<input type="email" name="email" required></label>
    <label>Password (min 8 chars)<input type="password" name="password" required minlength="8"></label>
    <label>Region
      <select name="region_id" required>
        <option value="">Select a region</option>
        <?php foreach ($available_regions as $r) : ?>
          <option value="<?= (int)$r['id'] ?>"><?= h($r['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Role
      <select name="role" required>
        <option value="user">Field-Tech</option>
        <option value="manager">Field-Tech Lead</option>
        <option value="admin">Admin</option>
      </select>
    </label>
  </div>
  <button type="submit" class="btn btn-primary">Add User</button>
</form>

<h2>Existing Users</h2>
<?php if (empty($users)) : ?>
  <p>No users found.</p>
<?php else : ?>
  <table class="table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Username</th>
        <th>Email</th>
        <th>Region</th>
        <th>Role</th>
        <th>Password Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $user) : ?>
        <tr>
          <td data-label="Name"><?= h($user['display_name']) ?></td>
          <td data-label="Username"><?= h($user['username'] ?? '') ?></td>
          <td data-label="Email"><?= h($user['email']) ?></td>
          <td data-label="Region"><?= h($user['region'] ?? 'No Region') ?></td>
          <td data-label="Role"><?= h($user['role']) ?></td>
          <td data-label="Password Status">
            <?php if ($user['must_reset_password']) : ?>
              <span class="badge badge-warning" title="User must reset password on next login">Reset Required</span>
            <?php else : ?>
              <span class="badge badge-success">Active</span>
            <?php endif; ?>
            <?php if ($user['password_changed_at']) : ?>
              <small style="display:block;color:var(--text-secondary);font-size:0.85em;">Last changed: <?= date('M j, Y', strtotime($user['password_changed_at'])) ?></small>
            <?php endif; ?>
          </td>
          <td data-label="Actions" class="actions-cell">
            <button
              class="btn btn-secondary btn-sm js-user-edit"
              data-user-id="<?= (int)$user['id'] ?>"
              data-email="<?= h($user['email']) ?>"
              data-first-name="<?= h($user['first_name'] ?? '') ?>"
              data-last-name="<?= h($user['last_name'] ?? '') ?>"
              data-username="<?= h($user['username'] ?? '') ?>"
              data-region-id="<?= (int)$user['region_id'] ?>"
              data-role="<?= h($user['role']) ?>"
            >Edit</button>
            <button
              class="btn btn-warning btn-sm js-user-reset"
              data-user-id="<?= (int)$user['id'] ?>"
              data-user-display="<?= h($user['display_name']) ?>"
            >Reset Password</button>
            <form method="post" action="/admin/users" style="display:inline-block;" class="delete-user-form">
              <?=csrf_field()?>
              <input type="hidden" name="action" value="delete_user">
              <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<!-- Edit User Modal -->
<div id="editUserModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h2>Edit User</h2>
      <button class="close-button" type="button" aria-label="Close modal">&times;</button>
    </div>
    <div class="modal-body">
      <form method="post" action="/admin/users">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="edit_user">
        <input type="hidden" name="user_id" id="editUserId">

        <div class="form-group">
          <label>Email<input type="email" name="email" id="editUserEmail" required></label>
        </div>

        <div class="form-group">
          <label>First Name<input type="text" name="first_name" id="editUserFirstName"></label>
        </div>

        <div class="form-group">
          <label>Last Name<input type="text" name="last_name" id="editUserLastName"></label>
        </div>

        <div class="form-group">
          <label>Username<input type="text" name="username" id="editUserUsername"></label>
        </div>

        <div class="form-group">
          <label>Region
            <select name="region_id" id="editUserRegion" required>
              <option value="">Select a region</option>
              <?php foreach ($available_regions as $r) : ?>
                <option value="<?= (int)$r['id'] ?>"><?= h($r['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>

        <div class="form-group">
          <label>Role
            <select name="role" id="editUserRole" required>
              <option value="user">Field-Tech</option>
              <option value="manager">Field-Tech Lead</option>
              <option value="admin">Admin</option>
            </select>
          </label>
        </div>

        <div class="form-group">
          <label>New Password (leave blank to keep current)<input type="password" name="password" minlength="8"></label>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="ModalManager.hide(document.getElementById('editUserModal'))">Cancel</button>
      <button type="submit" class="btn btn-primary" onclick="document.querySelector('#editUserModal form').submit()">Save Changes</button>
    </div>
  </div>
</div>

<!-- Password Reset Modal -->
<div id="passwordResetModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h2>Reset User Password</h2>
      <button class="close-button" type="button" aria-label="Close modal">&times;</button>
    </div>
    <div class="modal-body">
      <p>Choose how you want to reset the password for <strong id="resetUserEmail"></strong>:</p>

      <div class="password-reset-options">
        <div class="reset-option">
          <h3>Option 1: Force Password Reset (Recommended)</h3>
          <p>User will be required to change their password on next login. Optionally send them an email with a reset link.</p>
          <form method="post" action="/admin/users" id="forceResetForm">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="force_password_reset">
            <input type="hidden" name="user_id" id="forceResetUserId">
            <div class="form-group">
              <label>
                <input type="checkbox" name="send_email" value="1" checked>
                Send email notification with reset link
              </label>
            </div>
            <button type="submit" class="btn btn-primary">Force Password Reset</button>
          </form>
        </div>

        <hr style="margin: 20px 0; border: none; border-top: 1px solid var(--border);">

        <div class="reset-option">
          <h3>Option 2: Generate Temporary Password</h3>
          <p>Generate a random temporary password that you can give to the user. User must change it on first login.</p>
          <form method="post" action="/admin/users" id="tempPasswordForm" class="temp-password-form">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="generate_temp_password">
            <input type="hidden" name="user_id" id="tempPasswordUserId">
            <button type="submit" class="btn btn-warning">Generate Temporary Password</button>
          </form>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="ModalManager.hide(document.getElementById('passwordResetModal'))">Cancel</button>
    </div>
  </div>
</div>

<style>
  .password-reset-options {
    margin: 20px 0;
  }

  .reset-option {
    padding: 15px;
    background: var(--surface-2);
    border-radius: 8px;
    margin-bottom: 15px;
  }

  .reset-option h3 {
    margin-top: 0;
    font-size: 16px;
    color: var(--text-primary);
  }

  .reset-option p {
    color: var(--text-secondary);
    font-size: 14px;
    margin-bottom: 15px;
  }

  .badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.85em;
    font-weight: 600;
  }

  .badge-success {
    background-color: var(--badge-in-bg);
    color: var(--badge-in-text);
  }

  .badge-warning {
    background-color: var(--badge-low-bg);
    color: var(--badge-low-text);
  }

  .actions-cell {
    white-space: nowrap;
  }

  .actions-cell .btn {
    margin: 2px;
  }
</style>

<script>
  const editModal = document.getElementById('editUserModal');
  const editCloseButton = editModal.querySelector('.close-button');

  function openEditModal(id, email, firstName, lastName, username, regionId, role) {
    document.getElementById('editUserId').value = id;
    document.getElementById('editUserEmail').value = email;
    document.getElementById('editUserFirstName').value = firstName;
    document.getElementById('editUserLastName').value = lastName;
    document.getElementById('editUserUsername').value = username;
    document.getElementById('editUserRegion').value = regionId;
    document.getElementById('editUserRole').value = role;

    // Use ModalManager if available, fallback to direct display
    if (window.ModalManager) {
      ModalManager.show(editModal);
    } else {
      editModal.style.display = 'block';
    }
  }

  // The ModalManager will handle close events, but keep fallback for compatibility
  editCloseButton.addEventListener('click', () => {
    if (window.ModalManager) {
      ModalManager.hide(editModal);
    } else {
      editModal.style.display = 'none';
    }
  });

  window.addEventListener('click', (event) => {
    if (event.target == editModal) {
      if (window.ModalManager) {
        ModalManager.hide(editModal);
      } else {
        editModal.style.display = 'none';
      }
    }
  });

  // Password Reset Modal
  const passwordResetModal = document.getElementById('passwordResetModal');
  const passwordResetCloseButton = passwordResetModal.querySelector('.close-button');

  function openPasswordResetModal(userId, email) {
    document.getElementById('resetUserEmail').textContent = email;
    document.getElementById('forceResetUserId').value = userId;
    document.getElementById('tempPasswordUserId').value = userId;

    // Use ModalManager if available, fallback to direct display
    if (window.ModalManager) {
      ModalManager.show(passwordResetModal);
    } else {
      passwordResetModal.style.display = 'block';
    }
  }

  passwordResetCloseButton.addEventListener('click', () => {
    if (window.ModalManager) {
      ModalManager.hide(passwordResetModal);
    } else {
      passwordResetModal.style.display = 'none';
    }
  });

  window.addEventListener('click', (event) => {
    if (event.target == passwordResetModal) {
      if (window.ModalManager) {
        ModalManager.hide(passwordResetModal);
      } else {
        passwordResetModal.style.display = 'none';
      }
    }
  });

  // Handle user delete confirmations
  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.js-user-edit').forEach(button => {
      button.addEventListener('click', () => {
        openEditModal(
          button.dataset.userId,
          button.dataset.email || '',
          button.dataset.firstName || '',
          button.dataset.lastName || '',
          button.dataset.username || '',
          button.dataset.regionId || '',
          button.dataset.role || ''
        );
      });
    });

    document.querySelectorAll('.js-user-reset').forEach(button => {
      button.addEventListener('click', () => {
        openPasswordResetModal(
          button.dataset.userId,
          button.dataset.userDisplay || ''
        );
      });
    });

    document.querySelectorAll('.delete-user-form').forEach(form => {
      form.addEventListener('submit', function(e) {
        e.preventDefault();

        QuackNotify.confirm('Are you sure you want to delete this user?', {
          title: 'Delete User',
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

    // Handle temporary password form confirmation
    document.querySelectorAll('.temp-password-form').forEach(form => {
      form.addEventListener('submit', function(e) {
        e.preventDefault();

        QuackNotify.confirm('Generate a temporary password? You will need to securely share it with the user.', {
          title: 'Generate Temporary Password',
          confirmText: 'Generate',
          cancelText: 'Cancel',
          confirmStyle: 'warning'
        }).then(confirmed => {
          if (confirmed) {
            this.submit();
          }
        });
      });
    });
  });
</script>

<?php require __DIR__ . '/../footer.php'; ?>
