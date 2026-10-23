<?php
$title = 'Admin Dashboard';
$main_class = 'admin-main';
$tab = $tab ?? 'dashboard';
$canViewLogs = $canViewLogs ?? has_permission('admin.logs.view');
$canViewFiles = $canViewFiles ?? has_permission('admin.files.manage');
$canViewSessions = $canViewSessions ?? has_permission('admin.sessions.manage');
$total_users = $total_users ?? 0;
$total_stores = $total_stores ?? 0;
$total_regions = $total_regions ?? 0;
$available_regions = $available_regions ?? [];
$users = $users ?? [];
$users_page = $users_page ?? 1;
$users_pages = $users_pages ?? 1;
$rolesTablesReady = $rolesTablesReady ?? false;
$permissionDefinitions = $permissionDefinitions ?? [];
$stores = $stores ?? [];
$stores_page = $stores_page ?? 1;
$stores_pages = $stores_pages ?? 1;
$regions = $regions ?? [];
$regions_page = $regions_page ?? 1;
$regions_pages = $regions_pages ?? 1;
$systemInfo = $systemInfo ?? [];
$sessionStats = $sessionStats ?? [];
require __DIR__ . '/header.php';
$adminAction = '/admin.php';
$canManageEmailTemplates = $canManageEmailTemplates ?? false;
$emails = $emails ?? [];
?>


<?php foreach (flashes() as [$t, $m]) : ?>
  <div class="flash <?= h($t) ?>"><?= h($m) ?></div>
<?php endforeach; ?>

