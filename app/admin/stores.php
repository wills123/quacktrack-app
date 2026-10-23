<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/admin_redirect.php';
require_once __DIR__ . '/../includes/SnipeItClient.php';
require_login();
require_permission('admin.stores.manage');
csrf_verify();
$pdo = db();

function store_region_prefix(?string $store_number): ?string
{
    if ($store_number === null || !preg_match('/^(\d{2})/', $store_number, $matches)) {
        return null;
    }

    return $matches[1];
}

// Fetch all regions for dropdowns
$regionStmt = $pdo->query('SELECT id, name FROM regions ORDER BY name ASC');
$regions = $regionStmt->fetchAll() ?: [];
$region_ids_by_code = [];
foreach ($regions as $region) {
    if (preg_match('/^\d{2}$/', $region['name'])) {
        $region_ids_by_code[$region['name']] = (int)$region['id'];
    }
}
// Pagination and search setup
$items_per_page = 50;
$page = max(1, req_int($_GET, 'page', 1));
$search = req_string($_GET, 'search');
$offset = ($page - 1) * $items_per_page;
// Handle store actions (add, edit, delete, bulk_import)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = req_string($_POST, 'action');
    switch ($action) {
        case 'bulk_import':
            // Handle CSV/Excel bulk import

            if (
                empty($_FILES['stores_file'])
                || ($_FILES['stores_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !is_uploaded_file($_FILES['stores_file']['tmp_name'])
            ) {
                flash('Upload a valid CSV file.', 'error');
            } else {
                $file = $_FILES['stores_file']['tmp_name'];
                $filename = $_FILES['stores_file']['name'];
            // Check file type (CSV only for now)
                $allowed_types = ['text/csv', 'text/plain', 'application/vnd.ms-excel'];
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime_type = $finfo->file($file);
                if (!in_array($mime_type, $allowed_types) && !preg_match('/\.csv$/i', $filename)) {
                    flash('Please upload a CSV file.', 'error');
                } else {
                    try {
                        $handle = fopen($file, 'r');
                        if ($handle === false) {
                            throw new RuntimeException('The import file could not be opened.');
                        }
                        $imported = 0;
                        $updated = 0;
                        $region_assigned = 0;
                        $region_overrides = 0;
                        $unknown_region_prefixes = [];
                        $skipped = 0;
                        $errors = [];
                // Read CSV/TSV header. Spreadsheet exports commonly use tabs and may include a UTF-8 BOM.
                        $header_line = fgets($handle);
                        if ($header_line === false) {
                            throw new RuntimeException('The import file is empty.');
                        }
                        $delimiter = substr_count($header_line, "\t") > substr_count($header_line, ',') ? "\t" : ',';
                        $headers = str_getcsv($header_line, $delimiter);
                        $normalized_headers = array_map(static function ($header): string {
                            $header = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header);

                            return preg_replace('/[\s-]+/', '_', strtolower(trim($header)));
                        }, $headers);
                        $name_col = array_search('store_name', $normalized_headers, true);
                        if ($name_col === false) {
                            $name_col = array_search('name', $normalized_headers, true);
                        }
                        $address_col = array_search('address', $normalized_headers, true);
                        $contact_col = array_search('contact_info', $normalized_headers, true);
                        $store_number_col = array_search('store_number', $normalized_headers, true);
                        if ($store_number_col === false) {
                            $store_number_col = array_search('store_#', $normalized_headers, true);
                        }
                        $project_number_col = array_search('project_number', $normalized_headers, true);
                        if ($project_number_col === false) {
                            $project_number_col = array_search('project_code', $normalized_headers, true);
                        }
                        if ($name_col === false && $store_number_col === false) {
                                    flash('CSV must contain a "name" / "Store Name" column or a "Store #" column.', 'error');
                        } else {
                                // Use transaction for bulk import
                            $pdo->beginTransaction();
                                $findByNumberStmt = $pdo->prepare('SELECT id, name, store_number, project_number, region_id FROM stores WHERE store_number = ?');
                                $findByNameStmt = $pdo->prepare('SELECT id, name, store_number, project_number, region_id FROM stores WHERE name = ?');
                                $insertStmt = $pdo->prepare('INSERT INTO stores (name, store_number, project_number, address, contact_info, region_id) VALUES (?, ?, ?, ?, ?, ?)');
                                $updateStmt = $pdo->prepare('UPDATE stores
                                    SET name = COALESCE(?, name),
                                        store_number = COALESCE(?, store_number),
                                        project_number = COALESCE(?, project_number),
                                        address = COALESCE(?, address),
                                        contact_info = COALESCE(?, contact_info),
                                        region_id = COALESCE(?, region_id)
                                    WHERE id = ?');
                            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                                $name = $name_col === false ? null : (trim($row[$name_col] ?? '') ?: null);
                                $address = $address_col === false ? null : (trim($row[$address_col] ?? '') ?: null);
                                $contact = $contact_col === false ? null : (trim($row[$contact_col] ?? '') ?: null);
                                $store_number = $store_number_col === false ? null : (trim($row[$store_number_col] ?? '') ?: null);
                                $project_number = $project_number_col === false ? null : (trim($row[$project_number_col] ?? '') ?: null);
                                if ($name === null && $store_number === null) {
                                    $skipped++;
                                    continue;
                                }

                                $region_prefix = store_region_prefix($store_number);
                                $region_id = $region_prefix === null ? null : ($region_ids_by_code[$region_prefix] ?? null);
                                if ($region_prefix !== null && $region_id === null) {
                                    $unknown_region_prefixes[$region_prefix] = true;
                                }

                                $numberStore = false;
                                if ($store_number !== null) {
                                    $findByNumberStmt->execute([$store_number]);
                                    $numberStore = $findByNumberStmt->fetch();
                                }
                                $nameStore = false;
                                if ($name !== null) {
                                    $findByNameStmt->execute([$name]);
                                    $nameStore = $findByNameStmt->fetch();
                                }

                                if ($numberStore && $nameStore && (int)$numberStore['id'] !== (int)$nameStore['id']) {
                                    $skipped++;
                                    $errors[] = "Store '$name' conflicts with store number '$store_number'";
                                    continue;
                                }

                                try {
                                    $existingStore = $numberStore ?: $nameStore;
                                    if ($existingStore) {
                                        if ($region_id !== null && (int)($existingStore['region_id'] ?? 0) !== $region_id) {
                                            $region_assigned++;
                                            if (!empty($existingStore['region_id'])) {
                                                $region_overrides++;
                                            }
                                        }
                                        $updateStmt->execute([$name, $store_number, $project_number, $address, $contact, $region_id, $existingStore['id']]);
                                        $updated++;
                                    } elseif ($name === null) {
                                        $skipped++;
                                        $errors[] = "Store number '$store_number' has no matching store name";
                                    } else {
                                        $insertStmt->execute([$name, $store_number, $project_number, $address, $contact, $region_id]);
                                        $imported++;
                                    }
                                } catch (Throwable $e) {
                                    qt_rethrow_bad_input($e);
                                    $skipped++;
                                    $errors[] = "Could not import store '" . ($name ?? $store_number) . "'";
                                }
                            }

                            $pdo->commit();
                            fclose($handle);
                            if (!empty($unknown_region_prefixes)) {
                                $_SESSION['pending_region_review'] = array_keys($unknown_region_prefixes);
                            }
                        // Clear cached stores since we modified the stores table
                            clear_session_cache('cached_stores');
                            $msg = "Bulk import complete: $imported stores added, $updated stores updated";
                            if ($region_assigned > 0) {
                                $msg .= ", $region_assigned regions assigned";
                            }
                            if ($region_overrides > 0) {
                                $msg .= " ($region_overrides existing region assignments updated from Store #)";
                            }
                            if ($skipped > 0) {
                                    $msg .= ", $skipped skipped";
                                if (count($errors) > 0 && count($errors) <= 5) {
                                    $msg .= " (" . implode("; ", array_slice($errors, 0, 5)) . ")";
                                }
                            }
                            flash($msg);
                        }
                    } catch (Throwable $e) {
                        qt_rethrow_bad_input($e);
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        flash('Unable to process the bulk import. No changes were made.', 'error');
                    }
                }
            }
            header('Location: ' . admin_return_url('/admin/stores.php'));

            exit;
        case 'resolve_region_prefixes':
            $pending_prefixes = $_SESSION['pending_region_review'] ?? [];
            $valid_region_ids = array_flip(array_map(static fn(array $region): int => (int)$region['id'], $regions));
            $find_region_stmt = $pdo->prepare('SELECT id FROM regions WHERE name = ?');
            $create_region_stmt = $pdo->prepare('INSERT INTO regions (name) VALUES (?)');
            $assign_region_stmt = $pdo->prepare('UPDATE stores SET region_id = ? WHERE store_number LIKE ?');
            $assigned = 0;

            try {
                $pdo->beginTransaction();
                foreach ($pending_prefixes as $prefix) {
                    if (!is_string($prefix) || !preg_match('/^\d{2}$/', $prefix)) {
                        continue;
                    }
                    $choice = req_string(is_array($_POST['region_action'] ?? null) ? $_POST['region_action'] : [], $prefix, 'unassigned');
                    if ($choice === 'unassigned') {
                        continue;
                    }
                    if ($choice === 'create') {
                        $find_region_stmt->execute([$prefix]);
                        $region_id = $find_region_stmt->fetchColumn();
                        if ($region_id === false) {
                            $create_region_stmt->execute([$prefix]);
                            $region_id = (int)$pdo->lastInsertId();
                        }
                    } elseif (preg_match('/^region:(\d+)$/', $choice, $matches) && isset($valid_region_ids[(int)$matches[1]])) {
                        $region_id = (int)$matches[1];
                    } else {
                        continue;
                    }

                    $assign_region_stmt->execute([(int)$region_id, $prefix . '%']);
                    $assigned += $assign_region_stmt->rowCount();
                }
                $pdo->commit();
                unset($_SESSION['pending_region_review']);
                clear_session_cache('cached_stores');
                flash("Region review complete: $assigned stores assigned.");
            } catch (Throwable $e) {
                qt_rethrow_bad_input($e);
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash('Unable to apply the region choices. No changes were made.', 'error');
            }

            break;
        case 'dismiss_region_review':
            unset($_SESSION['pending_region_review']);
            flash('Region review dismissed. Stores with unknown prefixes remain unassigned.', 'info');

            break;
        case 'backfill_store_regions':
            try {
                $unknown_prefixes = $pdo->query("SELECT DISTINCT LEFT(s.store_number, 2) AS prefix
                    FROM stores s
                    LEFT JOIN regions r ON r.name = LEFT(s.store_number, 2)
                    WHERE s.store_number REGEXP '^[0-9]{2}' AND r.id IS NULL
                    ORDER BY prefix")->fetchAll(PDO::FETCH_COLUMN);
                $overrides = (int)$pdo->query("SELECT COUNT(*)
                    FROM stores s
                    JOIN regions r ON r.name = LEFT(s.store_number, 2)
                    WHERE s.store_number REGEXP '^[0-9]{2}'
                      AND r.name REGEXP '^[0-9]{2}$'
                      AND s.region_id IS NOT NULL
                      AND s.region_id != r.id")->fetchColumn();
                $assigned = $pdo->exec("UPDATE stores s
                    JOIN regions r ON r.name = LEFT(s.store_number, 2)
                    SET s.region_id = r.id
                    WHERE s.store_number REGEXP '^[0-9]{2}'
                      AND r.name REGEXP '^[0-9]{2}$'
                      AND (s.region_id IS NULL OR s.region_id != r.id)");
                if (!empty($unknown_prefixes)) {
                    $_SESSION['pending_region_review'] = $unknown_prefixes;
                }
                clear_session_cache('cached_stores');
                $message = "Region backfill complete: $assigned stores assigned";
                if ($overrides > 0) {
                    $message .= ", including $overrides existing assignments updated from Store #";
                }
                flash($message . '.');
            } catch (Throwable $e) {
                qt_rethrow_bad_input($e);
                flash('Unable to run the region backfill. No changes were made.', 'error');
            }

            break;
        case 'add_store':
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 $name = req_string($_POST, 'name');
            $store_number = req_string($_POST, 'store_number') ?: null;
            $project_number = req_string($_POST, 'project_number') ?: null;
            $address = req_string($_POST, 'address');
            $contact_info = req_string($_POST, 'contact_info');
            $region_id = req_int($_POST, 'region_id');
            $region_id = $region_id > 0 ? $region_id : null;
            $region_prefix = store_region_prefix($store_number);
            if ($region_id === null && $region_prefix !== null && isset($region_ids_by_code[$region_prefix])) {
                $region_id = $region_ids_by_code[$region_prefix];
            }
            if (empty($name)) {
                flash('Store name is required.', 'error');
            } else {
                try {
                    $stmt = $pdo->prepare('SELECT id FROM stores WHERE name = ?');
                    $stmt->execute([$name]);
                    if ($stmt->fetch()) {
                        flash('Store with this name already exists.', 'error');
                    } else {
                        $existingNumberStore = false;
                        if ($store_number !== null) {
                            $numberStmt = $pdo->prepare('SELECT name FROM stores WHERE store_number = ?');
                            $numberStmt->execute([$store_number]);
                            $existingNumberStore = $numberStmt->fetchColumn();
                        }
                        if ($existingNumberStore !== false) {
                            flash("Store number '$store_number' is already assigned to '$existingNumberStore'.", 'error');
                        } else {
                            $pdo->prepare('INSERT INTO stores (name, store_number, project_number, address, contact_info, region_id) VALUES (?, ?, ?, ?, ?, ?)')->execute([$name, $store_number, $project_number, $address, $contact_info, $region_id]);
                            clear_session_cache('cached_stores');
                            flash('Store added successfully.');
                            if ($region_prefix !== null && !isset($region_ids_by_code[$region_prefix])) {
                                flash("Store # prefix '$region_prefix' has no numeric region yet. Use the bulk-region review to create or assign it.", 'info');
                            }
                        }
                    }
                } catch (Throwable $e) {
                    qt_rethrow_bad_input($e);
                    flash('Unable to add the store. Please review the store name and number, then try again.', 'error');
                }
            }

            break;
        case 'edit_store':
            $storeId = req_int($_POST, 'store_id');
            $name = req_string($_POST, 'name');
            $store_number = req_string($_POST, 'store_number') ?: null;
            $project_number = req_string($_POST, 'project_number') ?: null;
            $address = req_string($_POST, 'address');
            $contact_info = req_string($_POST, 'contact_info');
            $region_id = req_int($_POST, 'region_id');
            $region_id = $region_id > 0 ? $region_id : null;
            if ($storeId <= 0 || empty($name)) {
                flash('Invalid input for editing store.', 'error');
            } else {
                try {
                    $stmt = $pdo->prepare('SELECT id FROM stores WHERE name = ? AND id != ?');
                    $stmt->execute([$name, $storeId]);
                    if ($stmt->fetch()) {
                        flash('Store with this name already exists.', 'error');
                    } else {
                        $existingNumberStore = false;
                        if ($store_number !== null) {
                            $numberStmt = $pdo->prepare('SELECT name FROM stores WHERE store_number = ? AND id != ?');
                            $numberStmt->execute([$store_number, $storeId]);
                            $existingNumberStore = $numberStmt->fetchColumn();
                        }
                        if ($existingNumberStore !== false) {
                            flash("Store number '$store_number' is already assigned to '$existingNumberStore'.", 'error');
                        } else {
                            $pdo->prepare('UPDATE stores SET name = ?, store_number = ?, project_number = ?, address = ?, contact_info = ?, region_id = ? WHERE id = ?')->execute([$name, $store_number, $project_number, $address, $contact_info, $region_id, $storeId]);
                            clear_session_cache('cached_stores');
                            flash('Store updated successfully.');
                        }
                    }
                } catch (Throwable $e) {
                    qt_rethrow_bad_input($e);
                    flash('Unable to update the store. Please review the store name and number, then try again.', 'error');
                }
            }

            break;
        case 'set_snipe_location':
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 $storeId         = req_int($_POST, 'store_id');
            $snipeLocationId = req_int($_POST, 'snipe_location_id');
            $snipeLocationName = req_string($_POST, 'snipe_location_name');
            if ($storeId <= 0) {
                flash('Invalid store ID.', 'error');
            } else {
                try {
                    $pdo->prepare('UPDATE stores SET snipe_location_id = ?, snipe_location_name = ? WHERE id = ?')->execute([
                        $snipeLocationId > 0 ? $snipeLocationId : null,
                        $snipeLocationId > 0 ? $snipeLocationName : null,
                        $storeId,
                    ]);
                    clear_session_cache('cached_stores');
                    flash($snipeLocationId > 0 ? 'Snipe-IT location mapped successfully.' : 'Snipe-IT location mapping removed.');
                } catch (Throwable $e) {
                    qt_rethrow_bad_input($e);
                    flash('Error saving mapping: ' . $e->getMessage(), 'error');
                }
            }

            break;
        case 'delete_store':
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 $storeId = req_int($_POST, 'store_id');
            if ($storeId <= 0) {
                flash('Invalid store ID for deletion.', 'error');
            } else {
                try {
                // Prevent deleting the 'Unallocated' store (ID 1)
                    if ($storeId === 1) {
                        flash('Cannot delete the "Unallocated" store.', 'error');
                        header('Location: ' . admin_return_url('/admin/stores.php'));
                        exit;
                    }
                    // Check if any van_stock items are assigned to this store
                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM van_stock WHERE store_id = ?');
                    $stmt->execute([$storeId]);
                    if ($stmt->fetchColumn() > 0) {
                        flash('Cannot delete store: Van stock items are still assigned to it. Reassign them first.', 'error');
                    } else {
                        $pdo->prepare('DELETE FROM stores WHERE id = ?')->execute([$storeId]);
                        clear_session_cache('cached_stores');
                        flash('Store deleted successfully.');
                    }
                } catch (Throwable $e) {
                    qt_rethrow_bad_input($e);
                    flash('Error deleting store: ' . $e->getMessage(), 'error');
                }
            }

            break;
    }
    header('Location: ' . admin_return_url('/admin/stores.php'));
    exit;
}

// Build search query
$where = '1=1';
$params = [];
if (!empty($search)) {
    $where = '(s.name LIKE ? OR s.store_number LIKE ? OR s.project_number LIKE ? OR s.address LIKE ? OR s.contact_info LIKE ?)';
    $search_term = '%' . $search . '%';
    $params = [$search_term, $search_term, $search_term, $search_term, $search_term];
}

// Get total count for pagination
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM stores s WHERE $where");
$countStmt->execute($params);
$total_stores = (int)$countStmt->fetchColumn();
$total_pages = max(1, ceil($total_stores / $items_per_page));
// Ensure page is within valid range
if ($page > $total_pages) {
    $page = $total_pages;
}

// Fetch paginated stores for display (with region name via LEFT JOIN)
$storesStmt = $pdo->prepare("SELECT s.id, s.name, s.store_number, s.project_number, s.address, s.contact_info, s.region_id, r.name AS region_name,
            s.snipe_location_id, s.snipe_location_name
     FROM stores s
     LEFT JOIN regions r ON s.region_id = r.id
     WHERE $where ORDER BY s.name ASC LIMIT ? OFFSET ?");
$storesStmt->execute(array_merge($params, [$items_per_page, $offset]));
$stores = $storesStmt->fetchAll();
// Fetch Snipe-IT locations for the mapping modal
$snipe_locations = [];
$snipe_enabled = env('SNIPE_IT_ENABLED', '0') === '1';
if ($snipe_enabled) {
    try {
        $client = SnipeItClient::getInstance();
        $response = $client->listLocations(['limit' => 200]);
        $snipe_locations = $response['rows'] ?? [];
    } catch (Throwable $e) {
        qt_rethrow_bad_input($e);
        Logger::info('snipe_locations_unavailable', 'Snipe-IT locations could not be loaded for store mapping.', [
            'error_type' => get_class($e),
        ]);
    }
}

require __DIR__ . '/../views/admin/stores.view.php';
