<?php

require_once __DIR__ . '/../config.php';
require_login();
require_permission('product_metadata.manage');
$pdo = db();
$current_user_id = $_SESSION['uid'];
// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = req_string($_POST, 'action');
    switch ($action) {
        case 'add':
                                                                                                                                                                                                                                                                                                          $name = req_string($_POST, 'name');
            $description = req_string($_POST, 'description');
            $website = req_string($_POST, 'website');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $sort_order = req_int($_POST, 'sort_order');
        // Validation
            if (empty($name)) {
                flash('Manufacturer name is required.', 'error');
            } elseif (strlen($name) > 100) {
                flash('Manufacturer name must be 100 characters or less.', 'error');
            } elseif (!empty($website) && !filter_var($website, FILTER_VALIDATE_URL)) {
                flash('Website must be a valid URL.', 'error');
            } else {
                try {
                // Check for duplicate name
                    $checkStmt = $pdo->prepare('SELECT id FROM product_manufacturers WHERE name = ?');
                    $checkStmt->execute([$name]);
                    if ($checkStmt->fetch()) {
                        flash('Manufacturer name already exists.', 'error');
                    } else {
                        $stmt = $pdo->prepare('
                            INSERT INTO product_manufacturers (name, description, website, is_active, sort_order, created_by, updated_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ');
                        $stmt->execute([
                            $name,
                            $description ?: null,
                            $website ?: null,
                            $is_active,
                            $sort_order,
                            $current_user_id,
                            $current_user_id
                        ]);
                        flash('Manufacturer added successfully.');
                        Logger::info('manufacturer_created', 'New product manufacturer created', [
                            'name' => $name,
                            'user_id' => $current_user_id
                        ]);
                    }
                } catch (Throwable $e) {
                    qt_rethrow_bad_input($e);
                    flash('Error adding manufacturer: ' . $e->getMessage(), 'error');
                    Logger::error('manufacturer_create_failed', $e);
                }
            }

            break;
        case 'edit':
                                                                                                                                                                                                                                                                                                          $id = req_int($_POST, 'id');
            $name = req_string($_POST, 'name');
            $description = req_string($_POST, 'description');
            $website = req_string($_POST, 'website');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $sort_order = req_int($_POST, 'sort_order');
        // Validation
            if ($id <= 0) {
                flash('Invalid manufacturer ID.', 'error');
            } elseif (empty($name)) {
                flash('Manufacturer name is required.', 'error');
            } elseif (strlen($name) > 100) {
                flash('Manufacturer name must be 100 characters or less.', 'error');
            } elseif (!empty($website) && !filter_var($website, FILTER_VALIDATE_URL)) {
                flash('Website must be a valid URL.', 'error');
            } else {
                try {
                // Check for duplicate name (excluding current record)
                    $checkStmt = $pdo->prepare('SELECT id FROM product_manufacturers WHERE name = ? AND id != ?');
                    $checkStmt->execute([$name, $id]);
                    if ($checkStmt->fetch()) {
                        flash('Manufacturer name already exists.', 'error');
                    } else {
                        $stmt = $pdo->prepare('
                            UPDATE product_manufacturers
                            SET name = ?, description = ?, website = ?, is_active = ?, sort_order = ?, updated_by = ?
                            WHERE id = ?
                        ');
                        $stmt->execute([
                            $name,
                            $description ?: null,
                            $website ?: null,
                            $is_active,
                            $sort_order,
                            $current_user_id,
                            $id
                        ]);
                        flash('Manufacturer updated successfully.');
                        Logger::info('manufacturer_updated', 'Product manufacturer updated', [
                            'id' => $id,
                            'name' => $name,
                            'user_id' => $current_user_id
                        ]);
                    }
                } catch (Throwable $e) {
                    qt_rethrow_bad_input($e);
                    flash('Error updating manufacturer: ' . $e->getMessage(), 'error');
                    Logger::error('manufacturer_update_failed', $e);
                }
            }

            break;
        case 'delete':
                                                                                                                                                                                                                                                                                                          $id = req_int($_POST, 'id');
            if ($id <= 0) {
                flash('Invalid manufacturer ID.', 'error');
            } else {
                try {
                // Check if manufacturer is in use
                    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE manufacturer_id = ?');
                    $countStmt->execute([$id]);
                    $productCount = $countStmt->fetchColumn();
                    if ($productCount > 0) {
                        flash("Cannot delete manufacturer. It is used by {$productCount} product(s). Please reassign products first.", 'error');
                    } else {
                        $pdo->prepare('DELETE FROM product_manufacturers WHERE id = ?')->execute([$id]);
                        flash('Manufacturer deleted successfully.');
                        Logger::info('manufacturer_deleted', 'Product manufacturer deleted', [
                            'id' => $id,
                            'user_id' => $current_user_id
                        ]);
                    }
                } catch (Throwable $e) {
                    qt_rethrow_bad_input($e);
                    flash('Error deleting manufacturer: ' . $e->getMessage(), 'error');
                    Logger::error('manufacturer_delete_failed', $e);
                }
            }

            break;
    }

    header('Location: /admin/manufacturers');
    exit;
}

// Fetch all manufacturers for display
$stmt = $pdo->prepare('
    SELECT pm.*,
           uc.email as created_by_email,
           uu.email as updated_by_email,
           (SELECT COUNT(*) FROM products p WHERE p.manufacturer_id = pm.id) as product_count
    FROM product_manufacturers pm
    LEFT JOIN users uc ON pm.created_by = uc.id
    LEFT JOIN users uu ON pm.updated_by = uu.id
    ORDER BY pm.sort_order ASC, pm.name ASC
');
$stmt->execute();
$manufacturers = $stmt->fetchAll();
require __DIR__ . '/../views/admin/manufacturers.view.php';