<div class="admin-dashboard">
  <div class="stock-page-header">
  </div>

  <div class="stock-layout" id="adminLayout" data-filters-open="true">
    <aside class="filters-sidebar" id="adminSidebar">
      <div class="filters-header">
        <h2>Admin Sections</h2>
        <button class="btn-link" type="button" id="adminCollapse">Collapse</button>
      </div>

      <div class="filters-group">
        <div class="admin-nav-section-label">Manage</div>
        <button class="admin-nav-btn <?= $tab === 'dashboard' ? 'active' : '' ?>" onclick="switchTab(event, 'dashboard')" data-admin-tab="dashboard">
          Overview
        </button>
        <button class="admin-nav-btn <?= $tab === 'users' ? 'active' : '' ?>" onclick="switchTab(event, 'users')" data-admin-tab="users">
          Users &amp; Passwords
        </button>
        <button class="admin-nav-btn <?= $tab === 'roles' ? 'active' : '' ?>" onclick="switchTab(event, 'roles')" data-admin-tab="roles">
          Roles &amp; Permissions
        </button>
        <button class="admin-nav-btn <?= $tab === 'stores' ? 'active' : '' ?>" onclick="switchTab(event, 'stores')" data-admin-tab="stores">
          Stores
        </button>
        <button class="admin-nav-btn <?= $tab === 'regions' ? 'active' : '' ?>" onclick="switchTab(event, 'regions')" data-admin-tab="regions">
          Regions
        </button>
        <button class="admin-nav-btn <?= $tab === 'email' ? 'active' : '' ?>" onclick="switchTab(event, 'email')" data-admin-tab="email">
          Email Templates
        </button>
        <button class="admin-nav-btn <?= $tab === 'settings' ? 'active' : '' ?>" onclick="switchTab(event, 'settings')" data-admin-tab="settings">
          Settings
        </button>

        <div class="admin-nav-section-label">Product Catalog</div>
        <a href="/admin/categories" class="admin-nav-btn admin-nav-link">Categories</a>
        <a href="/admin/manufacturers" class="admin-nav-btn admin-nav-link">Manufacturers</a>

        <div class="admin-nav-section-label">System</div>
        <?php if ($canViewLogs) : ?>
        <button class="admin-nav-btn <?= $tab === 'logs' ? 'active' : '' ?>" onclick="switchTab(event, 'logs')" data-admin-tab="logs">
          Logs
        </button>
        <?php endif; ?>
        <?php if ($canViewFiles) : ?>
        <button class="admin-nav-btn <?= $tab === 'files' ? 'active' : '' ?>" onclick="switchTab(event, 'files')" data-admin-tab="files">
          Files
        </button>
        <?php endif; ?>
        <?php if ($canViewSessions) : ?>
        <button class="admin-nav-btn <?= $tab === 'sessions' ? 'active' : '' ?>" onclick="switchTab(event, 'sessions')" data-admin-tab="sessions">
          Sessions
        </button>
        <?php endif; ?>
        <a href="/admin/stock_settings" class="admin-nav-btn admin-nav-link">Stock Settings</a>
        <a href="/admin/api_keys" class="admin-nav-btn admin-nav-link">API Keys</a>
        <a href="/admin/migrations" class="admin-nav-btn admin-nav-link">Migrations</a>
      </div>

      <div class="filters-actions">
        <button class="btn btn-secondary" type="button" onclick="switchTab(event, 'dashboard')" data-admin-tab="dashboard">Jump to Overview</button>
        <button class="btn btn-outline" type="button" onclick="switchTab(event, 'settings')" data-admin-tab="settings">System Settings</button>
      </div>
    </aside>

    <div class="filters-backdrop" id="adminBackdrop"></div>

    <section class="stock-main">
      <div class="stock-toolbar">
        <button type="button" class="btn btn-secondary" id="adminNavToggle">Sections</button>
        <div class="stock-search">
          <label for="adminSearch" class="sr-only">Search admin sections</label>
          <input type="search" id="adminSearch" placeholder="Search section titles" oninput="filterAdminSections(this.value)">
        </div>
        <div class="stock-sort">
          <select id="adminQuickJump" onchange="jumpToAdminSection(this.value)">
            <option value="">Quick jump…</option>
            <optgroup label="Manage">
              <option value="tab:dashboard">Overview</option>
              <option value="tab:users">Users &amp; Passwords</option>
              <option value="tab:roles">Roles &amp; Permissions</option>
              <option value="tab:stores">Stores</option>
              <option value="tab:regions">Regions</option>
              <option value="tab:email">Email Templates</option>
              <option value="tab:settings">Settings</option>
            </optgroup>
            <optgroup label="Product Catalog">
              <option value="link:/admin/categories">Categories</option>
              <option value="link:/admin/manufacturers">Manufacturers</option>
            </optgroup>
            <optgroup label="System">
              <option value="tab:logs">Logs</option>
              <option value="tab:files">Files</option>
              <option value="tab:sessions">Sessions</option>
              <option value="link:/admin/stock_settings">Stock Settings</option>
              <option value="link:/admin/api_keys">API Keys</option>
              <option value="link:/admin/migrations">Migrations</option>
            </optgroup>
          </select>
        </div>
      </div>

      <!-- Tab Content -->

  <!-- Dashboard Tab -->
  <div id="dashboard" class="admin-tab-content <?= $tab === 'dashboard' ? 'active' : '' ?>">
    <div class="admin-header">
      <div>
        <h1>Admin Dashboard</h1>
      </div>
      <div class="page-actions">
        <button class="btn btn-primary" type="button" onclick="switchTab(event, 'users')" data-admin-tab="users">Add User</button>
        <button class="btn btn-secondary" type="button" onclick="switchTab(event, 'stores')" data-admin-tab="stores">Manage Stores</button>
        <button class="btn btn-secondary" type="button" onclick="switchTab(event, 'settings')" data-admin-tab="settings">Settings</button>
        <a href="/admin/api_keys" class="btn btn-secondary">API Keys</a>
      </div>
    </div>

    <div class="admin-metrics">
      <div class="admin-metric-card">
        <div class="admin-metric-label">Total Users</div>
        <div class="admin-metric-value"><?= (int)$total_users ?></div>
        <div class="admin-metric-meta">Active accounts in the system.</div>
      </div>
      <div class="admin-metric-card">
        <div class="admin-metric-label">Stores</div>
        <div class="admin-metric-value"><?= (int)$total_stores ?></div>
        <div class="admin-metric-meta">Service locations tracked.</div>
      </div>
      <div class="admin-metric-card">
        <div class="admin-metric-label">Regions</div>
        <div class="admin-metric-value"><?= (int)$total_regions ?></div>
        <div class="admin-metric-meta">Active regional groups.</div>
      </div>
      <div class="admin-metric-card">
        <div class="admin-metric-label">Active Sessions</div>
        <div class="admin-metric-value"><?= (int)($sessionStats['active_sessions'] ?? 0) ?></div>
        <div class="admin-metric-meta">Current signed-in users.</div>
      </div>
    </div>

    <div class="dashboard-grid">
      <section class="dashboard-card">
        <h3>Regional Stock Overview</h3>
        <?php if (empty($regional_stock_overview)) : ?>
          <div class="empty-state">
            <p>No regional stock data available.</p>
          </div>
        <?php else : ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>Region</th>
                <th>Total Quantity</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($regional_stock_overview as $region_data) : ?>
                <tr>
                  <td><?= h($region_data['region']) ?></td>
                  <td><?= (int)$region_data['total_quantity'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <div style="margin-top: 15px;">
            <a href="/region-storage" class="btn btn-primary">Manage Regional Storage</a>
          </div>
        <?php endif; ?>
      </section>

      <section class="dashboard-card">
        <h3>User Management</h3>
        <p class="muted-text" style="margin-bottom: 15px;">Add, edit, and manage user accounts, roles, and permissions.</p>
        <div class="dashboard-actions">
          <button class="btn btn-secondary" type="button" onclick="switchTab(event, 'users')" data-admin-tab="users">Users &amp; Passwords</button>
          <button class="btn btn-secondary" type="button" onclick="switchTab(event, 'roles')" data-admin-tab="roles">Roles &amp; Permissions</button>
          <button class="btn btn-secondary" type="button" onclick="switchTab(event, 'stores')" data-admin-tab="stores">Stores</button>
          <button class="btn btn-secondary" type="button" onclick="switchTab(event, 'regions')" data-admin-tab="regions">Regions</button>
        </div>
      </section>
    </div>

    <div class="dashboard-grid">
      <section class="dashboard-card">
        <h3>Product Catalog</h3>
        <p class="muted-text" style="margin-bottom: 15px;">Manage product categories and manufacturers used across the catalog.</p>
        <div class="dashboard-actions">
          <a href="/admin/categories" class="btn btn-secondary">Categories</a>
          <a href="/admin/manufacturers" class="btn btn-secondary">Manufacturers</a>
        </div>
      </section>

      <section class="dashboard-card">
        <h3>Email Administration</h3>
        <p class="muted-text" style="margin-bottom: 15px;">Update templates and configuration used by notifications and ordering flows.</p>
        <div class="dashboard-actions">
          <button class="btn btn-secondary" type="button" onclick="switchTab(event, 'email')" data-admin-tab="email">Email Templates</button>
          <button class="btn btn-secondary" type="button" onclick="switchTab(event, 'settings')" data-admin-tab="settings">Email Settings</button>
        </div>
      </section>
    </div>

    <div class="dashboard-grid">
      <section class="dashboard-card">
        <h3>System Tools</h3>
        <p class="muted-text" style="margin-bottom: 15px;">Monitor logs, manage sessions, clean up files, and configure system settings.</p>
        <div class="dashboard-actions">
          <?php if ($canViewLogs) : ?>
          <button class="btn btn-secondary" type="button" onclick="switchTab(event, 'logs')" data-admin-tab="logs">Logs</button>
          <?php endif; ?>
          <?php if ($canViewFiles) : ?>
          <button class="btn btn-secondary" type="button" onclick="switchTab(event, 'files')" data-admin-tab="files">Files</button>
          <?php endif; ?>
          <?php if ($canViewSessions) : ?>
          <button class="btn btn-secondary" type="button" onclick="switchTab(event, 'sessions')" data-admin-tab="sessions">Sessions</button>
          <?php endif; ?>
          <button class="btn btn-secondary" type="button" onclick="switchTab(event, 'settings')" data-admin-tab="settings">Settings</button>
        </div>
      </section>

      <section class="dashboard-card">
        <h3>Developer &amp; Integrations</h3>
        <p class="muted-text" style="margin-bottom: 15px;">Manage API keys, run database migrations, and configure stock operation limits.</p>
        <div class="dashboard-actions">
          <a href="/admin/api_keys" class="btn btn-secondary">API Keys</a>
          <a href="/admin/migrations" class="btn btn-secondary">Migrations</a>
          <a href="/admin/stock_settings" class="btn btn-secondary">Stock Settings</a>
        </div>
      </section>
    </div>
  </div>

  <!-- Users Tab -->
  <div id="users" class="admin-tab-content <?= $tab === 'users' ? 'active' : '' ?>">
    <!-- Add User Form -->
    <div class="form-section">
      <h3>Add New User</h3>
      <form method="post" action="<?= $adminAction ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_user">
        <input type="hidden" name="redirect_to" value="/admin.php?tab=users">

        <div class="form-grid">
          <div class="form-group">
            <label>First Name</label>
            <input type="text" name="first_name" required>
          </div>
          <div class="form-group">
            <label>Last Name</label>
            <input type="text" name="last_name" required>
          </div>
          <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" required>
          </div>
          <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" required>
          </div>
          <div class="form-group">
            <label>Password (min 8 chars)</label>
            <input type="password" name="password" required minlength="8">
          </div>
          <div class="form-group">
            <label>Region</label>
            <select name="region_id" required>
              <option value="">Select a region</option>
              <?php foreach ($available_regions as $r) : ?>
                <option value="<?= (int)$r['id'] ?>"><?= h($r['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Role</label>
            <select name="role" required>
              <option value="user">User</option>
              <option value="admin">Admin</option>
            </select>
          </div>
        </div>

        <button type="submit" class="btn btn-primary">Add User</button>
      </form>
    </div>

    <!-- Users Table -->
    <div class="form-section">
      <h3>Existing Users (<?= count($users) ?> of <?= (int)$total_users ?>)</h3>
      <?php if (empty($users)) : ?>
        <div class="empty-state">
          <p>No users found.</p>
        </div>
      <?php else : ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Username</th>
              <th>Email</th>
              <th>Region</th>
              <th>Role</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $user) : ?>
              <tr>
                <td><?= h($user['display_name']) ?></td>
                <td><?= h($user['username'] ?? '') ?></td>
                <td><?= h($user['email']) ?></td>
                <td><?= h($user['region'] ?? 'No Region') ?></td>
                <td><?= h($user['role']) ?></td>
                <td class="actions">
                  <button
                    class="btn btn-sm btn-secondary js-admin-user-edit"
                    data-user-id="<?= (int)$user['id'] ?>"
                    data-email="<?= h($user['email']) ?>"
                    data-first-name="<?= h($user['first_name'] ?? '') ?>"
                    data-last-name="<?= h($user['last_name'] ?? '') ?>"
                    data-username="<?= h($user['username'] ?? '') ?>"
                    data-region-id="<?= (int)$user['region_id'] ?>"
                    data-role="<?= h($user['role']) ?>"
                  >Edit</button>
                  <form method="post" action="<?= $adminAction ?>" style="display: inline;" class="delete-user-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="redirect_to" value="/admin.php?tab=users&users_page=<?= (int)$users_page ?>">
                    <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                  </form>
                  <form method="post" action="<?= $adminAction ?>" style="display: inline;" class="reset-user-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="force_password_reset">
                    <input type="hidden" name="redirect_to" value="/admin.php?tab=users&users_page=<?= (int)$users_page ?>">
                    <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                    <label class="inline-checkbox">
                      <input type="checkbox" name="send_email" value="1">
                      Email reset
                    </label>
                    <button type="submit" class="btn btn-sm btn-outline">Force Reset</button>
                  </form>
                  <form method="post" action="<?= $adminAction ?>" style="display: inline;" class="temp-password-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="generate_temp_password">
                    <input type="hidden" name="redirect_to" value="/admin.php?tab=users&users_page=<?= (int)$users_page ?>">
                    <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline">Temp Password</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <!-- Pagination -->
          <?php if ($users_pages > 1) : ?>
          <div class="pagination">
                <?php if ($users_page > 1) : ?>
              <a href="?tab=users&users_page=1">First</a>
              <a href="?tab=users&users_page=<?= $users_page - 1 ?>">Previous</a>
                <?php endif; ?>

            <span>Page <?= $users_page ?> of <?= $users_pages ?></span>

                <?php if ($users_page < $users_pages) : ?>
              <a href="?tab=users&users_page=<?= $users_page + 1 ?>">Next</a>
              <a href="?tab=users&users_page=<?= $users_pages ?>">Last</a>
                <?php endif; ?>
          </div>
          <?php endif; ?>
      <?php endif; ?>
  </div>
</div>

  <!-- Roles Tab -->
  <div id="roles" class="admin-tab-content <?= $tab === 'roles' ? 'active' : '' ?>">
    <?php if (!$rolesTablesReady) : ?>
      <div class="form-section">
        <h3>Roles unavailable</h3>
        <p>Role management tables are missing. Please run the latest database migrations before using this section.</p>
      </div>
    <?php else : ?>
      <div class="form-section">
        <h3>Create Role</h3>
        <form method="post" action="<?= $adminAction ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create_role">
          <input type="hidden" name="redirect_to" value="/admin.php?tab=roles">

          <div class="form-grid">
            <div class="form-group">
              <label for="newRoleName">Role Name</label>
              <input type="text" id="newRoleName" name="name" required>
            </div>
            <div class="form-group">
              <label for="newRoleSlug">Slug</label>
              <input type="text" id="newRoleSlug" name="slug" required placeholder="e.g., field_tech_support">
              <small class="form-help">Lowercase, letters/numbers/underscores only.</small>
            </div>
            <div class="form-group">
              <label for="newRoleDescription">Description</label>
              <input type="text" id="newRoleDescription" name="description" placeholder="Optional role details">
            </div>
          </div>

          <div class="permission-groups">
            <?php foreach ($permissionDefinitions as $groupName => $groupPermissions) : ?>
              <div class="permission-group">
                <h4><?= h($groupName) ?></h4>
                <?php foreach ($groupPermissions as $permKey => $label) : ?>
                  <label class="permission-checkbox">
                    <input type="checkbox" name="permissions[]" value="<?= h($permKey) ?>">
                    <?= h($label) ?>
                  </label>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </div>

          <button type="submit" class="btn btn-primary" style="margin-top: 15px;">Create Role</button>
        </form>
      </div>

      <div class="form-section">
        <h3>Roles & Permissions</h3>
        <?php if (empty($rolesList)) : ?>
          <p>No roles defined yet.</p>
        <?php else : ?>
            <?php foreach ($rolesList as $role) : ?>
            <details class="role-card" <?= !empty($rolePermissionsMap[$role['id']] ?? []) ? 'open' : '' ?>>
              <summary><?= h($role['name']) ?> <small>(<?= h($role['slug']) ?>)</small></summary>
              <div class="meta">
                <?= h($role['description'] ?? 'No description') ?> • Assigned Users: <?= (int)($role['user_count'] ?? 0) ?>
                <?= (int)$role['is_protected'] === 1 ? '• Protected' : '' ?>
              </div>
              <form method="post" action="<?= $adminAction ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_role_permissions">
                <input type="hidden" name="redirect_to" value="/admin.php?tab=roles">
                <input type="hidden" name="role_id" value="<?= (int)$role['id'] ?>">
                <div class="permission-groups">
                  <?php foreach ($permissionDefinitions as $groupName => $groupPermissions) : ?>
                    <div class="permission-group">
                      <h4><?= h($groupName) ?></h4>
                        <?php foreach ($groupPermissions as $permKey => $label) : ?>
                            <?php $isChecked = in_array($permKey, $rolePermissionsMap[$role['id']] ?? [], true); ?>
                        <label class="permission-checkbox">
                          <input type="checkbox" name="permissions[]" value="<?= h($permKey) ?>" <?= $isChecked ? 'checked' : '' ?>>
                            <?= h($label) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
                <div class="role-actions">
                  <button type="submit" class="btn btn-primary">Save Permissions</button>
                </div>
              </form>
                <?php if ((int)$role['is_protected'] !== 1) : ?>
                <form method="post" action="<?= $adminAction ?>" data-confirm="Delete this role?" data-confirm-title="Delete Role" data-confirm-confirm-text="Delete" data-confirm-style="danger" style="display:inline;">
                    <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_role">
                  <input type="hidden" name="redirect_to" value="/admin.php?tab=roles">
                  <input type="hidden" name="role_id" value="<?= (int)$role['id'] ?>">
                  <button type="submit" class="btn btn-danger" style="margin-top:8px;">Delete Role</button>
                </form>
                <?php endif; ?>
            </details>
            <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="form-section">
        <h3>User Role Assignments</h3>
        <?php if (empty($userRoleAssignments)) : ?>
          <p>No users found.</p>
        <?php else : ?>
          <form method="post" action="<?= $adminAction ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="assign_user_roles">
            <input type="hidden" name="redirect_to" value="/admin.php?tab=roles">
            <table class="data-table">
              <thead>
                <tr>
                  <th>User</th>
                  <th>Region</th>
                  <th>Legacy Role</th>
                  <th>Assigned Roles</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($userRoleAssignments as $assignment) : ?>
                  <tr>
                    <td data-label="User"><?= h($assignment['display_name']) ?></td>
                    <td data-label="Region"><?= h($assignment['region_name'] ?? 'N/A') ?></td>
                    <td data-label="Legacy Role"><?= h($assignment['legacy_role'] ?? 'user') ?></td>
                    <td data-label="Roles" class="user-role-checkboxes">
                      <input type="hidden" name="user_roles_present[]" value="<?= (int)$assignment['id'] ?>">
                      <?php foreach ($rolesList as $role) : ?>
                            <?php $hasRole = in_array($role['slug'], $assignment['role_slugs'] ?? [], true); ?>
                        <label>
                          <input type="checkbox" name="user_roles[<?= (int)$assignment['id'] ?>][]" value="<?= h($role['slug']) ?>" <?= $hasRole ? 'checked' : '' ?>>
                            <?= h($role['name']) ?>
                        </label>
                      <?php endforeach; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <button type="submit" class="btn btn-primary" style="margin-top: 10px;">Update User Roles</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Stores Tab -->
  <div id="stores" class="admin-tab-content <?= $tab === 'stores' ? 'active' : '' ?>">
    <!-- Add Store Form -->
    <div class="form-section">
      <h3>Add New Store</h3>
      <form method="post" action="<?= $adminAction ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_store">
        <input type="hidden" name="redirect_to" value="/admin.php?tab=stores">

        <div class="form-grid">
          <div class="form-group">
            <label>Store Name</label>
            <input type="text" name="name" required>
          </div>
          <div class="form-group">
            <label>Store Number</label>
            <input type="text" name="store_number">
          </div>
          <div class="form-group">
            <label>Address</label>
            <input type="text" name="address">
          </div>
          <div class="form-group">
            <label>Contact Info</label>
            <input type="text" name="contact_info">
          </div>
        </div>

        <button type="submit" class="btn btn-primary">Add Store</button>
      </form>
    </div>

    <!-- Bulk Import -->
    <div class="form-section">
      <h3>Bulk Import Stores</h3>
      <form method="post" action="<?= $adminAction ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="bulk_import">
        <input type="hidden" name="redirect_to" value="/admin.php?tab=stores">

        <div class="form-grid">
          <div class="form-group">
            <label>Upload CSV File</label>
            <input type="file" name="stores_file" accept=".csv" required>
          </div>
        </div>

        <p style="font-size: 0.9rem; color: var(--color-gray-600);">
          CSV/TSV must have <code>name</code> or <code>Store Name</code>; <code>Store #</code>, <code>address</code>, and <code>contact_info</code> are optional.
        </p>

        <button type="submit" class="btn btn-info">Import Stores</button>
      </form>
    </div>

    <!-- Stores Table -->
    <div class="form-section">
      <h3>Existing Stores (<?= count($stores) ?> of <?= (int)$total_stores ?>)</h3>
      <?php if (empty($stores)) : ?>
        <div class="empty-state">
          <p>No stores found.</p>
        </div>
      <?php else : ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Name</th>
              <th>Store #</th>
              <th>Address</th>
              <th>Contact Info</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($stores as $store) : ?>
              <tr>
                <td><?= (int)$store['id'] ?></td>
                <td><?= h($store['name']) ?></td>
                <td><?= h($store['store_number'] ?? '—') ?></td>
                <td><?= h($store['address'] ?? '') ?></td>
                <td><?= h($store['contact_info'] ?? '') ?></td>
                <td class="actions">
                  <button
                    class="btn btn-sm btn-secondary js-admin-store-edit"
                    data-store-id="<?= (int)$store['id'] ?>"
                    data-store-name="<?= h($store['name']) ?>"
                    data-store-number="<?= h($store['store_number'] ?? '') ?>"
                    data-store-address="<?= h($store['address'] ?? '') ?>"
                    data-store-contact="<?= h($store['contact_info'] ?? '') ?>"
                  >Edit</button>
                  <form method="post" action="<?= $adminAction ?>" style="display: inline;" class="delete-store-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_store">
                    <input type="hidden" name="redirect_to" value="/admin.php?tab=stores&stores_page=<?= (int)$stores_page ?>">
                    <input type="hidden" name="store_id" value="<?= (int)$store['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger" <?= ((int)$store['id'] === 1) ? 'disabled' : '' ?>>Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <!-- Pagination -->
          <?php if ($stores_pages > 1) : ?>
          <div class="pagination">
                <?php if ($stores_page > 1) : ?>
              <a href="?tab=stores&stores_page=1">First</a>
              <a href="?tab=stores&stores_page=<?= $stores_page - 1 ?>">Previous</a>
                <?php endif; ?>

            <span>Page <?= $stores_page ?> of <?= $stores_pages ?></span>

                <?php if ($stores_page < $stores_pages) : ?>
              <a href="?tab=stores&stores_page=<?= $stores_page + 1 ?>">Next</a>
              <a href="?tab=stores&stores_page=<?= $stores_pages ?>">Last</a>
                <?php endif; ?>
          </div>
          <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Regions Tab -->
  <div id="regions" class="admin-tab-content <?= $tab === 'regions' ? 'active' : '' ?>">
    <!-- Add Region Form -->
    <div class="form-section">
      <h3>Add New Region</h3>
      <form method="post" action="<?= $adminAction ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_region">
        <input type="hidden" name="redirect_to" value="/admin.php?tab=regions">

        <div class="form-grid">
          <div class="form-group">
            <label>Region Name</label>
            <input type="text" name="name" required>
          </div>
        </div>

        <button type="submit" class="btn btn-primary">Add Region</button>
      </form>
    </div>

    <!-- Regions Table -->
    <div class="form-section">
      <h3>Existing Regions (<?= count($regions) ?> of <?= (int)$total_regions ?>)</h3>
      <?php if (empty($regions)) : ?>
        <div class="empty-state">
          <p>No regions found.</p>
        </div>
      <?php else : ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Name</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($regions as $region) : ?>
              <tr>
                <td><?= (int)$region['id'] ?></td>
                <td><?= h($region['name']) ?></td>
                <td class="actions">
                  <button
                    class="btn btn-sm btn-secondary js-admin-region-edit"
                    data-region-id="<?= (int)$region['id'] ?>"
                    data-region-name="<?= h($region['name']) ?>"
                  >Edit</button>
                  <form method="post" action="<?= $adminAction ?>" style="display: inline;" class="delete-region-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_region">
                    <input type="hidden" name="redirect_to" value="/admin.php?tab=regions&regions_page=<?= (int)$regions_page ?>">
                    <input type="hidden" name="region_id" value="<?= (int)$region['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <!-- Pagination -->
          <?php if ($regions_pages > 1) : ?>
          <div class="pagination">
                <?php if ($regions_page > 1) : ?>
              <a href="?tab=regions&regions_page=1">First</a>
              <a href="?tab=regions&regions_page=<?= $regions_page - 1 ?>">Previous</a>
                <?php endif; ?>

            <span>Page <?= $regions_page ?> of <?= $regions_pages ?></span>

                <?php if ($regions_page < $regions_pages) : ?>
              <a href="?tab=regions&regions_page=<?= $regions_page + 1 ?>">Next</a>
              <a href="?tab=regions&regions_page=<?= $regions_pages ?>">Last</a>
                <?php endif; ?>
          </div>
          <?php endif; ?>
      <?php endif; ?>
  </div>
</div>

  <!-- Settings Tab -->
  <div id="settings" class="admin-tab-content <?= $tab === 'settings' ? 'active' : '' ?>">
    <?php
      $checkoutValidationMeta = $app_settings['checkout_validation_enabled'] ?? null;
      $checkoutValidationEnabled = is_checkout_validation_enabled();
      $deploymentShipmentApprovalBypassEnabled = ($app_settings['deployment_shipment_approval_bypass_enabled']['setting_value'] ?? '0') === '1';
      $deploymentShipmentApprovalBypassReason = $app_settings['deployment_shipment_approval_bypass_reason']['setting_value'] ?? '';
    ?>
    <div class="form-section">
      <h3>Ordering Limits</h3>
      <p style="margin-top: -5px; color: var(--color-gray-600);">Control the global per-item cap enforced in carts and checkout.</p>
      <form method="post" action="<?= $adminAction ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_settings">

        <div class="form-grid">
          <div class="form-group">
            <label for="maxOrderQuantity" class="required">Max Quantity Per Item</label>
            <input type="number"
                   id="maxOrderQuantity"
                   name="max_order_quantity"
                   min="1"
                   max="100000"
                   step="1"
                   value="<?= h($current_max_order_quantity ?? 100) ?>"
                   required>
            <?php $maxSettingMeta = $app_settings['max_order_quantity'] ?? null; ?>
            <?php if ($maxSettingMeta && !empty($maxSettingMeta['updated_at'])) : ?>
              <p class="form-help">
                Last updated <?= h(date('M j, Y g:i A', strtotime($maxSettingMeta['updated_at']))) ?>
                by <?= h($maxSettingMeta['updated_by_display'] ?? 'system') ?>.
              </p>
            <?php else : ?>
              <p class="form-help">Defaults to the <code>MAX_ORDER_QUANTITY</code> value in <code>.env</code> (currently <?= h($current_max_order_quantity ?? 100) ?>).</p>
            <?php endif; ?>
          </div>
          <div class="form-group">
            <label for="checkoutValidationEnabled" class="required">Checkout Validation</label>
            <select id="checkoutValidationEnabled" name="checkout_validation_enabled" required>
              <option value="1" <?= $checkoutValidationEnabled ? 'selected' : '' ?>>Enabled (stock/status checks enforced)</option>
              <option value="0" <?= $checkoutValidationEnabled ? '' : 'selected' ?>>Disabled (send email without validation)</option>
            </select>
            <p class="form-help" style="margin-bottom:0;">
              Turning this off skips the stock and active-status checks so teams can send a quick email to purchasing.
            </p>
            <?php if ($checkoutValidationMeta && !empty($checkoutValidationMeta['updated_at'])) : ?>
              <p class="form-help">
                Last updated <?= h(date('M j, Y g:i A', strtotime($checkoutValidationMeta['updated_at']))) ?>
                by <?= h($checkoutValidationMeta['updated_by_display'] ?? 'system') ?>.
              </p>
            <?php else : ?>
              <p class="form-help">
                Defaults to <?= $checkoutValidationEnabled ? 'enabled' : 'disabled' ?> based on <code>CHECKOUT_VALIDATION_ENABLED</code> in <code>.env</code>.
              </p>
            <?php endif; ?>
          </div>
          <div class="form-group">
            <label for="deploymentShipmentApprovalBypassEnabled">Deployment Shipment Approval</label>
            <label class="inline-checkbox">
              <input
                type="checkbox"
                id="deploymentShipmentApprovalBypassEnabled"
                name="deployment_shipment_approval_bypass_enabled"
                value="1"
                <?= $deploymentShipmentApprovalBypassEnabled ? 'checked' : '' ?>
              >
              Bypass approval and send requests directly to preparation
            </label>
            <p class="form-help">
              Enable only when the deployment team can request and prepare shipments without a separate approval step.
            </p>
          </div>
          <div class="form-group">
            <label for="deploymentShipmentApprovalBypassReason">Approval Bypass Reason</label>
            <textarea
              id="deploymentShipmentApprovalBypassReason"
              name="deployment_shipment_approval_bypass_reason"
              rows="4"
              maxlength="500"
              <?= $deploymentShipmentApprovalBypassEnabled ? 'required' : '' ?>
              aria-describedby="deploymentShipmentApprovalBypassReasonHelp"
            ><?= h($deploymentShipmentApprovalBypassReason) ?></textarea>
            <p id="deploymentShipmentApprovalBypassReasonHelp" class="form-help">
              Required when approval bypass is enabled. This reason is retained when bypass is disabled and will be copied to future automatic-approval records.
            </p>
          </div>
        </div>

        <button type="submit" class="btn btn-primary">Save Settings</button>
      </form>
    </div>

    <div class="form-section">
      <h3>How it works</h3>
      <ul style="margin:0 0 10px 16px;">
        <li>Limit applies when items are added/updated in cart and when checkout runs.</li>
        <li>Changing the value takes effect immediately without redeploying.</li>
        <li>Technicians still send orders via Outlook mailto links, but the app enforces the cap before generating the email.</li>
      </ul>
      <p style="margin:0; color: var(--color-gray-600);">Future settings can live here, so keep the page bookmarked.</p>
    </div>

    <!-- Stock Operation Settings Link -->
    <div class="form-section">
      <h3>Stock Operation Settings</h3>
      <p style="margin-top: -5px; color: var(--color-gray-600);">Configure validation limits and rate limiting for van stock operations.</p>
      <a href="/admin/stock_settings" class="btn btn-secondary" style="display: inline-block; margin-top: 10px;">
        Manage Stock Settings
      </a>
      <p style="margin-top: 10px; font-size: 0.9em; color: var(--color-gray-600);">
        Control max quantities, note lengths, and rate limiting for stock add/adjust/transfer operations.
      </p>
    </div>

    <!-- Turnstile Configuration -->
    <div class="form-section">
      <h3>Cloudflare Turnstile Configuration</h3>
      <p style="margin-top: -5px; color: var(--color-gray-600);">Manage bot protection for registration, login, and password reset pages</p>

      <form method="post" action="<?= $adminAction ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_turnstile_keys">

        <!-- Enable/Disable Toggle -->
        <div class="form-group" style="margin-bottom: 25px;">
          <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; user-select: none;">
            <input
              type="checkbox"
              name="turnstile_enabled"
              id="turnstileEnabled"
              value="1"
              <?= is_turnstile_enabled() ? 'checked' : '' ?>
              style="width: 20px; height: 20px; cursor: pointer;">
            <span style="font-weight: 600; color: var(--color-gray-700);">Enable Turnstile Bot Protection</span>
          </label>
          <p class="form-help" style="margin-left: 30px;">
            When enabled, users must complete Turnstile verification on registration, login, and password reset pages.
            Disable for development/testing environments. <strong>Recommended: Enabled in production.</strong>
          </p>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label for="turnstileSiteKey" class="required">Turnstile Site Key</label>
            <input
              type="text"
              id="turnstileSiteKey"
              name="turnstile_site_key"
              value="<?= h(get_turnstile_site_key()) ?>"
              required
              placeholder="0x4AAAA..."
              pattern="^0x[a-zA-Z0-9_-]+$"
              title="Site key must start with '0x' followed by alphanumeric characters, hyphens, or underscores">
            <p class="form-help">Public site key used in the registration form widget</p>
          </div>

          <div class="form-group">
            <label for="turnstileSecretKey" class="required">Turnstile Secret Key</label>
            <div style="position: relative;">
              <input
                type="password"
                id="turnstileSecretKey"
                name="turnstile_secret_key"
                value="<?= h(get_turnstile_secret_key()) ?>"
                required
                placeholder="0x4AAAA..."
                pattern="^0x[a-zA-Z0-9_-]+$"
                title="Secret key must start with '0x' followed by alphanumeric characters, hyphens, or underscores"
                style="padding-right: 100px;">
              <button
                type="button"
                onclick="togglePasswordVisibility('turnstileSecretKey', this)"
                style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); padding: 4px 12px; font-size: 0.85rem; background: var(--color-gray-100); color: var(--text-primary); border: 1px solid var(--color-gray-300); border-radius: 4px; cursor: pointer;">
                Show
              </button>
            </div>
            <p class="form-help">Secret key used for server-side verification (keep confidential)</p>
          </div>
        </div>

        <div style="background: var(--color-gray-50); border-left: 4px solid var(--color-primary); padding: 15px; border-radius: 6px; margin-bottom: 20px;">
          <p style="margin: 0 0 10px 0; font-weight: 600; color: var(--color-gray-700);">Get Your Turnstile Keys:</p>
          <ol style="margin: 0 0 0 20px; padding: 0; color: var(--color-gray-600);">
            <li>Visit <a href="https://dash.cloudflare.com" target="_blank" rel="noopener" style="color: var(--text-brand);">Cloudflare Dashboard</a></li>
            <li>Navigate to <strong>Turnstile</strong> in the left sidebar</li>
            <li>Create a new site or use an existing one</li>
            <li>Copy the Site Key and Secret Key</li>
            <li>Paste them in the fields above and save</li>
          </ol>
        </div>

        <button type="submit" class="btn btn-primary">Update Turnstile Keys</button>
      </form>
    </div>

    <script>
      function togglePasswordVisibility(inputId, button) {
        const input = document.getElementById(inputId);
        if (input.type === 'password') {
          input.type = 'text';
          button.textContent = 'Hide';
        } else {
          input.type = 'password';
          button.textContent = 'Show';
        }
      }
    </script>

    <!-- Registration Page Images -->
    <div class="form-section">
      <h3>Registration Page Side Images</h3>
      <p style="margin-top: -5px; color: var(--color-gray-600);">Configure decorative images for the left and right sides of the registration page</p>

      <form method="post" action="<?= $adminAction ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_register_images">

        <div class="form-grid">
          <div class="form-group">
            <label for="registerLeftImage">Left Side Image URL</label>
            <input
              type="text"
              id="registerLeftImage"
              name="register_left_image"
              value="<?= h(get_register_left_image()) ?>"
              placeholder="/assets/register-left.jpg or https://example.com/image.jpg">
            <p class="form-help">URL or path to the image shown on the left side (recommended: 400x600px or larger)</p>
          </div>

          <div class="form-group">
            <label for="registerRightImage">Right Side Image URL</label>
            <input
              type="text"
              id="registerRightImage"
              name="register_right_image"
              value="<?= h(get_register_right_image()) ?>"
              placeholder="/assets/register-right.jpg or https://example.com/image.jpg">
            <p class="form-help">URL or path to the image shown on the right side (recommended: 400x600px or larger)</p>
          </div>
        </div>

        <div style="background: var(--color-gray-50); border-left: 4px solid var(--color-primary); padding: 15px; border-radius: 6px; margin-bottom: 20px;">
          <p style="margin: 0 0 10px 0; font-weight: 600; color: var(--color-gray-700);">Image Guidelines:</p>
          <ul style="margin: 0 0 0 20px; padding: 0; color: var(--color-gray-600);">
            <li>Images will be displayed on screens wider than 1200px</li>
            <li>Recommended size: 400x600px or larger (vertical orientation)</li>
            <li>Supported formats: JPG, PNG, GIF, WebP</li>
            <li>Use absolute URLs (https://...) or relative paths (/assets/...)</li>
            <li>Leave blank to hide the image</li>
            <li>Images are automatically hidden on mobile and tablet devices</li>
          </ul>
        </div>

        <div style="background: var(--color-info); background: rgba(59, 130, 246, 0.1); border-left: 4px solid var(--color-info); padding: 15px; border-radius: 6px; margin-bottom: 20px;">
          <p style="margin: 0; color: var(--color-gray-700);"><strong>Preview:</strong> Visit <a href="/register" target="_blank" style="color: var(--text-brand);">/register</a> to see your changes (may need to refresh)</p>
        </div>

        <button type="submit" class="btn btn-primary">Update Images</button>
      </form>
    </div>

    <!-- Login Page Images -->
    <div class="form-section">
      <h3>Login Page Side Images</h3>
      <p style="margin-top: -5px; color: var(--color-gray-600);">Configure decorative images for the left and right sides of the login page</p>

      <form method="post" action="<?= $adminAction ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_login_images">

        <div class="form-grid">
          <div class="form-group">
            <label for="loginLeftImage">Left Side Image URL</label>
            <input
              type="text"
              id="loginLeftImage"
              name="login_left_image"
              value="<?= h(get_login_left_image()) ?>"
              placeholder="/assets/login-left.jpg or https://example.com/image.jpg">
            <p class="form-help">URL or path to the image shown on the left side (recommended: 400x500px or larger)</p>
          </div>

          <div class="form-group">
            <label for="loginRightImage">Right Side Image URL</label>
            <input
              type="text"
              id="loginRightImage"
              name="login_right_image"
              value="<?= h(get_login_right_image()) ?>"
              placeholder="/assets/login-right.jpg or https://example.com/image.jpg">
            <p class="form-help">URL or path to the image shown on the right side (recommended: 400x500px or larger)</p>
          </div>
        </div>

        <div style="background: var(--color-gray-50); border-left: 4px solid var(--color-primary); padding: 15px; border-radius: 6px; margin-bottom: 20px;">
          <p style="margin: 0 0 10px 0; font-weight: 600; color: var(--color-gray-700);">Image Guidelines:</p>
          <ul style="margin: 0 0 0 20px; padding: 0; color: var(--color-gray-600);">
            <li>Images will be displayed on screens wider than 1200px</li>
            <li>Recommended size: 400x500px or larger (vertical orientation)</li>
            <li>Supported formats: JPG, PNG, GIF, WebP</li>
            <li>Use absolute URLs (https://...) or relative paths (/assets/...)</li>
            <li>Leave blank to hide the image</li>
            <li>Images are automatically hidden on mobile and tablet devices</li>
          </ul>
        </div>

        <div style="background: var(--color-info); background: rgba(59, 130, 246, 0.1); border-left: 4px solid var(--color-info); padding: 15px; border-radius: 6px; margin-bottom: 20px;">
          <p style="margin: 0; color: var(--color-gray-700);"><strong>Preview:</strong> Visit <a href="/login" target="_blank" style="color: var(--text-brand);">/login</a> to see your changes (may need to refresh)</p>
        </div>

        <button type="submit" class="btn btn-primary">Update Images</button>
      </form>
    </div>
  </div>

  <!-- Email Templates Tab -->
  <div id="email" class="admin-tab-content <?= $tab === 'email' ? 'active' : '' ?>">
    <div class="form-section">
      <h3>Purchasing Email Templates</h3>
      <p class="muted-text">Manage purchasing email addresses and templates used during checkout.</p>
      <p class="muted-text" style="margin-top: 10px;">
        Variables: {{ORDER_NUMBER}}, {{STORE_NAME}}, {{REGION}}, {{DATE}}, {{DATE_TIME}},
        {{ITEMS_LIST}}, {{ORDER_NOTES}}, {{USER_NAME}}, {{USER_EMAIL}}, {{TOTAL_ITEMS}}
      </p>

      <?php if (!$canManageEmailTemplates) : ?>
        <div class="info-banner" style="margin-top: 15px;">
          You do not have permission to manage email templates.
        </div>
      <?php else : ?>
        <div style="margin-top: 15px;">
          <button id="addTemplateBtn" class="btn btn-primary">+ Add Purchasing Email</button>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($canManageEmailTemplates) : ?>
      <div class="form-section">
        <h3>Existing Templates (<?= count($emails) ?>)</h3>
        <?php if (empty($emails)) : ?>
          <div class="empty-state">
            <p>No purchasing emails configured. Add one to get started.</p>
          </div>
        <?php else : ?>
          <table class="data-table">
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
                  <td><?= h($email['sort_order']) ?></td>
                  <td><strong><?= h($email['label']) ?></strong></td>
                  <td><?= h($email['email']) ?></td>
                  <td><?= h($email['cc_addresses'] ?: 'None') ?></td>
                  <td>
                    <span class="badge <?= $email['is_active'] ? 'badge-success' : 'badge-inactive' ?>">
                      <?= $email['is_active'] ? 'Active' : 'Inactive' ?>
                    </span>
                  </td>
                  <td>
                    <?= $email['is_default'] ? '<span class="badge badge-primary">Default</span>' : '' ?>
                  </td>
                  <td class="actions">
                    <button
                      class="btn btn-sm btn-secondary js-template-edit"
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
                      class="btn btn-sm btn-info js-template-preview"
                      data-subject="<?= h($email['subject_template']) ?>"
                      data-body="<?= h($email['body_template']) ?>"
                    >Preview</button>
                    <form method="post" action="/admin/email_templates" style="display:inline-block;" class="delete-template-form">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="redirect_to" value="/admin.php?tab=email">
                      <input type="hidden" name="id" value="<?= (int)$email['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

<?php if ($canViewLogs) : ?>
  <!-- Logs Tab -->
  <div id="logs" class="admin-tab-content <?= $tab === 'logs' ? 'active' : '' ?>">
    <div class="form-section">
      <h3>System Logs</h3>
      <p style="margin: 0 0 15px 0; color: var(--color-gray-600);">Recent error and security logs from today</p>

      <?php if (empty($recentErrors) && empty($recentSecurity)) : ?>
        <div class="empty-state">
          <p>No logs available.</p>
        </div>
      <?php else : ?>
          <?php if (!empty($recentErrors)) : ?>
          <div style="margin-bottom: 25px;">
            <h4 style="color: var(--text-brand); margin-bottom: 10px;">Recent Errors</h4>
            <table class="data-table">
              <thead>
                <tr>
                  <th>Time</th>
                  <th>Message</th>
                  <th>Details</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentErrors as $error) : ?>
                  <tr>
                    <td><?= h($error->timestamp ?? 'N/A') ?></td>
                    <td><?= h($error->message ?? 'Unknown error') ?></td>
                    <td><small><?= h(substr(json_encode($error), 0, 100)) ?></small></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>

          <?php if (!empty($recentSecurity)) : ?>
          <div>
            <h4 style="color: var(--text-brand); margin-bottom: 10px;">Recent Security Events</h4>
            <table class="data-table">
              <thead>
                <tr>
                  <th>Time</th>
                  <th>Event</th>
                  <th>User/IP</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentSecurity as $event) : ?>
                  <tr>
                    <td><?= h($event->timestamp ?? 'N/A') ?></td>
                    <td><?= h($event->event ?? 'Unknown event') ?></td>
                    <td><?= h($event->user_id ?? $event->ip ?? 'N/A') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
      <?php endif; ?>

      <p style="margin-top: 15px;">
        <a href="/admin_logs" class="btn btn-secondary">View Full Logs</a>
      </p>
    </div>

    <div class="form-section">
      <h3>Log Management</h3>
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
        <?php foreach (['error', 'warning', 'security', 'performance', 'info', 'debug'] as $logType) : ?>
          <div style="padding: 15px; background: var(--color-gray-50); border-radius: 6px;">
            <strong><?= ucfirst($logType) ?> Logs</strong><br>
            <form method="post" action="<?= $adminAction ?>" style="margin-top: 10px; display: flex; gap: 8px; flex-direction: column;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="clear_logs">
              <input type="hidden" name="log_type" value="<?= h($logType) ?>">
              <button type="submit" class="btn btn-sm btn-danger" data-confirm="Clear all <?= h($logType) ?> logs?" data-confirm-title="Clear Logs" data-confirm-confirm-text="Clear" data-confirm-style="danger">Clear</button>
            </form>
            <form method="post" action="<?= $adminAction ?>" style="margin-top: 5px;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="download_logs">
              <input type="hidden" name="log_type" value="<?= h($logType) ?>">
              <button type="submit" class="btn btn-sm btn-secondary">Download</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="form-section">
      <h3>System Information</h3>
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
        <div style="padding: 10px; background: var(--color-gray-50); border-radius: 6px;">
          <strong>PHP Version</strong><br>
          <small><?= h($systemInfo['php_version']) ?></small>
        </div>
        <div style="padding: 10px; background: var(--color-gray-50); border-radius: 6px;">
          <strong>Memory Limit</strong><br>
          <small><?= h($systemInfo['memory_limit']) ?></small>
        </div>
        <div style="padding: 10px; background: var(--color-gray-50); border-radius: 6px;">
          <strong>Max Execution Time</strong><br>
          <small><?= h($systemInfo['max_execution_time']) ?> seconds</small>
        </div>
        <div style="padding: 10px; background: var(--color-gray-50); border-radius: 6px;">
          <strong>Upload Max Size</strong><br>
          <small><?= h($systemInfo['upload_max_filesize']) ?></small>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($canViewFiles) : ?>
  <!-- Files Tab -->
  <div id="files" class="admin-tab-content <?= $tab === 'files' ? 'active' : '' ?>">
    <div class="form-section">
      <h3>Disk Usage</h3>
      <?php if (!empty($diskUsage)) : ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
          <div style="padding: 15px; background: var(--color-gray-50); border-radius: 6px;">
            <strong>Total Files</strong><br>
            <span style="font-size: 1.5rem; color: var(--text-brand);"><?= (int)($diskUsage['total_files'] ?? 0) ?></span>
          </div>
          <div style="padding: 15px; background: var(--color-gray-50); border-radius: 6px;">
            <strong>Total Size</strong><br>
            <span style="font-size: 1.5rem; color: var(--text-brand);"><?= h($diskUsage['total_size'] ?? '0 B') ?></span>
          </div>
          <div style="padding: 15px; background: var(--color-gray-50); border-radius: 6px;">
            <strong>Largest File</strong><br>
            <span style="font-size: 1rem;"><small><?= h($diskUsage['largest_file'] ?? 'N/A') ?></small></span>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <div class="form-section">
      <h3>File Management</h3>
      <form method="post" action="<?= $adminAction ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cleanup_old_files">

        <div class="form-group">
          <label for="max_age">Delete files older than (days):</label>
          <div style="display: flex; gap: 10px;">
            <input type="number" id="max_age" name="max_age" value="30" min="1" max="365" style="flex: 1; max-width: 150px;">
            <button type="submit" class="btn btn-secondary" data-confirm="Delete files older than the specified number of days?" data-confirm-title="Cleanup Old Files" data-confirm-confirm-text="Delete" data-confirm-style="danger">Cleanup Old Files</button>
          </div>
        </div>
      </form>
      <p style="margin-top: 15px;">
        <a href="/admin_files" class="btn btn-secondary">View All Files</a>
      </p>
    </div>
  </div>
<?php endif; ?>

<?php if ($canViewSessions) : ?>
  <!-- Sessions Tab -->
  <div id="sessions" class="admin-tab-content <?= $tab === 'sessions' ? 'active' : '' ?>">
    <div class="form-section">
      <h3>Session Information</h3>
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
        <div style="padding: 15px; background: var(--color-gray-50); border-radius: 6px;">
          <strong>Active Sessions</strong><br>
          <span style="font-size: 1.5rem; color: var(--text-brand);"><?= (int)($sessionStats['active_sessions'] ?? 0) ?></span>
        </div>
        <div style="padding: 15px; background: var(--color-gray-50); border-radius: 6px;">
          <strong>Session Lifetime</strong><br>
          <small><?= (int)($sessionStats['session_lifetime'] / 60) ?> minutes</small>
        </div>
        <div style="padding: 15px; background: var(--color-gray-50); border-radius: 6px;">
          <strong>Session Name</strong><br>
          <small><?= h($sessionStats['session_name'] ?? 'N/A') ?></small>
        </div>
      </div>
    </div>

    <div class="form-section">
      <h3>Session Management</h3>
      <form method="post" action="<?= $adminAction ?>" style="margin-bottom: 20px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cleanup_sessions">
        <button type="submit" class="btn btn-secondary" data-confirm="Clean up expired sessions?" data-confirm-title="Cleanup Sessions" data-confirm-confirm-text="Cleanup" data-confirm-style="danger">Cleanup Expired Sessions</button>
      </form>

      <div style="border-top: 1px solid var(--color-gray-200); padding-top: 20px;">
        <h4 style="color: var(--text-brand); margin-bottom: 15px;">Force Logout User</h4>
        <form method="post" action="<?= $adminAction ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="force_logout_user">

          <div class="form-group">
            <label for="logout_user_id">User ID:</label>
            <div style="display: flex; gap: 10px;">
              <input type="number" id="logout_user_id" name="user_id" min="1" required style="flex: 1; max-width: 150px;">
              <button type="submit" class="btn btn-danger">Force Logout</button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <?php if (!empty($recentSecurity)) : ?>
      <div class="form-section">
        <h3>Recent Login/Logout Activity</h3>
        <table class="data-table">
          <thead>
            <tr>
              <th>Time</th>
              <th>Event</th>
              <th>User</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentSecurity as $event) : ?>
                <?php if (in_array($event->event ?? '', ['successful_login', 'user_logout', 'session_timeout'])) : ?>
                <tr>
                  <td><?= h($event->timestamp ?? 'N/A') ?></td>
                  <td><?= h(str_replace('_', ' ', ucfirst($event->event ?? 'Unknown'))) ?></td>
                  <td><?= h($event->user_id ?? $event->email ?? 'N/A') ?></td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <div class="form-section">
      <p>
        <a href="/admin_sessions" class="btn btn-secondary">View Full Sessions</a>
      </p>
    </div>
  </div>
