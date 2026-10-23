<?php

require_once __DIR__ . '/../config.php';
require_login();
require_permission('roles.manage');
if (!roles_tables_available()) {
    flash_message('Role tables are not available. Run the latest migrations.', 'error');
    redirect('/admin.php?tab=roles');
}
$pdo = db();
$action = req_string($_POST, 'action');
$allPermissionKeys = all_permission_keys();
$validPermissionSet = array_fill_keys($allPermissionKeys, true);
$redirectTarget = '/admin.php?tab=roles';

function normalize_role_slug(string $slug): string
{

    $slug = strtolower(trim($slug));
    $slug = preg_replace('/[^a-z0-9_]+/', '_', $slug);
    $slug = preg_replace('/_+/', '_', $slug);
    return trim($slug, '_');
}

try {
    switch ($action) {
        case 'create_role':
                                                                                                                                                                                              $name = req_string($_POST, 'name');
            $slug = normalize_role_slug(req_raw_string($_POST, 'slug'));
            $description = req_string($_POST, 'description');
            // Strict: this list replaces the role's stored permissions, so a malformed entry is a 400,
            // never a silently dropped (= revoked) permission.
            $permissionsInput = req_string_list($_POST, 'permissions', 500, true);
            if ($name === '' || $slug === '') {
                flash_message('Role name and slug are required.', 'error');
                redirect($redirectTarget);
            }

            if (get_role_id_by_slug($slug)) {
                flash_message('Role slug already exists. Choose another slug.', 'error');
                redirect($redirectTarget);
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO roles (name, slug, description) VALUES (?, ?, ?)');
            $stmt->execute([$name, $slug, $description !== '' ? $description : null]);
            $roleId = (int)$pdo->lastInsertId();
            $permissions = [];
            foreach ($permissionsInput as $permission) {
                $permission = strtolower($permission);
                if (isset($validPermissionSet[$permission])) {
                    $permissions[] = $permission;
                }
            }
            $permissions = array_unique($permissions);
            if (!empty($permissions)) {
                $insert = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_key) VALUES (?, ?)');
                foreach ($permissions as $perm) {
                    $insert->execute([$roleId, $perm]);
                }
            }

            $pdo->commit();
            AuditLogger::log('roles.manage', [
                'entity_type' => 'role',
                'entity_id' => $roleId,
                'description' => "Created role {$name}",
                'metadata' => ['slug' => $slug, 'permissions' => $permissions]
            ]);
            flash_message("Role \"{$name}\" created.", 'success');

            break;
        case 'update_role_permissions':
                                                                                                                                                                                              $roleId = req_int($_POST, 'role_id');
            if ($roleId <= 0) {
                flash_message('Invalid role selection.', 'error');
                redirect($redirectTarget);
            }

            $stmt = $pdo->prepare('SELECT id, name, slug, is_protected FROM roles WHERE id = ?');
            $stmt->execute([$roleId]);
            $role = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$role) {
                flash_message('Role not found.', 'error');
                redirect($redirectTarget);
            }

            // Strict: this list replaces the role's stored permissions, so a malformed entry is a 400,
            // never a silently dropped (= revoked) permission.
            $permissionsInput = req_string_list($_POST, 'permissions', 500, true);
            $permissions = [];
            foreach ($permissionsInput as $permission) {
                $permission = strtolower($permission);
                if (isset($validPermissionSet[$permission])) {
                    $permissions[] = $permission;
                }
            }
            $permissions = array_unique($permissions);
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);
            if (!empty($permissions)) {
                $insert = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_key) VALUES (?, ?)');
                foreach ($permissions as $perm) {
                    $insert->execute([$roleId, $perm]);
                }
            }
            $pdo->commit();
            AuditLogger::log('roles.manage', [
                'entity_type' => 'role',
                'entity_id' => $roleId,
                'description' => "Updated permissions for role {$role['name']}",
                'metadata' => ['permissions' => $permissions]
            ]);
            flash_message("Permissions updated for {$role['name']}.", 'success');

            break;
        case 'delete_role':
                                                                                                                                                                                              $roleId = req_int($_POST, 'role_id');
            if ($roleId <= 0) {
                flash_message('Invalid role selection.', 'error');
                redirect($redirectTarget);
            }

            $stmt = $pdo->prepare('SELECT id, name, slug, is_protected FROM roles WHERE id = ?');
            $stmt->execute([$roleId]);
            $role = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$role) {
                flash_message('Role not found.', 'error');
                redirect($redirectTarget);
            }

            if ((int)$role['is_protected'] === 1) {
                flash_message('Protected roles cannot be deleted.', 'error');
                redirect($redirectTarget);
            }

            $assignedUsers = $pdo->prepare('SELECT COUNT(*) FROM user_roles WHERE role_id = ?');
            $assignedUsers->execute([$roleId]);
            if ($assignedUsers->fetchColumn() > 0) {
                flash_message('Cannot delete role while users are assigned to it.', 'error');
                redirect($redirectTarget);
            }

            $pdo->prepare('DELETE FROM roles WHERE id = ?')->execute([$roleId]);
            AuditLogger::log('roles.manage', [
                'entity_type' => 'role',
                'entity_id' => $roleId,
                'description' => "Deleted role {$role['name']}",
                'metadata' => ['slug' => $role['slug']]
            ]);
            flash_message("Role {$role['name']} deleted.", 'success');

            break;
        case 'assign_user_roles':
                                                                                                                                                                                              $userEntries = array_values(array_filter(req_int_list($_POST, 'user_roles_present', 5000, true), fn ($id) => $id > 0));
            if (empty($userEntries)) {
                flash_message('No users submitted for role updates.', 'error');
                redirect($redirectTarget);
            }

            // Strict: these lists replace each user's stored roles, so everything is validated
            // before the first write and a malformed entry is a 400 instead of a revoked role.
            $userRolesInput = $_POST['user_roles'] ?? [];
            if (!is_array($userRolesInput)) {
                throw new BadRequestException("Field 'user_roles' must be a list.");
            }
            $selectedSlugsByUser = [];
            foreach ($userEntries as $userId) {
                $selectedSlugsByUser[$userId] = array_unique(array_filter(array_map('strtolower', req_string_list($userRolesInput, (string)$userId, 200, true))));
            }
            $pdo->beginTransaction();
            foreach ($userEntries as $userId) {
                $selectedSlugs = $selectedSlugsByUser[$userId];
                $roleIds = [];
                foreach ($selectedSlugs as $slug) {
                        $roleId = get_role_id_by_slug($slug);
                    if ($roleId) {
                        $roleIds[$roleId] = $slug;
                    }
                }

                $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$userId]);
                $slugList = array_values($roleIds);
                if (!empty($roleIds)) {
                    $insert = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
                    foreach ($roleIds as $rid => $slug) {
                        $insert->execute([$userId, $rid]);
                    }
                }

                // Update legacy role column for backward compatibility
                $legacyRole = 'user';
                if (in_array('admin', $slugList, true)) {
                    $legacyRole = 'admin';
                } elseif (in_array('field_tech_lead', $slugList, true)) {
                    $legacyRole = 'manager';
                }
                $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$legacyRole, $userId]);
                if ($userId === ($_SESSION['uid'] ?? null)) {
                    reset_user_permission_cache();
                }
            }

            $pdo->commit();
            AuditLogger::log('roles.manage', [
                'entity_type' => 'user_roles',
                'entity_id' => null,
                'description' => 'Updated user role assignments',
                'metadata' => ['affected_users' => $userEntries]
            ]);
            flash_message('User roles updated.', 'success');

            break;
        default:
                                                                                                                                                                                              flash_message('Unsupported roles action.', 'error');
    }
} catch (Throwable $e) {
    qt_rethrow_bad_input($e);
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    Logger::error('roles_action_failed', $e, ['action' => $action]);
    flash_message('Roles operation failed. Please try again.', 'error');
}

redirect($redirectTarget);
