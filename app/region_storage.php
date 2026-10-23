<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/inventory_ownership.php';
require_login();
$pdo = db();
$current_user_id = $_SESSION['uid'];
if (!defined('REGION_STORAGE_MAX_ADJUST_DELTA')) {
    define('REGION_STORAGE_MAX_ADJUST_DELTA', (int)env('REGION_STORAGE_MAX_ADJUST_DELTA', '10000'));
}

if (!defined('REGION_STORAGE_LOCATION_MAX_LENGTH')) {
    define('REGION_STORAGE_LOCATION_MAX_LENGTH', 255);
}

function rs_redirect(int $regionId): void
{

    header('Location: /region-storage?view_region_id=' . max(0, $regionId));
    exit;
}

function rs_sanitize_location(string $location): string
{

    $location = trim($location);
    if ($location === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        $location = mb_substr($location, 0, REGION_STORAGE_LOCATION_MAX_LENGTH);
    } else {
        $location = substr($location, 0, REGION_STORAGE_LOCATION_MAX_LENGTH);
    }

    return $location;
}

function rs_get_region(PDO $pdo, int $regionId): ?array
{

    static $cache = [];
    if ($regionId <= 0) {
        return null;
    }

    if (!isset($cache[$regionId])) {
        $stmt = $pdo->prepare('SELECT id, name FROM regions WHERE id = ?');
        $stmt->execute([$regionId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $cache[$regionId] = $result ?: null;
    }

    return $cache[$regionId];
}

function rs_get_product(PDO $pdo, int $productId): ?array
{

    static $cache = [];
    if ($productId <= 0) {
        return null;
    }

    if (!isset($cache[$productId])) {
        $stmt = $pdo->prepare('SELECT id, product_id AS sku, name FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $cache[$productId] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    return $cache[$productId];
}

function rs_storage_has_region_id(PDO $pdo): bool
{

    static $hasRegionId;
    if ($hasRegionId !== null) {
        return $hasRegionId;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'region_storage'
          AND COLUMN_NAME = 'region_id'
    ");
    $stmt->execute();
    $hasRegionId = ((int)$stmt->fetchColumn() > 0);
    return $hasRegionId;
}

$regionStorageHasRegionId = rs_storage_has_region_id($pdo);
$stores = $pdo->query('SELECT id, name FROM stores ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

function rs_get_store(PDO $pdo, int $storeId): ?array
{

    static $cache = [];
    if ($storeId <= 0) {
        return null;
    }

    if (!isset($cache[$storeId])) {
        $stmt = $pdo->prepare('SELECT id, name FROM stores WHERE id = ?');
        $stmt->execute([$storeId]);
        $cache[$storeId] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    return $cache[$storeId];
}

// Fetch all available regions from the regions table
$regions = $pdo->query('SELECT id, name FROM regions ORDER BY name ASC')->fetchAll();
// Handle region storage adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'adjust_region_storage') {
    csrf_verify();
    $prodId = req_int($_POST, 'prod_id');
    $change = req_int($_POST, 'change_qty');
    $selectedRegionId = req_int($_POST, 'region_id');
    $location = rs_sanitize_location($_POST['location'] ?? '');
    $region = rs_get_region($pdo, $selectedRegionId);
    $product = rs_get_product($pdo, $prodId);

    if (!$product) {
        flash('Please select a valid product before adjusting inventory.', 'error');
        rs_redirect($selectedRegionId);
    }

    try {
        InventoryOwnershipPolicy::requireQuantityProduct($pdo, $prodId);
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
        rs_redirect($selectedRegionId);
    }
    if (!$region) {
        flash('Please select a valid region before adjusting inventory.', 'error');
        rs_redirect(0);
    }

    if ($change === 0) {
        flash('Enter a non-zero quantity adjustment.', 'error');
        rs_redirect($selectedRegionId);
    }

    if (abs($change) > REGION_STORAGE_MAX_ADJUST_DELTA) {
        flash('Quantity change is too large for a single adjustment.', 'error');
        rs_redirect($selectedRegionId);
    }

    try {
        $pdo->beginTransaction();
// Query by (product_id, region_id, location) to support multi-location storage
        if ($regionStorageHasRegionId) {
            $stmt = $pdo->prepare('SELECT id, quantity, location FROM region_storage WHERE product_id = ? AND region_id = ? AND location = ? FOR UPDATE');
            $stmt->execute([$prodId, $selectedRegionId, $location]);
        } else {
            $stmt = $pdo->prepare('SELECT id, quantity, location FROM region_storage WHERE product_id = ? AND region = ? AND location = ? FOR UPDATE');
            $stmt->execute([$prodId, $region['name'], $location]);
        }
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($current) {
            $beforeQty = (int)$current['quantity'];
            $newQty = $beforeQty + $change;
            if ($newQty < 0) {
                throw new RuntimeException('Resulting quantity would be negative.');
            }

            if ($newQty === 0) {
                $deleteStmt = $pdo->prepare('DELETE FROM region_storage WHERE id = ?');
                $deleteStmt->execute([$current['id']]);
            } else {
                $updateStmt = $pdo->prepare('UPDATE region_storage SET quantity = ?, region = ? WHERE id = ?');
                $updateStmt->execute([$newQty, $region['name'], $current['id']]);
            }
        } else {
            if ($change < 0) {
                throw new RuntimeException('Cannot remove stock that does not exist at this location.');
            }
            $beforeQty = 0;
            $newQty = $change;
            if ($regionStorageHasRegionId) {
                $insertStmt = $pdo->prepare('INSERT INTO region_storage (product_id, region_id, region, location, quantity) VALUES (?, ?, ?, ?, ?)');
                $insertStmt->execute([$prodId, $region['id'], $region['name'], $location, $change]);
            } else {
                $insertStmt = $pdo->prepare('INSERT INTO region_storage (product_id, region, location, quantity) VALUES (?, ?, ?, ?)');
                $insertStmt->execute([$prodId, $region['name'], $location, $change]);
            }
        }

        $pdo->commit();
        AuditLogger::logRegionStorageChange([
            'product_id' => $product['id'],
            'product_sku' => $product['sku'],
            'region_id' => $region['id'],
            'region_name' => $region['name'],
            'change_type' => 'adjust',
            'quantity_before' => $beforeQty,
            'quantity_after' => $newQty,
            'delta' => $change,
            'notes' => null,
            'description' => sprintf('Adjusted %s by %d units in %s', $product['name'] ?? ('Product #' . $product['id']), $change, $region['name'])
        ]);
        flash('Region storage updated successfully.');
    } catch (Throwable $e) {
        qt_rethrow_bad_input($e);
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        Logger::error('region_storage_adjust_failed', $e, [
            'product_id' => $prodId,
            'region_id' => $selectedRegionId,
            'change' => $change
        ]);
        flash('Unable to update region storage. Please try again.', 'error');
    }

    rs_redirect($selectedRegionId);
}

// Handle transfer between regions (or within same region to different location)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'transfer_stock') {
    csrf_verify();
    $prodId = req_int($_POST, 'prod_id');
    $fromRegionId = req_int($_POST, 'from_region_id');
    $toRegionId = req_int($_POST, 'to_region_id');
    $transferQty = req_int($_POST, 'transfer_qty');
    $sourceLocation = rs_sanitize_location($_POST['source_location'] ?? '');
    $newLocation = rs_sanitize_location($_POST['new_location'] ?? '');
    $destinationType = req_raw_string($_POST, 'destination_type', 'region');
    $destinationType = in_array($destinationType, ['region', 'van', 'location'], true) ? $destinationType : 'region';
    $vanStoreId = req_int($_POST, 'van_store_id', 1);
    $product = rs_get_product($pdo, $prodId);
    $fromRegion = rs_get_region($pdo, $fromRegionId);
    $toRegion = $destinationType === 'region' ? rs_get_region($pdo, $toRegionId) : null;
    $targetStore = $destinationType === 'van' ? rs_get_store($pdo, $vanStoreId) : null;

    if (!$product) {
        flash('Select a valid product to transfer.', 'error');
        rs_redirect($fromRegionId);
    }

    try {
        InventoryOwnershipPolicy::requireQuantityProduct($pdo, $prodId);
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
        rs_redirect($fromRegionId);
    }
    if (!$fromRegion) {
        flash('Select a valid source region.', 'error');
        rs_redirect(0);
    }

    if ($destinationType === 'region') {
        if (!$toRegion || $fromRegion['id'] === $toRegion['id']) {
            flash('Please choose valid and distinct source/destination regions.', 'error');
            rs_redirect($fromRegionId);
        }
    } elseif ($destinationType === 'location') {
    // Transfer within same region to different location
        if ($sourceLocation === $newLocation) {
            flash('Source and destination locations must be different.', 'error');
            rs_redirect($fromRegionId);
        }
    } else {
        if (!$targetStore) {
            flash('Select a valid store for van stock assignment.', 'error');
            rs_redirect($fromRegionId);
        }
    }

    if ($transferQty <= 0 || $transferQty > REGION_STORAGE_MAX_ADJUST_DELTA) {
        flash('Transfer quantity must be between 1 and ' . REGION_STORAGE_MAX_ADJUST_DELTA . '.', 'error');
        rs_redirect($fromRegionId);
    }

    try {
        $pdo->beginTransaction();
// Query source by (product_id, region_id, location) to support multi-location storage
        if ($regionStorageHasRegionId) {
            $sourceStmt = $pdo->prepare('SELECT id, quantity, location FROM region_storage WHERE product_id = ? AND region_id = ? AND location = ? FOR UPDATE');
            $sourceStmt->execute([$prodId, $fromRegionId, $sourceLocation]);
        } else {
            $sourceStmt = $pdo->prepare('SELECT id, quantity, location FROM region_storage WHERE product_id = ? AND region = ? AND location = ? FOR UPDATE');
            $sourceStmt->execute([$prodId, $fromRegion['name'], $sourceLocation]);
        }
        $source = $sourceStmt->fetch(PDO::FETCH_ASSOC);
        if (!$source || (int)$source['quantity'] < $transferQty) {
            throw new RuntimeException('Insufficient stock at this location in source region.');
        }

        if ($destinationType === 'region') {
// Transfer to another region - query destination by (product_id, region_id, location)
            $destinationLocation = $newLocation !== '' ? $newLocation : $sourceLocation;
            if ($regionStorageHasRegionId) {
                $destStmt = $pdo->prepare('SELECT id, quantity, location FROM region_storage WHERE product_id = ? AND region_id = ? AND location = ? FOR UPDATE');
                $destStmt->execute([$prodId, $toRegionId, $destinationLocation]);
            } else {
                $destStmt = $pdo->prepare('SELECT id, quantity, location FROM region_storage WHERE product_id = ? AND region = ? AND location = ? FOR UPDATE');
                $destStmt->execute([$prodId, $toRegion['name'], $destinationLocation]);
            }
            $destination = $destStmt->fetch(PDO::FETCH_ASSOC);
            $newSourceQty = (int)$source['quantity'] - $transferQty;
            if ($newSourceQty > 0) {
                $updateSource = $pdo->prepare('UPDATE region_storage SET quantity = ?, region = ? WHERE id = ?');
                $updateSource->execute([$newSourceQty, $fromRegion['name'], $source['id']]);
            } else {
                $deleteSource = $pdo->prepare('DELETE FROM region_storage WHERE id = ?');
                $deleteSource->execute([$source['id']]);
            }

            if ($destination) {
                $newDestQty = (int)$destination['quantity'] + $transferQty;
                $updateDest = $pdo->prepare('UPDATE region_storage SET quantity = ?, region = ? WHERE id = ?');
                $updateDest->execute([$newDestQty, $toRegion['name'], $destination['id']]);
            } else {
                if ($regionStorageHasRegionId) {
                    $insertDest = $pdo->prepare('INSERT INTO region_storage (product_id, region_id, region, location, quantity) VALUES (?, ?, ?, ?, ?)');
                    $insertDest->execute([$prodId, $toRegion['id'], $toRegion['name'], $destinationLocation, $transferQty]);
                } else {
                    $insertDest = $pdo->prepare('INSERT INTO region_storage (product_id, region, location, quantity) VALUES (?, ?, ?, ?)');
                    $insertDest->execute([$prodId, $toRegion['name'], $destinationLocation, $transferQty]);
                }
                $newDestQty = $transferQty;
            }

            $pdo->commit();
            AuditLogger::log('region_storage.transfer', [
                'entity_type' => 'product',
                'entity_id' => $product['id'],
                'description' => sprintf('Transferred %d units of %s from %s to %s', $transferQty, $product['name'] ?? ('Product #' . $product['id']), $fromRegion['name'], $toRegion['name']),
                'metadata' => [
                    'product_id' => $product['id'],
                    'product_sku' => $product['sku'],
                    'from_region_id' => $fromRegion['id'],
                    'from_region_name' => $fromRegion['name'],
                    'from_location' => $sourceLocation,
                    'to_region_id' => $toRegion['id'],
                    'to_region_name' => $toRegion['name'],
                    'to_location' => $destinationLocation,
                    'quantity' => $transferQty,
                    'source_quantity_after' => $newSourceQty,
                    'destination_quantity_after' => $newDestQty
                ]
            ]);
            flash("Transferred {$transferQty} units from {$fromRegion['name']} to {$toRegion['name']}.");
        } elseif ($destinationType === 'location') {
        // Transfer within same region to different location
            if ($regionStorageHasRegionId) {
                $destStmt = $pdo->prepare('SELECT id, quantity, location FROM region_storage WHERE product_id = ? AND region_id = ? AND location = ? FOR UPDATE');
                $destStmt->execute([$prodId, $fromRegionId, $newLocation]);
            } else {
                $destStmt = $pdo->prepare('SELECT id, quantity, location FROM region_storage WHERE product_id = ? AND region = ? AND location = ? FOR UPDATE');
                $destStmt->execute([$prodId, $fromRegion['name'], $newLocation]);
            }
            $destination = $destStmt->fetch(PDO::FETCH_ASSOC);
            $newSourceQty = (int)$source['quantity'] - $transferQty;
            if ($newSourceQty > 0) {
                $updateSource = $pdo->prepare('UPDATE region_storage SET quantity = ?, region = ? WHERE id = ?');
                $updateSource->execute([$newSourceQty, $fromRegion['name'], $source['id']]);
            } else {
                $deleteSource = $pdo->prepare('DELETE FROM region_storage WHERE id = ?');
                $deleteSource->execute([$source['id']]);
            }

            if ($destination) {
                $newDestQty = (int)$destination['quantity'] + $transferQty;
                $updateDest = $pdo->prepare('UPDATE region_storage SET quantity = ?, region = ? WHERE id = ?');
                $updateDest->execute([$newDestQty, $fromRegion['name'], $destination['id']]);
            } else {
                if ($regionStorageHasRegionId) {
                    $insertDest = $pdo->prepare('INSERT INTO region_storage (product_id, region_id, region, location, quantity) VALUES (?, ?, ?, ?, ?)');
                    $insertDest->execute([$prodId, $fromRegion['id'], $fromRegion['name'], $newLocation, $transferQty]);
                } else {
                    $insertDest = $pdo->prepare('INSERT INTO region_storage (product_id, region, location, quantity) VALUES (?, ?, ?, ?)');
                    $insertDest->execute([$prodId, $fromRegion['name'], $newLocation, $transferQty]);
                }
                $newDestQty = $transferQty;
            }

            $pdo->commit();
            $sourceLocDisplay = $sourceLocation !== '' ? $sourceLocation : '(no location)';
            $destLocDisplay = $newLocation !== '' ? $newLocation : '(no location)';
            AuditLogger::log('region_storage.move_location', [
                'entity_type' => 'product',
                'entity_id' => $product['id'],
                'description' => sprintf('Moved %d units of %s from "%s" to "%s" in %s', $transferQty, $product['name'] ?? ('Product #' . $product['id']), $sourceLocDisplay, $destLocDisplay, $fromRegion['name']),
                'metadata' => [
                    'product_id' => $product['id'],
                    'product_sku' => $product['sku'],
                    'region_id' => $fromRegion['id'],
                    'region_name' => $fromRegion['name'],
                    'from_location' => $sourceLocation,
                    'to_location' => $newLocation,
                    'quantity' => $transferQty,
                    'source_quantity_after' => $newSourceQty,
                    'destination_quantity_after' => $newDestQty
                ]
            ]);
            flash("Moved {$transferQty} units from \"{$sourceLocDisplay}\" to \"{$destLocDisplay}\" in {$fromRegion['name']}.");
        } else {
            $newSourceQty = (int)$source['quantity'] - $transferQty;
            if ($newSourceQty > 0) {
                $updateSource = $pdo->prepare('UPDATE region_storage SET quantity = ?, region = ? WHERE id = ?');
                $updateSource->execute([$newSourceQty, $fromRegion['name'], $source['id']]);
            } else {
                $deleteSource = $pdo->prepare('DELETE FROM region_storage WHERE id = ?');
                $deleteSource->execute([$source['id']]);
            }

            $vanStockStmt = $pdo->prepare('SELECT id, quantity FROM van_stock WHERE product_id = ? AND user_id = ? AND store_id = ? FOR UPDATE');
            $vanStockStmt->execute([$prodId, $current_user_id, $targetStore['id']]);
            $vanStockRow = $vanStockStmt->fetch(PDO::FETCH_ASSOC);
            if ($vanStockRow) {
                $updatedQty = (int)$vanStockRow['quantity'] + $transferQty;
                $updateVan = $pdo->prepare('UPDATE van_stock SET quantity = ?, updated_at = NOW() WHERE id = ?');
                $updateVan->execute([$updatedQty, $vanStockRow['id']]);
            } else {
                $insertVan = $pdo->prepare('INSERT INTO van_stock (product_id, user_id, store_id, quantity) VALUES (?, ?, ?, ?)');
                $insertVan->execute([$prodId, $current_user_id, $targetStore['id'], $transferQty]);
            }

            $txnStmt = $pdo->prepare('INSERT INTO van_stock_transactions (product_id, user_id, quantity_change, reason, source_store_id, target_store_id, note, metadata) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $txnMetadata = [
                'source_region_id' => $fromRegion['id'],
                'source_region_name' => $fromRegion['name'],
                'destination_store_id' => $targetStore['id'],
                'destination_store_name' => $targetStore['name']
            ];
            $txnStmt->execute([
                $prodId,
                $current_user_id,
                $transferQty,
                'transfer',
                null,
                $targetStore['id'],
                'Transferred from region storage',
                json_encode($txnMetadata)
            ]);
            $pdo->commit();
            AuditLogger::log('region_storage.transfer_to_van', [
                'entity_type' => 'product',
                'entity_id' => $product['id'],
                'description' => sprintf('Moved %d units of %s from %s into van stock (%s)', $transferQty, $product['name'] ?? ('Product #' . $product['id']), $fromRegion['name'], $targetStore['name']),
                'metadata' => [
                    'product_id' => $product['id'],
                    'product_sku' => $product['sku'],
                    'from_region_id' => $fromRegion['id'],
                    'from_region_name' => $fromRegion['name'],
                    'van_store_id' => $targetStore['id'],
                    'van_store_name' => $targetStore['name'],
                    'quantity' => $transferQty,
                    'source_quantity_after' => $newSourceQty
                ]
            ]);
            flash("Moved {$transferQty} units from {$fromRegion['name']} into your van stock ({$targetStore['name']}).");
        }
    } catch (Throwable $e) {
        qt_rethrow_bad_input($e);
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        Logger::error('region_storage_transfer_failed', $e, [
            'product_id' => $prodId,
            'from_region_id' => $fromRegionId,
            'to_region_id' => $destinationType === 'region' ? $toRegionId : null,
            'destination_type' => $destinationType,
            'van_store_id' => $destinationType === 'van' ? $vanStoreId : null,
            'quantity' => $transferQty
        ]);
        flash('Unable to transfer stock at this time. Please try again.', 'error');
    }

    rs_redirect($fromRegionId);
}

// Handle location update (moves all stock from current location to new location)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_location') {
    csrf_verify();
    $prodId = req_int($_POST, 'prod_id');
    $regionId = req_int($_POST, 'region_id');
    $currentLocation = rs_sanitize_location($_POST['current_location'] ?? '');
    $newLocation = rs_sanitize_location($_POST['location'] ?? '');
    $region = rs_get_region($pdo, $regionId);
    $product = rs_get_product($pdo, $prodId);
    if (!$product) {
        flash('Select a valid product to update its location.', 'error');
        rs_redirect($regionId);
    }

    if (!$region) {
        flash('Select a valid region to update.', 'error');
        rs_redirect(0);
    }

    if ($currentLocation === $newLocation) {
        flash('New location must be different from current location.', 'error');
        rs_redirect($regionId);
    }

    try {
        $pdo->beginTransaction();
// Find the source row by (product_id, region_id, location)
        if ($regionStorageHasRegionId) {
            $stmt = $pdo->prepare('SELECT id, quantity, location FROM region_storage WHERE product_id = ? AND region_id = ? AND location = ? FOR UPDATE');
            $stmt->execute([$prodId, $regionId, $currentLocation]);
        } else {
            $stmt = $pdo->prepare('SELECT id, quantity, location FROM region_storage WHERE product_id = ? AND region = ? AND location = ? FOR UPDATE');
            $stmt->execute([$prodId, $region['name'], $currentLocation]);
        }
        $source = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$source) {
            throw new RuntimeException('Region storage entry not found at this location.');
        }

        $moveQty = (int)$source['quantity'];
// Check if destination location already exists
        if ($regionStorageHasRegionId) {
            $destStmt = $pdo->prepare('SELECT id, quantity FROM region_storage WHERE product_id = ? AND region_id = ? AND location = ? FOR UPDATE');
            $destStmt->execute([$prodId, $regionId, $newLocation]);
        } else {
            $destStmt = $pdo->prepare('SELECT id, quantity FROM region_storage WHERE product_id = ? AND region = ? AND location = ? FOR UPDATE');
            $destStmt->execute([$prodId, $region['name'], $newLocation]);
        }
        $destination = $destStmt->fetch(PDO::FETCH_ASSOC);
// Delete the source row
        $deleteStmt = $pdo->prepare('DELETE FROM region_storage WHERE id = ?');
        $deleteStmt->execute([$source['id']]);
// Insert or update destination
        if ($destination) {
            $newDestQty = (int)$destination['quantity'] + $moveQty;
            $updateDest = $pdo->prepare('UPDATE region_storage SET quantity = ?, region = ? WHERE id = ?');
            $updateDest->execute([$newDestQty, $region['name'], $destination['id']]);
        } else {
            if ($regionStorageHasRegionId) {
                $insertDest = $pdo->prepare('INSERT INTO region_storage (product_id, region_id, region, location, quantity) VALUES (?, ?, ?, ?, ?)');
                $insertDest->execute([$prodId, $region['id'], $region['name'], $newLocation, $moveQty]);
            } else {
                $insertDest = $pdo->prepare('INSERT INTO region_storage (product_id, region, location, quantity) VALUES (?, ?, ?, ?)');
                $insertDest->execute([$prodId, $region['name'], $newLocation, $moveQty]);
            }
        }

        $pdo->commit();
        $sourceLocDisplay = $currentLocation !== '' ? $currentLocation : '(no location)';
        $destLocDisplay = $newLocation !== '' ? $newLocation : '(no location)';
        AuditLogger::log('region_storage.location_update', [
            'entity_type' => 'product',
            'entity_id' => $product['id'],
            'description' => sprintf('Moved %d units of %s from "%s" to "%s" in %s', $moveQty, $product['name'] ?? ('Product #' . $product['id']), $sourceLocDisplay, $destLocDisplay, $region['name']),
            'metadata' => [
                'product_id' => $product['id'],
                'product_sku' => $product['sku'],
                'region_id' => $region['id'],
                'region_name' => $region['name'],
                'previous_location' => $currentLocation,
                'new_location' => $newLocation,
                'quantity_moved' => $moveQty
            ]
        ]);
        flash("Moved {$moveQty} units from \"{$sourceLocDisplay}\" to \"{$destLocDisplay}\".");
    } catch (Throwable $e) {
        qt_rethrow_bad_input($e);
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        Logger::error('region_storage_location_update_failed', $e, [
            'product_id' => $prodId,
            'region_id' => $regionId
        ]);
        flash('Unable to update location right now. Please try again.', 'error');
    }

    rs_redirect($regionId);
}

// Fetch products for the adjustment form
$products = $pdo->query('SELECT id, product_id, barcode, name FROM products WHERE is_tracked_asset = 0 ORDER BY name ASC')->fetchAll();
// Fetch current region storage for display
$current_region_storage = [];
$selected_display_region_id = 0;
$selected_display_region_name = '';
if (isset($_GET['view_region_id']) && (int)$_GET['view_region_id'] > 0) {
    $selected_display_region_id = (int)$_GET['view_region_id'];
} elseif (!empty($regions)) {
    $selected_display_region_id = (int)$regions[0]['id'];
}

$selectedRegion = rs_get_region($pdo, $selected_display_region_id);
if ($selectedRegion) {
    $selected_display_region_name = $selectedRegion['name'];
    if ($regionStorageHasRegionId) {
        $stmt = $pdo->prepare('SELECT rs.quantity, rs.location, rs.product_id, p.name, p.product_id as sku, p.image_path
                                 FROM region_storage rs
                                 JOIN products p ON p.id = rs.product_id
                                 WHERE rs.region_id = ? AND rs.quantity > 0 AND p.is_tracked_asset = 0
                                 ORDER BY p.name ASC');
            $stmt->execute([$selectedRegion['id']]);
    } else {
        $stmt = $pdo->prepare("SELECT rs.quantity, rs.location, rs.product_id, p.name, p.product_id as sku, p.image_path
                                 FROM region_storage rs
                                 JOIN products p ON p.id = rs.product_id
                                 WHERE rs.region = ? AND rs.quantity > 0 AND p.is_tracked_asset = 0
                                 ORDER BY p.name ASC");
        $stmt->execute([$selectedRegion['name']]);
    }
    $current_region_storage = $stmt->fetchAll();
}

require __DIR__ . '/views/region_storage.view.php';