<?php endif; ?>
    </section>
  </div>
</div>

<!-- Edit Modals -->

<!-- Edit User Modal -->
<div id="editUserModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h2>Edit User</h2>
      <button class="close-button" type="button" aria-label="Close modal">&times;</button>
    </div>
    <div class="modal-body">
      <form method="post" action="<?= $adminAction ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="edit_user">
        <input type="hidden" name="redirect_to" value="/admin.php?tab=users&users_page=<?= (int)$users_page ?>">
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
              <option value="user">User</option>
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

<!-- Edit Store Modal -->
<div id="editStoreModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h2>Edit Store</h2>
      <button class="close-button" type="button" aria-label="Close modal">&times;</button>
    </div>
    <div class="modal-body">
      <form method="post" action="<?= $adminAction ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="edit_store">
        <input type="hidden" name="redirect_to" value="/admin.php?tab=stores&stores_page=<?= (int)$stores_page ?>">
        <input type="hidden" name="store_id" id="editStoreId">

        <div class="form-group">
          <label>Store Name<input type="text" name="name" id="editStoreName" required></label>
        </div>

        <div class="form-group">
          <label>Store Number<input type="text" name="store_number" id="editStoreNumber"></label>
        </div>

        <div class="form-group">
          <label>Address<input type="text" name="address" id="editStoreAddress"></label>
        </div>

        <div class="form-group">
          <label>Contact Info<input type="text" name="contact_info" id="editStoreContactInfo"></label>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="ModalManager.hide(document.getElementById('editStoreModal'))">Cancel</button>
      <button type="submit" class="btn btn-primary" onclick="document.querySelector('#editStoreModal form').submit()">Save Changes</button>
    </div>
  </div>
