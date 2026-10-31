<?php

/**
 * Guards for user and role administration (audit findings C4 and C5).
 *
 * A user manager who is not a full administrator may only grant a role, or act on a user, whose permissions they hold
 * themselves: otherwise `admin.users.manage` would be a path to administrator or to any elevated permission (grant
 * yourself `admin`, or reset the password of someone holding `email_templates.manage`). Only a technician's everyday
 * permissions (USER_ADMIN_EVERYDAY_PERMISSIONS) are exempt, so a user manager can still manage ordinary users; every
 * other permission, including any added later, counts.
 *
 * Changing someone's roles or credentials, or a role's permissions, increments users.session_version in the same write
 * (USER_ADMIN_END_SESSIONS), so every session of that user, the acting administrator's own included, ends at its next
 * request: a demoted user cannot keep the rights cached in a session. Only accounts that sign in with a local password
 * (login_method `local`) are given a password or a reset link by an administrator; Snipe-IT and company SSO accounts
 * are not.
 */

declare(strict_types=1);

/** A technician's everyday permissions (the field_tech role and the legacy `user` role): the only ones a user manager need not hold. */
const USER_ADMIN_EVERYDAY_PERMISSIONS = [
    'acumatica.inventory.view', 'assets.checkin', 'assets.checkout', 'assets.view', 'cart.manage', 'dashboard.view',
    'favorites.manage', 'order_templates.manage', 'orders.create', 'orders.view_own', 'products.view', 'receiving.receive_region',
    'region_storage.adjust', 'region_storage.transfer_van', 'region_storage.view', 'stock.adjust', 'stock.view_own',
    'surveys.submit', 'surveys.view_own', 'van_stock.adjust', 'van_stock.view',
];

/** The roles the user form offers, and the RBAC role each one maps to (see sync_user_role_assignment()). */
const USER_ADMIN_LEGACY_ROLES = ['admin' => 'admin', 'manager' => 'field_tech_lead', 'user' => 'field_tech'];

/** SQL assignment that ends every session of the updated user (append to an UPDATE users SET list). */
const USER_ADMIN_END_SESSIONS = 'session_version = session_version + 1';

/**
 * Every permission a legacy role grants: its legacy permission list and its RBAC role's stored permissions.
 *
 * @return list<string>
 */
function user_admin_role_permissions(PDO $pdo, string $role): array
{
    $permissions = legacy_permission_map()[$role] ?? [];
    $slug = USER_ADMIN_LEGACY_ROLES[$role] ?? null;
    if ($slug !== null) {
        $stmt = $pdo->prepare('SELECT LOWER(rp.permission_key) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.slug = ?');
        $stmt->execute([$slug]);
        $permissions = array_merge($permissions, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    return array_values(array_unique(array_map('strval', $permissions)));
}

/**
 * Every permission a user holds: their RBAC roles' permissions and their legacy role's list.
 *
 * @return list<string>
 */
function user_admin_user_permissions(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $role = strtolower((string)$stmt->fetchColumn());
    $legacy = legacy_permission_map()[$role] ?? [];
    return array_values(array_unique(array_merge(fetch_user_permissions($pdo, $userId), $legacy)));
}

/**
 * Whether the acting user holds every permission in $permissions beyond the everyday ones (a full administrator holds
 * them all).
 *
 * @param list<string> $permissions
 */
function user_admin_actor_holds(array $permissions): bool
{
    foreach ($permissions as $permission) {
        if (user_admin_is_power($permission) && !has_permission($permission)) {
            return false;
        }
    }
    return true;
}

/** Whether $permission goes beyond a technician's everyday work (anything not in USER_ADMIN_EVERYDAY_PERMISSIONS). */
function user_admin_is_power(string $permission): bool
{
    return !in_array(strtolower($permission), USER_ADMIN_EVERYDAY_PERMISSIONS, true);
}

/** Whether the acting user may give someone the legacy role $role (one of USER_ADMIN_LEGACY_ROLES). */
function user_admin_may_assign_role(PDO $pdo, string $role): bool
{
    return isset(USER_ADMIN_LEGACY_ROLES[$role]) && user_admin_actor_holds(user_admin_role_permissions($pdo, $role));
}

/** Whether the acting user may edit, delete or reset the credentials of user $userId. */
function user_admin_may_manage_user(PDO $pdo, int $userId): bool
{
    return user_admin_actor_holds(user_admin_user_permissions($pdo, $userId));
}

/** Whether user $userId signs in with a local password, so an administrator may set or reset it. */
function user_admin_has_local_credentials(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare('SELECT login_method FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetchColumn() === 'local';
}

/**
 * Replace the RBAC role of a user's previous legacy role with the new one (sync_user_role_assignment() only adds).
 * Roles assigned on the roles page that do not belong to the previous legacy role are kept.
 */
function user_admin_replace_legacy_role(PDO $pdo, int $userId, string $previousRole, string $role): void
{
    $previousSlug = USER_ADMIN_LEGACY_ROLES[$previousRole] ?? null;
    if ($previousSlug !== null && $previousRole !== $role) {
        $pdo->prepare('DELETE ur FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.slug = ?')
            ->execute([$userId, $previousSlug]);
    }
    sync_user_role_assignment($userId, $role);
}

/**
 * The role ids user $userId holds, sorted (to tell whether an assignment changed anything).
 *
 * @return list<int>
 */
function user_admin_role_ids(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT role_id FROM user_roles WHERE user_id = ? ORDER BY role_id');
    $stmt->execute([$userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}
