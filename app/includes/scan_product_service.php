<?php

declare(strict_types=1);

/**
 * Exact barcode takes precedence over SKU and order number; raw values precede colon-normalized
 * values within each lookup. Keep the legacy partial-SKU fallback last and require more than
 * four normalized characters so short scans cannot match a broad product range.
 *
 * @return array<string, mixed>|null
 */
function scan_product_lookup(PDO $pdo, string $barcode): ?array
{
    $normalized = str_replace(':', '', $barcode);
    $candidates = [$barcode];
    if ($normalized !== '' && $normalized !== $barcode) {
        $candidates[] = $normalized;
    }
    $select = 'SELECT id, product_id, barcode, name, image_path, asset_type, manufacturer, status, '
        . 'color, order_number, created_at, modified_at FROM products';
    $queries = [
        $select . ' WHERE barcode = ? LIMIT 2',
        $select . ' WHERE product_id = ? LIMIT 1',
        $select . ' WHERE order_number = ? LIMIT 1',
    ];
    foreach ($queries as $sql) {
        $product = scan_product_exact_lookup($pdo, $sql, $candidates);
        if ($product !== null) {
            return $product;
        }
    }
    if (strlen($normalized) > 4) {
        // An underscore is a valid barcode character, but must stay literal in SQL LIKE.
        $stmt = $pdo->prepare($select . " WHERE REPLACE(product_id, ':', '') LIKE ? ESCAPE '!' LIMIT 1");
        $stmt->execute(['%' . str_replace('_', '!_', $normalized) . '%']);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        return $product === false ? null : $product;
    }
    return null;
}

/**
 * Run a fixed exact-match query against raw then normalized candidates. Barcode queries fetch
 * two rows to detect ambiguity; the legacy SKU/order lookups fetch at most one row.
 *
 * @param list<string> $candidates
 * @return array<string, mixed>|null
 */
function scan_product_exact_lookup(PDO $pdo, string $sql, array $candidates): ?array
{
    $stmt = $pdo->prepare($sql);
    foreach ($candidates as $candidate) {
        $stmt->execute([$candidate]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($matches) > 1) {
            throw new ConflictException('Barcode matched multiple products. Please search by SKU.');
        }
        if ($matches !== []) {
            return $matches[0];
        }
    }
    return null;
}
