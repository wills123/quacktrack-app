<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/admin_redirect.php';
require_once __DIR__ . '/../includes/user_admin_guard.php';
require_once __DIR__ . '/../includes/user_admin_transaction.php';
require_login();
require_permission('admin.users.manage');
csrf_verify();
$pdo = db();
// Handle user actions (add, edit, delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = req_string($_POST, 'action');
    try {
        user_admin_begin_change($pdo, req_int($_POST, 'user_id'));
        switch ($action) {
            case 'add_user':
                                                                                                                                                                                                                                                                                  $email = req_string($_POST, 'email');
                $password = req_raw_string($_POST, 'password');
                $first_name = sanitize_string(req_raw_string($_POST, 'first_name'), 80);
                $last_name = sanitize_string(req_raw_string($_POST, 'last_name'), 80);
                $username = sanitize_string(req_raw_string($_POST, 'username'), 80);
                $region_id = req_int($_POST, 'region_id');
                $role = strtolower(req_string($_POST, 'role', 'user'));
                if (empty($email) || empty($password) || $region_id <= 0 || $first_name === '' || $last_name === '' || $username === '') {
                    flash('Name, username, email, password, and region are required.', 'error');
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    flash('Invalid email format.', 'error');
                } elseif (!validate_length($first_name, 1, 80) || !validate_length($last_name, 1, 80)) {
                    flash('First and last name must be between 1 and 80 characters.', 'error');
                } elseif (!validate_length($username, 3, 50) || preg_match('/[^a-zA-Z0-9._-]/', $username)) {
                    flash('Username must be 3-50 characters and use only letters, numbers, dots, underscores, or hyphens.', 'error');
                } elseif (($passwordError = password_policy_error($password)) !== null) {
                    flash($passwordError . '.', 'error');
                } elseif (!user_admin_may_assign_role($pdo, $role)) {
                    flash('You may not grant that role.', 'error');
                } else {
                    try {
                        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
                        $stmt->execute([$email]);
                        if ($stmt->fetch()) {
                            flash('User with this email already exists.', 'error');
                        } else {
                            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
                            $stmt->execute([$username]);
                            if ($stmt->fetch()) {
                                flash('User with this username already exists.', 'error');
                            } else {
                                $hash = password_hash($password, PASSWORD_DEFAULT);
                                $pdo->prepare('INSERT INTO users (email, first_name, last_name, username, password_hash, region_id, role) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$email, $first_name, $last_name, $username, $hash, $region_id, $role]);
                                $newUserId = (int)$pdo->lastInsertId();
                                sync_user_role_assignment($newUserId, $role);
                                flash('User added successfully.');
                            }
                        }
                    } catch (Throwable $e) {
                        qt_rethrow_bad_input($e);
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        flash('Error adding user: ' . $e->getMessage(), 'error');
                    }
                }

                break;
            case 'edit_user':
                                                                                                                                                                                                                                                                                  $userId = req_int($_POST, 'user_id');
                $email = req_string($_POST, 'email');
                $first_name = sanitize_string(req_raw_string($_POST, 'first_name'), 80);
                $last_name = sanitize_string(req_raw_string($_POST, 'last_name'), 80);
                $username = sanitize_string(req_raw_string($_POST, 'username'), 80);
                $region_id = req_int($_POST, 'region_id');
                $role = strtolower(req_string($_POST, 'role', 'user'));
                $password = req_raw_string($_POST, 'password');
            // Optional password change

                if ($userId <= 0 || empty($email) || $region_id <= 0) {
                    flash('Invalid input for editing user.', 'error');
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    flash('Invalid email format.', 'error');
                } elseif (!empty($password) && ($passwordError = password_policy_error($password)) !== null) {
                    flash('New password: ' . $passwordError . '.', 'error');
                } elseif (!user_admin_may_manage_user($pdo, $userId)) {
                    flash('You may not change a user who has more access than you.', 'error');
                } elseif (!user_admin_may_assign_role($pdo, $role)) {
                    flash('You may not grant that role.', 'error');
                } else {
                    try {
                        if ($username !== '') {
                            if (!validate_length($username, 3, 50) || preg_match('/[^a-zA-Z0-9._-]/', $username)) {
                                flash('Username must be 3-50 characters and use only letters, numbers, dots, underscores, or hyphens.', 'error');
                                break;
                            }
                            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ?');
                            $stmt->execute([$username, $userId]);
                            if ($stmt->fetch()) {
                                flash('User with this username already exists.', 'error');
                                break;
                            }
                        }

                        $sql = 'UPDATE users SET email = ?, region_id = ?, role = ?';
                        $params = [$email, $region_id, $role];
                        if ($first_name !== '') {
                            $sql .= ', first_name = ?';
                            $params[] = $first_name;
                        }

                        if ($last_name !== '') {
                            $sql .= ', last_name = ?';
                            $params[] = $last_name;
                        }

                        if ($username !== '') {
                            $sql .= ', username = ?';
                            $params[] = $username;
                        }

                        if (!empty($password)) {
                            if (!user_admin_has_local_credentials($pdo, $userId)) {
                                flash('This user signs in through single sign-on; their password is managed there.', 'error');
                                break;
                            }
                            $hash = password_hash($password, PASSWORD_DEFAULT);
                            $sql .= ', password_hash = ?';
                            $params[] = $hash;
                        }

                        $previous = $pdo->prepare('SELECT role FROM users WHERE id = ?');
                        $previous->execute([$userId]);
                        $previousRole = strtolower((string)$previous->fetchColumn());
                        if ($previousRole !== $role || !empty($password)) {
                            $sql .= ', ' . USER_ADMIN_END_SESSIONS;
                        }
                        $sql .= ' WHERE id = ?';
                        $params[] = $userId;
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);
                        user_admin_replace_legacy_role($pdo, $userId, $previousRole, $role);
                        flash('User updated successfully.');
                    } catch (Throwable $e) {
                        qt_rethrow_bad_input($e);
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        flash('Error updating user: ' . $e->getMessage(), 'error');
                    }
                }

                break;
            case 'delete_user':
                                                                                                                                                                                                                                                                                  $userId = req_int($_POST, 'user_id');
                if ($userId <= 0) {
                    flash('Invalid user ID for deletion.', 'error');
                } else {
                    try {
                    // Prevent deleting the currently logged-in admin
                        if ($userId === (int)$_SESSION['uid']) {
                            flash('Cannot delete your own account.', 'error');
                            break;
                        }
                        if (!user_admin_may_manage_user($pdo, $userId)) {
                            flash('You may not delete a user who has more access than you.', 'error');
                            break;
                        }
                        // Check if the user is assigned to any van stock or transactions
                        $stmt = $pdo->prepare('SELECT COUNT(*) FROM van_stock WHERE user_id = ?');
                        $stmt->execute([$userId]);
                        if ($stmt->fetchColumn() > 0) {
                            flash('Cannot delete user: User has associated van stock.', 'error');
                        } else {
                            $stmt = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE user_id = ?');
                            $stmt->execute([$userId]);
                            if ($stmt->fetchColumn() > 0) {
                                flash('Cannot delete user: User has associated transactions.', 'error');
                            } else {
                                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
                                flash('User deleted successfully.');
                            }
                        }
                    } catch (Throwable $e) {
                        qt_rethrow_bad_input($e);
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        flash('Error deleting user: ' . $e->getMessage(), 'error');
                    }
                }

                break;
            case 'force_password_reset':
                                                                                                                                                                                                                                                                                  $userId = req_int($_POST, 'user_id');
                $send_email = isset($_POST['send_email']) && $_POST['send_email'] === '1';
                if ($userId <= 0) {
                    flash('Invalid user ID.', 'error');
                } else {
                    try {
                    // Get user info
                        $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
                        $stmt->execute([$userId]);
                        $user = $stmt->fetch();
                        if (!$user) {
                            flash('User not found.', 'error');
                        } elseif (!user_admin_may_manage_user($pdo, $userId)) {
                            flash('You may not reset the password of a user who has more access than you.', 'error');
                        } elseif (!user_admin_has_local_credentials($pdo, $userId)) {
                            flash('This user signs in through single sign-on; their password is managed there.', 'error');
                        } else {
                        // Set must_reset_password flag and end the user's sessions
                            $stmt = $pdo->prepare('UPDATE users SET must_reset_password = 1, ' . USER_ADMIN_END_SESSIONS . ' WHERE id = ?');
                            $stmt->execute([$userId]);
                        // Optionally send email notification
                            if ($send_email) {
                // Generate password reset token
                                $token = bin2hex(random_bytes(32));
                                $token_hash = hash_password_reset_token($token);
                                $expires_at = date('Y-m-d H:i:s', time() + 3600);
                // 1 hour

                                $stmt = $pdo->prepare('INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)');
                                $stmt->execute([$userId, $token_hash, $expires_at]);
                // Send email
                                $reset_link = build_password_reset_link($token);
                                $email_sent = send_password_reset_notification_email($user['email'], $reset_link);
                                if ($email_sent) {
                                    flash('Password reset flag set and email sent to user.');
                                } else {
                                    flash('Password reset flag set, but the email failed to send. The user can request a new link from the sign-in page.', 'warning');
                                }
                            } else {
                                flash('Password reset flag set. User will be prompted to change password on next login.');
                            }

                            Logger::security('admin_force_password_reset', [
                                'admin_id' => $_SESSION['uid'],
                                'target_user_id' => $userId,
                                'target_email' => $user['email'],
                                'email_sent' => $send_email
                            ]);
                        }
                    } catch (Throwable $e) {
                        qt_rethrow_bad_input($e);
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        flash('Error forcing password reset: ' . $e->getMessage(), 'error');
                    }
                }

                break;
            case 'generate_temp_password':
                                                                                                                                                                                                                                                                                  $userId = req_int($_POST, 'user_id');
                if ($userId <= 0) {
                    flash('Invalid user ID.', 'error');
                } else {
                    try {
                    // Get user info
                        $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
                        $stmt->execute([$userId]);
                        $user = $stmt->fetch();
                        if (!$user) {
                            flash('User not found.', 'error');
                        } elseif (!user_admin_may_manage_user($pdo, $userId)) {
                            flash('You may not reset the password of a user who has more access than you.', 'error');
                        } elseif (!user_admin_has_local_credentials($pdo, $userId)) {
                            flash('This user signs in through single sign-on; their password is managed there.', 'error');
                        } else {
                        // Generate a random temporary password (16 characters)
                            $temp_password = bin2hex(random_bytes(8));
                        // 16 hex characters
                            $hash = password_hash($temp_password, PASSWORD_DEFAULT);
                        // Update user password and set must_reset_password flag
                            $stmt = $pdo->prepare('UPDATE users SET password_hash = ?, must_reset_password = 1, password_changed_at = NOW(), ' . USER_ADMIN_END_SESSIONS . ' WHERE id = ?');
                            $stmt->execute([$hash, $userId]);
                            flash('Temporary password generated: <strong>' . htmlspecialchars($temp_password) . '</strong><br>User must change this password on next login.', 'success');
                            Logger::security('admin_generate_temp_password', [
                                'admin_id' => $_SESSION['uid'],
                                'target_user_id' => $userId,
                                'target_email' => $user['email']
                            ]);
                        }
                    } catch (Throwable $e) {
                        qt_rethrow_bad_input($e);
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        flash('Error generating temporary password: ' . $e->getMessage(), 'error');
                    }
                }

                break;
        }
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        qt_rethrow_bad_input($e);
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('Unable to save account administration changes. Please retry or sign in again.', 'error');
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    header('Location: ' . admin_return_url('/admin/users'));
    exit;
}