</div>

<!-- Edit Region Modal -->
<div id="editRegionModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h2>Edit Region</h2>
      <button class="close-button" type="button" aria-label="Close modal">&times;</button>
    </div>
    <div class="modal-body">
      <form method="post" action="<?= $adminAction ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="edit_region">
        <input type="hidden" name="redirect_to" value="/admin.php?tab=regions&regions_page=<?= (int)$regions_page ?>">
        <input type="hidden" name="region_id" id="editRegionId">

        <div class="form-group">
          <label>Region Name<input type="text" name="name" id="editRegionName" required></label>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="ModalManager.hide(document.getElementById('editRegionModal'))">Cancel</button>
      <button type="submit" class="btn btn-primary" onclick="document.querySelector('#editRegionModal form').submit()">Save Changes</button>
    </div>
  </div>
</div>

<!-- Email Template Modals -->
<div id="templateModal" class="modal">
  <div class="modal-content modal-large">
    <div class="modal-header">
      <h2 id="modalTitle">Add Purchasing Email</h2>
      <button class="close-button" type="button" aria-label="Close modal">&times;</button>
    </div>
    <div class="modal-body">
      <form method="post" action="/admin/email_templates" id="templateForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" id="formAction" value="add">
        <input type="hidden" name="redirect_to" value="/admin.php?tab=email">
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
            <textarea name="body_template" id="templateBody" rows="12" required></textarea>
          </label>
          <small>Use {{ITEMS_LIST}}, {{ORDER_NOTES}}, and other variables.</small>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Sort Order
              <input type="number" name="sort_order" id="templateSortOrder" value="0" min="0">
            </label>
            <small>Lower numbers appear first.</small>
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

