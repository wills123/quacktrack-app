<?php

/**
 * The first administrator of a fresh installation (audit finding C2). The database is not seeded with an account;
 * app/create_admin.php calls create_first_admin() once, from the command line, with ADMIN_EMAIL and ADMIN_PASSWORD.
 */

declare(strict_types=1);

/** create_first_admin() outcomes, used as the command's exit codes. */
const FIRST_ADMIN_CREATED = 0;
const FIRST_ADMIN_INVALID = 1;
const FIRST_ADMIN_EXISTS = 2;

/**
 * Create the first administrator unless one exists. The account must change its password at first sign-in. Writes
 * what happened to $out (never the password).
 *
 * @param resource $out
 */
function create_first_admin(PDO $pdo, string $email, string $password, $out): int
{
    $email = strtolower(trim($email));
    $problems = filter_var($email, FILTER_VALIDATE_EMAIL) === false ? ['ADMIN_EMAIL must be a valid email address.'] : [];
    $problems = array_merge($problems, validate_password_strength($password)['errors'] ?? []);
    if ($problems !== []) {
        fwrite($out, implode("\n", $problems) . "\n");
        return FIRST_ADMIN_INVALID;
    }
    $pdo->beginTransaction();
    try {
        if (first_admin_exists($pdo)) {
            $pdo->rollBack();
            fwrite($out, "An administrator already exists; nothing was changed.\n");
            return FIRST_ADMIN_EXISTS;
        }
        $region = $pdo->query("SELECT id FROM regions WHERE name = 'Admin Region' LIMIT 1")->fetchColumn();
        $pdo->prepare("INSERT INTO users (email, password_hash, role, region_id, must_reset_password) VALUES (?, ?, 'admin', ?, 1)")
            ->execute([$email, password_hash($password, PASSWORD_DEFAULT), $region === false ? null : (int)$region]);
        $userId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    sync_user_role_assignment($userId, 'admin');
    fwrite($out, "Administrator {$email} created. The password must be changed at first sign-in.\n");
    return FIRST_ADMIN_CREATED;
}

/** Whether any account is an administrator, by its legacy role or (when the role tables exist) its RBAC role. */
function first_admin_exists(PDO $pdo): bool
{
    if ((int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' FOR UPDATE")->fetchColumn() > 0) {
        return true;
    }
    return roles_tables_available()
        && (int)$pdo->query("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.slug = 'admin'")->fetchColumn() > 0;
}
