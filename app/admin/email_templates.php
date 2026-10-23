<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/admin_redirect.php';
require_login();
require_permission('email_templates.manage');
$pdo = db();
$current_user_id = $_SESSION['uid'];
// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = req_string($_POST, 'action');
    switch ($action) {
        case 'add':
                                                                                                                                                                                                                                                                                                         $email = req_string($_POST, 'email');
            $label = req_string($_POST, 'label');
            $cc_addresses = req_string($_POST, 'cc_addresses');
            $subject_template = req_string($_POST, 'subject_template');
            $body_template = req_string($_POST, 'body_template');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $is_default = isset($_POST['is_default']) ? 1 : 0;
            $sort_order = req_int($_POST, 'sort_order');
        // Validation
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('Valid email address is required.', 'error');
            } elseif (empty($label)) {
                flash('Label is required.', 'error');
            } elseif (empty($subject_template)) {
                flash('Subject template is required.', 'error');
            } elseif (empty($body_template)) {
                flash('Body template is required.', 'error');
            } else {
                try {
                // Check for duplicate email
                    $checkStmt = $pdo->prepare('SELECT id FROM purchasing_emails WHERE email = ?');
                    $checkStmt->execute([$email]);
                    if ($checkStmt->fetch()) {
                        flash('Email address already exists.', 'error');
                    } else {
                    // If set as default, unset other defaults
                        if ($is_default) {
                            $pdo->prepare('UPDATE purchasing_emails SET is_default = 0')->execute();
                        }

                        $stmt = $pdo->prepare('
                            INSERT INTO purchasing_emails (
                                email, label, cc_addresses, subject_template, body_template,
                                is_active, is_default, sort_order, created_by, updated_by
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ');
                        $stmt->execute([
                            $email, $label, $cc_addresses ?: null, $subject_template, $body_template,
                            $is_active, $is_default, $sort_order, $current_user_id, $current_user_id
                        ]);
                        flash('Purchasing email added successfully.');
                        Logger::info('email_template_created', 'New purchasing email created', [
                            'email' => $email,
                            'user_id' => $current_user_id
                        ]);
                    }
                } catch (Throwable $e) {
                    qt_rethrow_bad_input($e);
                    flash('Error adding purchasing email: ' . $e->getMessage(), 'error');
                    Logger::error('email_template_create_failed', $e);
                }
            }

            break;
        case 'edit':
                                                                                                                                                                                                                                                                                                         $id = req_int($_POST, 'id');
            $email = req_string($_POST, 'email');
            $label = req_string($_POST, 'label');
            $cc_addresses = req_string($_POST, 'cc_addresses');
            $subject_template = req_string($_POST, 'subject_template');
            $body_template = req_string($_POST, 'body_template');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $is_default = isset($_POST['is_default']) ? 1 : 0;
            $sort_order = req_int($_POST, 'sort_order');
        // Validation
            if ($id <= 0) {
                flash('Invalid ID.', 'error');
            } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('Valid email address is required.', 'error');
            } elseif (empty($label)) {
                flash('Label is required.', 'error');
            } elseif (empty($subject_template)) {
                flash('Subject template is required.', 'error');
            } elseif (empty($body_template)) {
                flash('Body template is required.', 'error');
            } else {
                try {
                // Check for duplicate email (excluding current record)
                    $checkStmt = $pdo->prepare('SELECT id FROM purchasing_emails WHERE email = ? AND id != ?');
                    $checkStmt->execute([$email, $id]);
                    if ($checkStmt->fetch()) {
                        flash('Email address already exists.', 'error');
                    } else {
                    // If set as default, unset other defaults
                        if ($is_default) {
                            $pdo->prepare('UPDATE purchasing_emails SET is_default = 0 WHERE id != ?')->execute([$id]);
                        }

                        $stmt = $pdo->prepare('
                            UPDATE purchasing_emails
                            SET email = ?, label = ?, cc_addresses = ?, subject_template = ?,
                                body_template = ?, is_active = ?, is_default = ?, sort_order = ?,
                                updated_by = ?
                            WHERE id = ?
                        ');
                        $stmt->execute([
                            $email, $label, $cc_addresses ?: null, $subject_template,
                            $body_template, $is_active, $is_default, $sort_order,
                            $current_user_id, $id
                        ]);
                        flash('Purchasing email updated successfully.');
                        Logger::info('email_template_updated', 'Purchasing email updated', [
                            'id' => $id,
                            'user_id' => $current_user_id
                        ]);
                    }
                } catch (Throwable $e) {
                    qt_rethrow_bad_input($e);
                    flash('Error updating purchasing email: ' . $e->getMessage(), 'error');
                    Logger::error('email_template_update_failed', $e);
                }
            }

            break;
        case 'delete':
                                                                                                                                                                                                                                                                                                         $id = req_int($_POST, 'id');
            if ($id <= 0) {
                flash('Invalid ID.', 'error');
            } else {
                try {
                // Check if this is the only active email
                    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM purchasing_emails WHERE is_active = 1');
                    $countStmt->execute();
                    $activeCount = $countStmt->fetchColumn();
                    $emailStmt = $pdo->prepare('SELECT is_active FROM purchasing_emails WHERE id = ?');
                    $emailStmt->execute([$id]);
                    $emailData = $emailStmt->fetch();
                    if ($emailData && $emailData['is_active'] && $activeCount <= 1) {
                        flash('Cannot delete the last active purchasing email.', 'error');
                    } else {
                        $pdo->prepare('DELETE FROM purchasing_emails WHERE id = ?')->execute([$id]);
                        flash('Purchasing email deleted successfully.');
                        Logger::info('email_template_deleted', 'Purchasing email deleted', [
                            'id' => $id,
                            'user_id' => $current_user_id
                        ]);
                    }
                } catch (Throwable $e) {
                    qt_rethrow_bad_input($e);
                    flash('Error deleting purchasing email: ' . $e->getMessage(), 'error');
                    Logger::error('email_template_delete_failed', $e);
                }
            }

            break;
    }

    header('Location: ' . admin_return_url('/admin/email_templates'));
    exit;
}

// Fetch all purchasing emails for display
$stmt = $pdo->prepare('
    SELECT pe.*,
           uc.email as created_by_email,
           uu.email as updated_by_email
    FROM purchasing_emails pe
    LEFT JOIN users uc ON pe.created_by = uc.id
    LEFT JOIN users uu ON pe.updated_by = uu.id
    ORDER BY pe.sort_order ASC, pe.label ASC
');
$stmt->execute();
$emails = $stmt->fetchAll();
require __DIR__ . '/../views/admin/email_templates.view.php';
