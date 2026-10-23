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
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $sort_order = req_int($_POST, 'sort_order');
        // Validation
            if (empty($name)) {
                flash('Category name is required.', 'error');
            } elseif (strlen($name) > 100) {
                flash('Category name must be 100 characters or less.', 'error');
            } else {
                try {
                    // Check for duplicate name
                    $checkStmt = $pdo->prepare('SELECT id FROM product_categories WHERE name = ?');
                    $checkStmt->execute([$name]);
                    if ($checkStmt->fetch()) {
                        flash('Category name already exists.', 'error');
                    } else {
                        $stmt = $pdo->prepare('
                            INSERT INTO product_categories (name, description, is_active, sort_order, created_by, updated_by)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ');
                        $stmt->execute([
                            $name,
                            $description ?: null,
                            $is_active,
                            $sort_order,
                            $current_user_id,
                            $current_user_id
                        ]);
                        flash('Category added successfully.');
                        Logger::info('category_created', 'New product category created', [
                            'name' => $name,
                            'user_id' => $current_user_id
                        ]);
                    }
                } catch (Throwable $e) {
                    qt_rethrow_bad_input($e);
                    flash('Error adding category: ' . $e->getMessage(), 'error');
                    Logger::error('category_create_failed', $e);
                }
            }

            break;
        case 'edit':
                                                                                                                                                                                                                                                                                                          $id = req_int($_POST, 'id');
            $name = req_string($_POST, 'name');
            $description = req_string($_POST, 'description');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $sort_order = req_int($_POST, 'sort_order');
        // Validation
            if ($id <= 0) {
                flash('Invalid category ID.', 'error');
            } elseif (empty($name)) {
                flash('Category name is required.', 'error');
            } elseif (strlen($name) > 100) {
                flash('Category name must be 100 characters or less.', 'error');
            } else {
                try {
                // Check for duplicate name (excluding current record)
                    $checkStmt = $pdo->prepare('SELECT id FROM product_categories WHERE name = ? AND id != ?');
                    $checkStmt->execute([$name, $id]);
                    if ($checkStmt->fetch()) {
                        flash('Category name already exists.', 'error');
                    } else {
                        $stmt = $pdo->prepare('
                            UPDATE product_categories
                            SET name = ?, description = ?, is_active = ?, sort_order = ?, updated_by = ?
                            WHERE id = ?
                        ');
                        $stmt->execute([
                            $name,
                            $description ?: null,
                            $is_active,
                            $sort_order,
                            $current_user_id,
                            $id
                        ]);
                        flash('Category updated successfully.');
                        Logger::info('category_updated', 'Product category updated', [
                            'id' => $id,
                            'name' => $name,
                            'user_id' => $current_user_id
                        ]);
                    }
                } catch (Throwable $e) {
                    qt_rethrow_bad_input($e);
                    flash('Error updating category: ' . $e->getMessage(), 'error');
                    Logger::error('category_update_failed', $e);
                }
            }

            break;
        case 'delete':
                                                                                                                                                                                                                                                                                                          $id = req_int($_POST, 'id');
            if ($id <= 0) {
                flash('Invalid category ID.', 'error');
            } else {
                try {
                // Check if category is in use
                    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id = ?');
                    $countStmt->execute([$id]);
                    $productCount = $countStmt->fetchColumn();
                    if ($productCount > 0) {
                        flash("Cannot delete category. It is used by {$productCount} product(s). Please reassign products first.", 'error');
                    } else {
                        $pdo->prepare('DELETE FROM product_categories WHERE id = ?')->execute([$id]);
                        flash('Category deleted successfully.');
                        Logger::info('category_deleted', 'Product category deleted', [
                            'id' => $id,
                            'user_id' => $current_user_id
                        ]);
                    }
                } catch (Throwable $e) {
                    qt_rethrow_bad_input($e);
                    flash('Error deleting category: ' . $e->getMessage(), 'error');
                    Logger::error('category_delete_failed', $e);
                }
            }

            break;
    }

    header('Location: /admin/categories');
    exit;
}

// Fetch all categories for display
$stmt = $pdo->prepare('
    SELECT pc.*,
           uc.email as created_by_email,
           uu.email as updated_by_email,
           (SELECT COUNT(*) FROM products p WHERE p.category_id = pc.id) as product_count
    FROM product_categories pc
    LEFT JOIN users uc ON pc.created_by = uc.id
    LEFT JOIN users uu ON pc.updated_by = uu.id
    ORDER BY pc.sort_order ASC, pc.name ASC
');
$stmt->execute();
$categories = $stmt->fetchAll();
require __DIR__ . '/../views/admin/categories.view.php';
