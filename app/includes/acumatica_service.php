<?php

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/AcumaticaClient.php';

/**
 * The Acumatica lookups and purchase-order creation of api/acumatica.php. The client does the talking; this file
 * decides what a lookup answers and what is recorded locally once a purchase order exists in Acumatica.
 */

/** The integration is switched off or its configuration is unusable: nothing was sent to Acumatica. */
final class AcumaticaUnavailableException extends RuntimeException
{
}

/**
 * The client for this request, set to act for $userId.
 *
 * @throws AcumaticaUnavailableException
 */
function acumatica_client(int $userId): AcumaticaClient
{
    if (env('ACUMATICA_ENABLED', '0') !== '1') {
        throw new AcumaticaUnavailableException('Acumatica integration is not enabled.');
    }
    try {
        $client = AcumaticaClient::getInstance();
    } catch (RuntimeException $e) {
        Logger::error('acumatica_not_configured', $e);
        throw new AcumaticaUnavailableException('Acumatica integration is not configured.', 0, $e);
    }
    $client->setCurrentUserId($userId);
    return $client;
}

/**
 * On-hand quantities of the requested items. Items that do not exist are left out; items whose lookup failed are
 * listed in "unavailable". When every lookup failed Acumatica is down, not empty.
 *
 * @param list<string> $inventoryIds
 * @return array{levels: object, unavailable: list<string>}
 * @throws AcumaticaProviderException
 */
function acumatica_stock_levels(AcumaticaClient $client, array $inventoryIds): array
{
    $failed = [];
    $levels = $client->getStockLevels($inventoryIds, $failed);
    if ($failed !== [] && count($failed) === count(array_unique($inventoryIds))) {
        throw new AcumaticaProviderException('Every stock lookup failed.');
    }
    return ['levels' => (object)$levels, 'unavailable' => $failed];
}

/**
 * A record the lookup found.
 *
 * @param array<string, mixed>|null $record
 * @return array<string, mixed>
 * @throws NotFoundException
 */
function acumatica_found(?array $record, string $what): array
{
    return $record ?? throw new NotFoundException($what . ' not found in Acumatica.');
}

/**
 * Create a purchase order in Acumatica (always on Hold) and record it locally. Once Acumatica has the order a local
 * recording failure is logged with the order number and reported as "recorded: false", never retried by the caller:
 * a second request would create a second purchase order.
 *
 * @param array<string, mixed> $in parsed input (vendor_id, source_type, source_id?, description?, vendor_ref?, lines)
 * @return array{order_nbr: string, status: string, recorded: bool}
 * @throws AcumaticaProviderException
 */
function acumatica_create_po(AcumaticaClient $client, PDO $pdo, int $userId, array $in): array
{
    $lines = acumatica_po_lines($in['lines']);
    $order = $client->createPurchaseOrder(
        $in['vendor_id'],
        $lines,
        ($in['description'] ?? '') !== '' ? $in['description'] : null,
        ($in['vendor_ref'] ?? '') !== '' ? $in['vendor_ref'] : null
    );
    $orderNbr = acumatica_order_number($order, $userId);
    $status = $order['Status']['value'] ?? null;
    $status = is_string($status) && strlen($status) <= 30 ? $status : 'Hold';
    $recorded = acumatica_record_po($pdo, $userId, $in, ['order_nbr' => $orderNbr, 'status' => $status, 'lines' => $lines]);
    return ['order_nbr' => $orderNbr, 'status' => $status, 'recorded' => $recorded];
}

/**
 * The order number Acumatica assigned, as the local record can hold it.
 *
 * @param array<string, mixed> $order
 * @throws AcumaticaProviderException
 */
function acumatica_order_number(array $order, int $userId): string
{
    $orderNbr = $order['OrderNbr']['value'] ?? null;
    if (!is_string($orderNbr) || $orderNbr === '' || strlen($orderNbr) > 20) {
        Logger::error('acumatica_po_without_order_number', new RuntimeException('Acumatica returned no usable order number'), ['user_id' => $userId]);
        throw new AcumaticaProviderException('Acumatica did not return an order number.', 502);
    }
    return $orderNbr;
}

/**
 * The request lines in the client's shape.
 *
 * @param list<array<string, mixed>> $lines
 * @return array<int, array{inventoryId: string, qty: float, warehouseId: string|null, unitCost: float|null}>
 */
function acumatica_po_lines(array $lines): array
{
    return array_map(static fn(array $line): array => [
        'inventoryId' => $line['inventory_id'],
        'qty' => $line['qty'],
        'warehouseId' => ($line['warehouse_id'] ?? '') !== '' ? $line['warehouse_id'] : null,
        'unitCost' => $line['unit_cost'] ?? null,
    ], $lines);
}

/**
 * Save the local record of a purchase order Acumatica now holds.
 *
 * @param array<string, mixed> $in
 * @param array{order_nbr: string, status: string, lines: array<int, array<string, mixed>>} $order
 */
function acumatica_record_po(PDO $pdo, int $userId, array $in, array $order): bool
{
    try {
        $pdo->prepare('
            INSERT INTO acumatica_purchase_orders
                (acumatica_order_nbr, acumatica_vendor_id, status, source_type, source_id, requested_by, line_summary, last_synced_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ')->execute([$order['order_nbr'], $in['vendor_id'], $order['status'], $in['source_type'], $in['source_id'] ?? null, $userId, json_encode($order['lines'])]);
        return true;
    } catch (Throwable $e) {
        Logger::error('acumatica_po_not_recorded', $e, ['order_nbr' => $order['order_nbr'], 'user_id' => $userId]);
        return false;
    }
}
