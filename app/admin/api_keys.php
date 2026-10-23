<?php

/**
 * Admin: API Key Management
 *
 * Generate, view, and revoke API keys for external integrations (Power BI, etc.).
 */

require_once __DIR__ . '/../config.php';
require_login();
require_permission('admin.users.manage');
csrf_verify();
$pdo = db();
// ── Handle POST actions ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = req_string($_POST, 'action');
    switch ($action) {
        case 'generate_key':
                                                                                                                                                                                                                                                                                                                                                                                                                                      $name  = req_string($_POST, 'name');
            $desc  = req_string($_POST, 'description');
            $scope = req_string($_POST, 'scopes', 'powerbi:read');
            $expiresIn = req_int($_POST, 'expires_in_days');
            $allowedScopes = ['powerbi:read', 'quickqueue:orders', 'quackmap:read'];
            if ($name === '') {
                flash('Key name is required.', 'error');
                break;
            }
            if (!in_array($scope, $allowedScopes, true)) {
                flash('Invalid API key scope.', 'error');
                break;
            }

            // Generate a cryptographically secure API key
            $randomHex = bin2hex(random_bytes(32));
            $rawKey   = 'qt_' . $randomHex;
        // qt_ + 64 hex
            $keyHash  = hash('sha256', $rawKey);
            $prefix   = substr($randomHex, 0, 8);
        // display-only prefix (random portion)

            $expiresAt = null;
            if ($expiresIn > 0) {
                $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiresIn} days"));
            }

            try {
                $stmt = $pdo->prepare('INSERT INTO api_keys (name, description, key_hash, key_prefix, scopes, is_active, expires_at, created_by_user_id)
                     VALUES (?, ?, ?, ?, ?, 1, ?, ?)');
                $stmt->execute([
                    $name,
                    $desc !== '' ? $desc : null,
                    $keyHash,
                    $prefix,
                    $scope,
                    $expiresAt,
                $_SESSION['uid'],
                ]);
    // Store the raw key in session briefly so it can be shown once
                $_SESSION['new_api_key'] = $rawKey;
                $_SESSION['new_api_key_name'] = $name;
                flash('API key created. Copy it now - it will not be shown again!');
            } catch (Throwable $e) {
                qt_rethrow_bad_input($e);
                flash('Error creating API key. See logs for details.', 'error');
                if (class_exists('Logger', false)) {
                    Logger::error('api_key_create_failed', $e, ['admin_id' => $_SESSION['uid'] ?? null]);
                }
            }

            break;
        case 'revoke_key':
                                                                                                                                                                                                                                                                                                                                                                                                                                      $keyId = req_int($_POST, 'key_id');
            if ($keyId <= 0) {
                flash('Invalid key ID.', 'error');
            } else {
                try {
                    $pdo->prepare('UPDATE api_keys SET is_active = 0 WHERE id = ?')->execute([$keyId]);
                    flash('API key revoked.');
                } catch (Throwable $e) {
                    qt_rethrow_bad_input($e);
                    flash('Error revoking key. See logs for details.', 'error');
                    if (class_exists('Logger', false)) {
                        Logger::error('api_key_revoke_failed', $e, ['admin_id' => $_SESSION['uid'] ?? null, 'key_id' => $keyId]);
                    }
                }
            }

            break;
        case 'activate_key':
                                                                                                                                                                                                                                                                                                                                                                                                                                      $keyId = req_int($_POST, 'key_id');
            if ($keyId <= 0) {
                flash('Invalid key ID.', 'error');
            } else {
                try {
                    $pdo->prepare('UPDATE api_keys SET is_active = 1 WHERE id = ?')->execute([$keyId]);
                    flash('API key reactivated.');
                } catch (Throwable $e) {
                    qt_rethrow_bad_input($e);
                    flash('Error activating key. See logs for details.', 'error');
                    if (class_exists('Logger', false)) {
                        Logger::error('api_key_activate_failed', $e, ['admin_id' => $_SESSION['uid'] ?? null, 'key_id' => $keyId]);
                    }
                }
            }

            break;
        case 'delete_key':
                                                                                                                                                                                                                                                                                                                                                                                                                                      $keyId = req_int($_POST, 'key_id');
            if ($keyId <= 0) {
                flash('Invalid key ID.', 'error');
            } else {
                try {
                    $pdo->prepare('DELETE FROM api_keys WHERE id = ?')->execute([$keyId]);
                    flash('API key permanently deleted.');
                } catch (Throwable $e) {
                    qt_rethrow_bad_input($e);
                    flash('Error deleting key. See logs for details.', 'error');
                    if (class_exists('Logger', false)) {
                        Logger::error('api_key_delete_failed', $e, ['admin_id' => $_SESSION['uid'] ?? null, 'key_id' => $keyId]);
                    }
                }
            }

            break;
    }

    header('Location: /admin/api_keys');
    exit;
}

// ── Fetch existing keys ─────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT k.*, CONCAT(u.first_name, ' ', u.last_name) AS created_by_name
     FROM api_keys k
     LEFT JOIN users u ON k.created_by_user_id = u.id
     ORDER BY k.created_at DESC");
$stmt->execute();
$apiKeys = $stmt->fetchAll(PDO::FETCH_ASSOC); // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Read by the required views/admin/api_keys.view.php table.
// Check if we have a newly created key to show
$newApiKey     = $_SESSION['new_api_key'] ?? null; // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Read by the required views/admin/api_keys.view.php one-time key display.
$newApiKeyName = $_SESSION['new_api_key_name'] ?? null; // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Read by the required views/admin/api_keys.view.php one-time key display.
unset($_SESSION['new_api_key'], $_SESSION['new_api_key_name']);
require __DIR__ . '/../views/admin/api_keys.view.php';