<div id="previewModal" class="modal">
  <div class="modal-content modal-large">
    <div class="modal-header">
      <h2>Email Preview (sample data)</h2>
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
  // Tab switching
  function switchTab(e, tabName) {
    if (e && typeof e.preventDefault === 'function') {
      e.preventDefault();
    }

    // Hide all tabs
    const contents = document.querySelectorAll('.admin-tab-content');
    contents.forEach(content => content.classList.remove('active'));

    // Remove active class from all nav buttons
    const buttons = document.querySelectorAll('[data-admin-tab]');
    buttons.forEach(btn => btn.classList.remove('active'));

    // Show selected tab and highlight button
    document.getElementById(tabName).classList.add('active');
    buttons.forEach(btn => {
      if (btn.getAttribute('data-admin-tab') === tabName) {
        btn.classList.add('active');
      }
    });

    if (window.history && window.history.replaceState) {
      const url = new URL(window.location.href);
      url.searchParams.set('tab', tabName);
      window.history.replaceState({}, '', url);
    }
  }

  function jumpToAdminSection(value) {
    if (!value) return;
    if (value.startsWith('link:')) {
      window.location.href = value.slice(5);
    } else if (value.startsWith('tab:')) {
      switchTab(null, value.slice(4));
    }
  }

  function filterAdminSections(query) {
    const value = (query || '').toLowerCase().trim();
    document.querySelectorAll('.admin-nav-btn').forEach(btn => {
      const label = btn.textContent.toLowerCase();
      btn.style.display = label.includes(value) ? 'block' : 'none';
    });
  }

  // Modal management
  function openUserEditModal(id, email, firstName, lastName, username, regionId, role) {
    document.getElementById('editUserId').value = id;
    document.getElementById('editUserEmail').value = email;
    document.getElementById('editUserFirstName').value = firstName;
    document.getElementById('editUserLastName').value = lastName;
    document.getElementById('editUserUsername').value = username;
    document.getElementById('editUserRegion').value = regionId;
    document.getElementById('editUserRole').value = role;

    const modal = document.getElementById('editUserModal');
    if (window.ModalManager) {
      ModalManager.show(modal);
    } else {
      modal.style.display = 'block';
    }
  }

  function openStoreEditModal(id, name, storeNumber, address, contactInfo) {
    document.getElementById('editStoreId').value = id;
    document.getElementById('editStoreName').value = name;
    document.getElementById('editStoreNumber').value = storeNumber;
    document.getElementById('editStoreAddress').value = address;
    document.getElementById('editStoreContactInfo').value = contactInfo;

    const modal = document.getElementById('editStoreModal');
    if (window.ModalManager) {
      ModalManager.show(modal);
    } else {
      modal.style.display = 'block';
    }
  }

  function openRegionEditModal(id, name) {
    document.getElementById('editRegionId').value = id;
    document.getElementById('editRegionName').value = name;

    const modal = document.getElementById('editRegionModal');
    if (window.ModalManager) {
      ModalManager.show(modal);
    } else {
      modal.style.display = 'block';
    }
  }

  // Modal close handlers
  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.js-admin-user-edit').forEach(button => {
      button.addEventListener('click', () => {
        openUserEditModal(
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

    document.querySelectorAll('.js-admin-store-edit').forEach(button => {
      button.addEventListener('click', () => {
        openStoreEditModal(
          button.dataset.storeId,
          button.dataset.storeName || '',
          button.dataset.storeNumber || '',
          button.dataset.storeAddress || '',
          button.dataset.storeContact || ''
        );
      });
    });

    document.querySelectorAll('.js-admin-region-edit').forEach(button => {
      button.addEventListener('click', () => {
        openRegionEditModal(
          button.dataset.regionId,
          button.dataset.regionName || ''
        );
      });
    });

    // User modal
    const userModal = document.getElementById('editUserModal');
    if (userModal) {
      const userCloseBtn = userModal.querySelector('.close-button');
      userCloseBtn.addEventListener('click', () => {
        if (window.ModalManager) {
          ModalManager.hide(userModal);
        } else {
          userModal.style.display = 'none';
        }
      });
    }

    // Store modal
    const storeModal = document.getElementById('editStoreModal');
    if (storeModal) {
      const storeCloseBtn = storeModal.querySelector('.close-button');
      storeCloseBtn.addEventListener('click', () => {
        if (window.ModalManager) {
          ModalManager.hide(storeModal);
        } else {
          storeModal.style.display = 'none';
        }
      });
    }

    // Region modal
    const regionModal = document.getElementById('editRegionModal');
    if (regionModal) {
      const regionCloseBtn = regionModal.querySelector('.close-button');
      regionCloseBtn.addEventListener('click', () => {
        if (window.ModalManager) {
          ModalManager.hide(regionModal);
        } else {
          regionModal.style.display = 'none';
        }
      });
    }

    // Delete confirmations
    document.querySelectorAll('.delete-user-form').forEach(form => {
      form.addEventListener('submit', function(e) {
        e.preventDefault();
        QuackNotify.confirm('Are you sure you want to delete this user?', {
          title: 'Delete User',
          confirmText: 'Delete',
          cancelText: 'Cancel',
          confirmStyle: 'danger'
        }).then(confirmed => {
          if (confirmed) this.submit();
        });
      });
    });

    document.querySelectorAll('.delete-store-form').forEach(form => {
      form.addEventListener('submit', function(e) {
        e.preventDefault();
        QuackNotify.confirm('Are you sure you want to delete this store?', {
          title: 'Delete Store',
          confirmText: 'Delete',
          cancelText: 'Cancel',
          confirmStyle: 'danger'
        }).then(confirmed => {
          if (confirmed) this.submit();
        });
      });
    });

    document.querySelectorAll('.delete-region-form').forEach(form => {
      form.addEventListener('submit', function(e) {
        e.preventDefault();
        QuackNotify.confirm('Are you sure you want to delete this region?', {
          title: 'Delete Region',
          confirmText: 'Delete',
          cancelText: 'Cancel',
          confirmStyle: 'danger'
        }).then(confirmed => {
          if (confirmed) this.submit();
        });
      });
    });

    // Email template modal handling
    const templateModal = document.getElementById('templateModal');
    const previewModal = document.getElementById('previewModal');

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

    document.querySelectorAll('.js-template-preview').forEach(btn => {
      btn.addEventListener('click', () => {
        const subject = btn.dataset.subject;
        const body = btn.dataset.body;

        const sampleData = {
          '{{ORDER_NUMBER}}': 'ORD-12345678ABCD',
          '{{STORE_NAME}}': 'Sample Store',
          '{{REGION}}': 'North Region',
          '{{DATE}}': new Date().toISOString().split('T')[0],
          '{{DATE_TIME}}': new Date().toLocaleString(),
          '{{USER_NAME}}': 'Alex Carter',
          '{{USER_EMAIL}}': 'alex.carter@example.com',
          '{{TOTAL_ITEMS}}': '5',
          '{{ITEMS_LIST}}': 'ITEMS:\n------\n\nSKU: PROD-001\nProduct: Sample Product\nQuantity: 2\n\nSKU: PROD-002\nProduct: Another Product\nQuantity: 3',
          '{{ORDER_NOTES}}': 'Deliver before 3 PM.'
        };

        let previewSubject = subject;
        let previewBody = body;
        Object.keys(sampleData).forEach(key => {
          const value = sampleData[key];
          previewSubject = previewSubject.split(key).join(value);
          previewBody = previewBody.split(key).join(value);
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

    const adminLayout = document.getElementById('adminLayout');
    const adminBackdrop = document.getElementById('adminBackdrop');
    const adminNavToggle = document.getElementById('adminNavToggle');
    const adminCollapse = document.getElementById('adminCollapse');

    const toggleAdminSidebar = (forceOpen = null) => {
      const isOpen = adminLayout?.getAttribute('data-filters-open') !== 'false';
      const nextState = forceOpen === null ? !isOpen : forceOpen;
      adminLayout?.setAttribute('data-filters-open', nextState ? 'true' : 'false');
    };

    adminNavToggle?.addEventListener('click', () => toggleAdminSidebar());
    adminCollapse?.addEventListener('click', () => toggleAdminSidebar(false));
    adminBackdrop?.addEventListener('click', () => toggleAdminSidebar(false));
  });

  // Click outside modal to close
  window.addEventListener('click', function(event) {
    const modals = ['editUserModal', 'editStoreModal', 'editRegionModal', 'templateModal', 'previewModal'];
    modals.forEach(modalId => {
      const modal = document.getElementById(modalId);
      if (event.target === modal) {
        if (window.ModalManager) {
          ModalManager.hide(modal);
        } else {
          modal.style.display = 'none';
        }
      }
    });
  });
</script>

<?php require __DIR__ . '/footer.php'; ?>
