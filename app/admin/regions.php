<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/admin_redirect.php';
require_once __DIR__ . '/../includes/SnipeItClient.php';
require_login();
require_permission('admin.regions.manage');
csrf_verify();
$pdo = db();
// Pagination and search setup
$items_per_page = 50;
$page = max(1, req_int($_GET, 'page', 1));
$search = req_string($_GET, 'search');
$offset = ($page - 1) * $items_per_page;
// Handle region actions (add, edit, delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = req_string($_POST, 'action');
    switch ($action) {
        case 'add_region':
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           $name = req_string($_POST, 'name');
            if (empty($name)) {
                flash('Region name is required.', 'error');
            } else {
                try {
                    $stmt = $pdo->prepare('SELECT id FROM regions WHERE name = ?');
                    $stmt->execute([$name]);
                    if ($stmt->fetch()) {
                        flash('Region with this name already exists.', 'error');
                    } else {
                        $pdo->prepare('INSERT INTO regions (name) VALUES (?)')->execute([$name]);
                        flash('Region added successfully.');
                    }
                } catch (Throwable $e) {
                    qt_rethrow_bad_input($e);
                    flash('Error adding region: ' . $e->getMessage(), 'error');
                }
            }

            break;
        case 'edit_region':
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           $regionId = req_int($_POST, 'region_id');
            $name = req_string($_POST, 'name');
            if ($regionId <= 0 || empty($name)) {
                flash('Invalid input for editing region.', 'error');
            } else {
                try {
                    $stmt = $pdo->prepare('SELECT id FROM regions WHERE name = ? AND id != ?');
                    $stmt->execute([$name, $regionId]);
                    if ($stmt->fetch()) {
                        flash('Region with this name already exists.', 'error');
                    } else {
                        $pdo->prepare('UPDATE regions SET name = ? WHERE id = ?')->execute([$name, $regionId]);
                        flash('Region updated successfully.');
                    }
                } catch (Throwable $e) {
                    qt_rethrow_bad_input($e);
                    flash('Error updating region: ' . $e->getMessage(), 'error');
                }
            }

            break;
        case 'delete_region':
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           $regionId = req_int($_POST, 'region_id');
            if ($regionId <= 0) {
                flash('Invalid region ID for deletion.', 'error');
            } else {
                try {
                // Check if any users are assigned to this region
                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE region_id = ?');
                    $stmt->execute([$regionId]);
                    if ($stmt->fetchColumn() > 0) {
                        flash('Cannot delete region: Users are still assigned to it.', 'error');
                    } else {
                        $pdo->prepare('DELETE FROM regions WHERE id = ?')->execute([$regionId]);
                        flash('Region deleted successfully.');
                    }
                } catch (Throwable $e) {
                    qt_rethrow_bad_input($e);
                    flash('Error deleting region: ' . $e->getMessage(), 'error');
                }
            }

            break;
        case 'set_region_registration':
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           $regionId = req_int($_POST, 'region_id');
            $allowRegistration = isset($_POST['allow_registration']) ? 1 : 0;
            if ($regionId <= 0) {
                flash('Invalid region ID for updating registration visibility.', 'error');
            } else {
                try {
                    $pdo->prepare('UPDATE regions SET allow_registration = ? WHERE id = ?')
                        ->execute([$allowRegistration, $regionId]);
                    flash('Region registration visibility updated.');
                } catch (Throwable $e) {
                    qt_rethrow_bad_input($e);
                    flash('Error updating region visibility: ' . $e->getMessage(), 'error');
                }
            }

            break;
        case 'set_snipe_location':
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           $regionId        = req_int($_POST, 'region_id');
            $snipeLocationId = req_int($_POST, 'snipe_location_id');
            $snipeLocationName = req_string($_POST, 'snipe_location_name');
            if ($regionId <= 0) {
                flash('Invalid region ID.', 'error');
            } elseif ($snipeLocationId <= 0) {
            // Clear the mapping
                try {
                    $pdo->prepare('DELETE FROM snipe_it_location_mappings WHERE region_id = ?')
                    ->execute([$regionId]);
                    flash('Snipe-IT location mapping removed.');
                } catch (Throwable $e) {
                    qt_rethrow_bad_input($e);
                    flash('Error removing mapping: ' . $e->getMessage(), 'error');
                }
            } else {
                try {
                    $pdo->prepare('INSERT INTO snipe_it_location_mappings (region_id, snipe_location_id, snipe_location_name)
                         VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE region_id = ?, snipe_location_name = ?')->execute([$regionId, $snipeLocationId, $snipeLocationName, $regionId, $snipeLocationName]);
                    flash('Snipe-IT location mapped successfully.');
                } catch (Throwable $e) {
                    qt_rethrow_bad_input($e);
                    flash('Error saving mapping: ' . $e->getMessage(), 'error');
                }
            }

            break;
    }
    header('Location: ' . admin_return_url('/admin/regions.php'));
    exit;
}

// Build search query
$where = '1=1';
$params = [];
if (!empty($search)) {
    $where = '(name LIKE ?)';
    $search_term = '%' . $search . '%';
    $params = [$search_term];
}

// Get total count for pagination
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM regions WHERE $where");
$countStmt->execute($params);
$total_regions = (int)$countStmt->fetchColumn();
$total_pages = max(1, ceil($total_regions / $items_per_page));
// Ensure page is within valid range
if ($page > $total_pages) {
    $page = $total_pages;
}

// Fetch paginated regions with their Snipe-IT location mapping
$regionsStmt = $pdo->prepare("SELECT r.id, r.name, r.allow_registration,
            m.snipe_location_id, m.snipe_location_name
     FROM regions r
     LEFT JOIN snipe_it_location_mappings m ON m.region_id = r.id
     WHERE $where
     ORDER BY r.name ASC LIMIT ? OFFSET ?");
$regionsStmt->execute(array_merge($params, [$items_per_page, $offset]));
$regions = $regionsStmt->fetchAll();
// Fetch Snipe-IT locations for the mapping modal (if Snipe-IT is enabled)
$snipe_locations = [];
$snipe_enabled = env('SNIPE_IT_ENABLED', '0') === '1';
if ($snipe_enabled) {
    try {
        $client = SnipeItClient::getInstance();
        $response = $client->listLocations(['limit' => 200]);
        $snipe_locations = $response['rows'] ?? [];
    } catch (Throwable $e) {
        qt_rethrow_bad_input($e);
        Logger::info('snipe_locations_unavailable', 'Snipe-IT locations could not be loaded for region mapping.', [
            'error_type' => get_class($e),
        ]);
    }
}

require __DIR__ . '/../views/admin/regions.view.php';