// Fetch all users for display
// Check if password reset columns exist (backwards compatibility)
$column_exists = false;
try {
    $check = $pdo->query("SHOW COLUMNS FROM users LIKE 'must_reset_password'");
    $column_exists = $check->rowCount() > 0;
} catch (Exception $e) {
    qt_rethrow_bad_input($e);
    Logger::info('password_reset_column_unavailable', 'Legacy users schema does not include password reset state.', [
        'error_type' => get_class($e),
    ]);
}

if ($column_exists) {
    $users = $pdo->query("
        SELECT u.id, u.email, u.first_name, u.last_name, u.username,
               COALESCE(NULLIF(u.username, ''), NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), ''), u.email) AS display_name,
               u.region_id, r.name as region, u.role, u.must_reset_password, u.password_changed_at
        FROM users u
        LEFT JOIN regions r ON u.region_id = r.id
        ORDER BY u.email ASC
    ")->fetchAll();
} else {
    $users = $pdo->query("
        SELECT u.id, u.email, u.first_name, u.last_name, u.username,
               COALESCE(NULLIF(u.username, ''), NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), ''), u.email) AS display_name,
               u.region_id, r.name as region, u.role
        FROM users u
        LEFT JOIN regions r ON u.region_id = r.id
        ORDER BY u.email ASC
    ")->fetchAll();
// Add default values for missing columns
    foreach ($users as &$user) {
        $user['must_reset_password'] = 0;
        $user['password_changed_at'] = null;
    }
    unset($user);
}

// Fetch all available regions for the forms
$available_regions = $pdo->query('SELECT id, name FROM regions ORDER BY name ASC')->fetchAll();
require __DIR__ . '/../views/admin/users.view.php';
