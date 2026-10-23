<?php

/**
 * Kit items and the asset lookups of api/kit-templates.php: the Snipe-IT models of a kit, a serial or asset tag
 * check before dispatch, the add-item search over the catalog and Snipe-IT, and adding, removing and resizing items.
 * Changes run in one transaction with the kit (or the item) locked, so concurrent adds get distinct sort orders.
 */

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/input_schemas/kit_templates.php';
require_once __DIR__ . '/snipe_catalog_service.php';

/** Assets read per page while looking for an exact serial or asset tag. */
const KIT_TEMPLATES_LOOKUP_PAGE = 100;

/** Search matches read before a serial is called too broad to check. */
const KIT_TEMPLATES_LOOKUP_MAX = 500;

/** Snipe-IT models the add-item search returns (the catalog part stops at 20 products). */
const KIT_TEMPLATES_SEARCH_MODELS = 30;

/**
 * Run $work in a transaction and return its result; any failure rolls everything back.
 *
 * @template T
 * @param callable(): T $work
 * @return T
 */
function kit_templates_transaction(PDO $pdo, callable $work): mixed
{
    $pdo->beginTransaction();
    try {
        $result = $work();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** A scalar provider field as text; anything else is ''. */
function kit_templates_text(mixed $value): string
{
    return is_scalar($value) ? trim((string)$value) : '';
}

/**
 * The record rows of a Snipe-IT list answer, with its total.
 *
 * @param array<string, mixed>|null $answer
 * @return array{rows: list<array<mixed>>, total: int}
 * @throws SnipeProviderException
 */
function kit_templates_snipe_list(?array $answer, string $what): array
{
    if ($answer === null || !is_array($answer['rows'] ?? null) || !is_int($answer['total'] ?? null)) {
        throw new SnipeProviderException("Snipe-IT did not return the {$what}.");
    }
    foreach ($answer['rows'] as $row) {
        if (!is_array($row)) {
            throw new SnipeProviderException("Snipe-IT returned an unusable row in the {$what}.");
        }
    }
    return ['rows' => array_values($answer['rows']), 'total' => $answer['total']];
}

/**
 * The Snipe-IT model items of a kit, for the ship form's serial fields.
 *
 * @return list<array{kit_item_id: int, snipe_model_id: int, snipe_model_name: string|null, quantity: int}>
 * @throws NotFoundException
 */
function kit_templates_snipe_items(PDO $pdo, int $kitId): array
{
    $kit = $pdo->prepare('SELECT id FROM kit_templates WHERE id = ?');
    $kit->execute([$kitId]);
    if ($kit->fetchColumn() === false) {
        throw new NotFoundException('Kit template not found.');
    }
    $stmt = $pdo->prepare(
        "SELECT id, snipe_model_id, snipe_model_name, quantity FROM kit_template_items
         WHERE kit_template_id = ? AND item_type = 'snipe_model' ORDER BY sort_order ASC, id ASC"
    );
    $stmt->execute([$kitId]);
    return array_map(static fn(array $row): array => [
        'kit_item_id' => (int)$row['id'],
        'snipe_model_id' => (int)$row['snipe_model_id'],
        'snipe_model_name' => $row['snipe_model_name'] === null ? null : (string)$row['snipe_model_name'],
        'quantity' => (int)$row['quantity'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * The assets whose serial or asset tag is exactly $serial (ignoring case), read from Snipe-IT's search a page at a
 * time.
 *
 * @return list<array<mixed>>
 * @throws SnipeProviderException
 */
function kit_templates_exact_assets(SnipeItClient $client, string $serial): array
{
    $needle = mb_strtolower($serial);
    $matches = [];
    $offset = 0;
    do {
        $page = kit_templates_snipe_list($client->listAssets(['search' => $serial, 'limit' => KIT_TEMPLATES_LOOKUP_PAGE, 'offset' => $offset]), 'serial search');
        foreach ($page['rows'] as $asset) {
            $keys = [mb_strtolower(kit_templates_text($asset['serial'] ?? null)), mb_strtolower(kit_templates_text($asset['asset_tag'] ?? null))];
            if (in_array($needle, $keys, true)) {
                $matches[] = $asset;
            }
        }
        $offset += KIT_TEMPLATES_LOOKUP_PAGE;
    } while ($page['rows'] !== [] && $offset < $page['total'] && $offset < KIT_TEMPLATES_LOOKUP_MAX);
    if ($offset < $page['total'] && $page['rows'] !== []) {
        throw new SnipeProviderException('Snipe-IT matched too many assets to check that serial number.');
    }
    return $matches;
}

/**
 * The answer about one serial number or asset tag: whether it exists once, is of the expected model and can be
 * dispatched (not assigned and in a deployable status).
 *
 * @return array<string, mixed>
 * @throws SnipeProviderException
 */
function kit_templates_lookup(SnipeItClient $client, string $serial, int $modelId): array
{
    $matches = kit_templates_exact_assets($client, $serial);
    $answer = [
        'found' => $matches !== [], 'ambiguous' => count($matches) > 1, 'available' => false, 'wrong_model' => false,
        'asset_id' => null, 'asset_name' => '', 'serial' => $serial, 'asset_tag' => '', 'status_label' => '',
        'message' => 'Not found — no asset with that serial or asset tag exists in Snipe-IT.',
    ];
    if ($answer['ambiguous']) {
        return ['message' => 'More than one asset in Snipe-IT has that serial or asset tag. Enter the unique asset tag.'] + $answer;
    }
    return $matches === [] ? $answer : kit_templates_lookup_asset($matches[0], $modelId) + $answer;
}

/**
 * The array under $key of a provider row, or [] when it is missing or not an array.
 *
 * @param array<mixed> $row
 * @return array<mixed>
 */
function kit_templates_ref(array $row, string $key): array
{
    return is_array($row[$key] ?? null) ? $row[$key] : [];
}

/**
 * The name of the record under $key of a provider row, '' when there is none.
 *
 * @param array<mixed> $row
 */
function kit_templates_ref_name(array $row, string $key): string
{
    return kit_templates_text(kit_templates_ref($row, $key)['name'] ?? null);
}

/**
 * The model id of an asset, 0 when it has none.
 *
 * @param array<mixed> $asset
 */
function kit_templates_model_id(array $asset): int
{
    $id = kit_templates_ref($asset, 'model')['id'] ?? null;
    return is_int($id) && $id > 0 ? $id : 0;
}

/**
 * The id of a provider asset.
 *
 * @param array<mixed> $asset
 * @throws SnipeProviderException
 */
function kit_templates_asset_id(array $asset): int
{
    $id = $asset['id'] ?? null;
    if (!is_int($id) || $id <= 0) {
        throw new SnipeProviderException('Snipe-IT returned an asset without a valid id.');
    }
    return $id;
}

/**
 * The lookup fields of the one matching asset.
 *
 * @param array<mixed> $asset
 * @return array<string, mixed>
 * @throws SnipeProviderException
 */
function kit_templates_lookup_asset(array $asset, int $modelId): array
{
    $id = kit_templates_asset_id($asset);
    $wrongModel = $modelId > 0 && kit_templates_model_id($asset) !== $modelId;
    $reason = $wrongModel
        ? 'Wrong model — this asset is a "' . (kit_templates_ref_name($asset, 'model') ?: 'unknown') . '", not the expected model.'
        : kit_templates_unavailable_reason($asset);
    $status = kit_templates_ref_name($asset, 'status_label');
    $fields = [];
    foreach (['asset_name' => 'name', 'serial' => 'serial', 'asset_tag' => 'asset_tag'] as $out => $field) {
        $fields[$out] = kit_templates_text($asset[$field] ?? null);
    }
    return $fields + [
        'available' => $reason === null,
        'wrong_model' => $wrongModel,
        'asset_id' => $id,
        'status_label' => $status,
        'message' => $reason ?? 'Ready to deploy — ' . ($status ?: 'available') . '.',
    ];
}

/**
 * Why an asset cannot be dispatched, or null when it can: it must be unassigned and in a deployable status.
 *
 * @param array<mixed> $asset
 */
function kit_templates_unavailable_reason(array $asset): ?string
{
    $status = kit_templates_ref($asset, 'status_label');
    $meta = strtolower(kit_templates_text($status['status_meta'] ?? $status['status_type'] ?? null));
    if (!empty($asset['assigned_to']) || $meta === 'deployed') {
        $to = kit_templates_ref_name($asset, 'assigned_to');
        return 'Already checked out' . ($to !== '' ? ' to ' . $to : '') . '. Check it in Snipe-IT before dispatching.';
    }
    return $meta === 'deployable' ? null : 'Not deployable — its status is ' . (kit_templates_ref_name($asset, 'status_label') ?: 'unknown') . '.';
}

/**
 * Catalog products whose name, SKU or manufacturer contains $q (wildcards in $q are literal).
 *
 * @return list<array<string, mixed>>
 */
function kit_templates_search_catalog(PDO $pdo, string $q): array
{
    $like = '%' . addcslashes($q, '\\%_') . '%';
    $stmt = $pdo->prepare(
        'SELECT id, name, product_id AS sku, manufacturer, is_tracked_asset FROM products
         WHERE name LIKE ? OR product_id LIKE ? OR manufacturer LIKE ? ORDER BY name ASC LIMIT 20'
    );
    $stmt->execute([$like, $like, $like]);
    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $product) {
        $tracked = (int)$product['is_tracked_asset'] === 1;
        $results[] = [
            'source' => 'catalog',
            'item_type' => $tracked ? 'product_tracked' : 'product',
            'id' => (int)$product['id'],
            'name' => (string)$product['name'],
            'sub' => implode(' · ', array_filter(['SKU: ' . $product['sku'], (string)$product['manufacturer']], static fn(string $part): bool => $part !== '' && $part !== 'SKU: ')),
            'badge' => $tracked ? 'Tracked' : 'Catalog',
        ];
    }
    return $results;
}

/**
 * One search result for a Snipe-IT asset, keyed by its model (or its name when it has no model); null when it has no
 * name to show.
 *
 * @param array<mixed> $asset
 * @return array{0: string, 1: array<string, mixed>}|null
 */
function kit_templates_snipe_result(array $asset): ?array
{
    $modelId = kit_templates_model_id($asset);
    $modelName = kit_templates_ref_name($asset, 'model');
    $name = $modelName ?: kit_templates_text($asset['name'] ?? null);
    if ($name === '') {
        return null;
    }
    $sub = [
        kit_templates_ref_name($asset, 'manufacturer'),
        kit_templates_ref_name($asset, 'category'),
        'Model: ' . ($modelName ?: 'N/A'),
    ];
    return [$modelId > 0 ? 'model_' . $modelId : 'asset_' . $name, [
        'source' => 'snipe', 'item_type' => 'snipe_model', 'id' => $modelId, 'name' => $name,
        'sub' => implode(' · ', array_filter($sub, static fn(string $part): bool => $part !== '')),
        'badge' => 'Snipe-IT', 'snipe_model_id' => $modelId, 'snipe_model_name' => $name,
    ]];
}

/**
 * Snipe-IT models matching $q: assets are searched and kept once per model, so each device type appears once.
 *
 * @return list<array<string, mixed>>
 * @throws SnipeProviderException
 */
function kit_templates_search_snipe(SnipeItClient $client, string $q): array
{
    $list = kit_templates_snipe_list($client->listAssets(['search' => $q, 'limit' => 50, 'offset' => 0]), 'asset search');
    $results = [];
    foreach ($list['rows'] as $asset) {
        [$key, $result] = kit_templates_snipe_result($asset) ?? ['', null];
        if ($result !== null && !isset($results[$key])) {
            $results[$key] = $result;
        }
    }
    return array_slice(array_values($results), 0, KIT_TEMPLATES_SEARCH_MODELS);
}

/**
 * The add-item search: catalog products first, then Snipe-IT models, each by name. When Snipe-IT is off or does not
 * answer, the catalog results still come back and snipe_available says the list is incomplete.
 *
 * @return array{results: list<array<string, mixed>>, snipe_available: bool}
 */
function kit_templates_search(PDO $pdo, ?SnipeItClient $client, string $q): array
{
    if ($q === '') {
        return ['results' => [], 'snipe_available' => $client !== null];
    }
    $snipe = [];
    $available = $client !== null;
    if ($client !== null) {
        try {
            $snipe = kit_templates_search_snipe($client, $q);
        } catch (SnipeProviderException $e) {
            Logger::error('kit_search_snipe_error', $e);
            $available = false;
        }
    }
    $byName = static fn(array $a, array $b): int => strcmp((string)$a['name'], (string)$b['name']);
    $catalog = kit_templates_search_catalog($pdo, $q);
    usort($catalog, $byName);
    usort($snipe, $byName);
    return ['results' => array_merge($catalog, $snipe), 'snipe_available' => $available];
}

/**
 * Add an item at the end of a kit and return the stored row.
 *
 * @param array<string, mixed> $in parsed add_item input
 * @return array<string, mixed>
 * @throws NotFoundException
 */
function kit_templates_add_item(PDO $pdo, array $in): array
{
    return kit_templates_transaction($pdo, static function () use ($pdo, $in): array {
        $kit = $pdo->prepare('SELECT id FROM kit_templates WHERE id = ? FOR UPDATE');
        $kit->execute([$in['kit_template_id']]);
        if ($kit->fetchColumn() === false) {
            throw new NotFoundException('Kit template not found.');
        }
        if (isset($in['product_id'])) {
            kit_templates_require_product($pdo, $in['product_id']);
        }
        $next = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM kit_template_items WHERE kit_template_id = ?');
        $next->execute([$in['kit_template_id']]);
        $insert = $pdo->prepare(
            'INSERT INTO kit_template_items (kit_template_id, item_type, product_id, snipe_model_id, snipe_model_name, quantity, notes, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $in['kit_template_id'], $in['item_type'] === 'snipe_model' ? 'snipe_model' : 'product', $in['product_id'] ?? null,
            $in['snipe_model_id'] ?? null, $in['snipe_model_name'] ?? null, $in['quantity'], ($in['notes'] ?? '') === '' ? null : $in['notes'], (int)$next->fetchColumn(),
        ]);
        $row = $pdo->prepare(
            'SELECT kti.*, p.name AS product_name, p.product_id AS product_sku, p.manufacturer AS product_manufacturer,
                    COALESCE(p.is_tracked_asset, 0) AS is_tracked_asset
             FROM kit_template_items kti LEFT JOIN products p ON p.id = kti.product_id WHERE kti.id = ?'
        );
        $row->execute([(int)$pdo->lastInsertId()]);
        return $row->fetch(PDO::FETCH_ASSOC);
    });
}

/**
 * Make sure a product exists and keep it until the transaction ends.
 *
 * @throws NotFoundException
 */
function kit_templates_require_product(PDO $pdo, int $productId): void
{
    $product = $pdo->prepare('SELECT id FROM products WHERE id = ? FOR SHARE');
    $product->execute([$productId]);
    if ($product->fetchColumn() === false) {
        throw new NotFoundException('Product not found.');
    }
}

/**
 * Remove one kit item.
 *
 * @throws NotFoundException
 */
function kit_templates_remove_item(PDO $pdo, int $itemId): void
{
    $stmt = $pdo->prepare('DELETE FROM kit_template_items WHERE id = ?');
    $stmt->execute([$itemId]);
    if ($stmt->rowCount() === 0) {
        throw new NotFoundException('Item not found.');
    }
}

/**
 * Set one kit item's quantity.
 *
 * @throws NotFoundException
 */
function kit_templates_update_quantity(PDO $pdo, int $itemId, int $quantity): void
{
    kit_templates_transaction($pdo, static function () use ($pdo, $itemId, $quantity): void {
        $item = $pdo->prepare('SELECT id FROM kit_template_items WHERE id = ? FOR UPDATE');
        $item->execute([$itemId]);
        if ($item->fetchColumn() === false) {
            throw new NotFoundException('Item not found.');
        }
        $pdo->prepare('UPDATE kit_template_items SET quantity = ? WHERE id = ?')->execute([$quantity, $itemId]);
    });
}
