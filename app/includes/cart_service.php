<?php

declare(strict_types=1);

/** A cart rule the user broke (400); the message is shown to the user as is. */
class CartValidationException extends ClientErrorException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message, array $details = [])
    {
        parent::__construct($message, 400, $details);
    }
}

/**
 * qm-ok: flag-param - region availability is an account capability used to validate allocations
 *
 * @return array{van: int, region: int}
 */
function normalize_receive_split(int $quantity, ?int $van_qty, ?int $region_qty, bool $has_region): array
{

    if ($quantity <= 0) {
        return ['van' => 0, 'region' => 0];
    }

    if ($van_qty === null && $region_qty === null) {
        return ['van' => $quantity, 'region' => 0];
    }

    if (!$has_region && ($region_qty ?? 0) > 0) {
        throw new CartValidationException('Region storage is not available for your account.');
    }

    $van_qty = $van_qty ?? 0;
    $region_qty = $region_qty ?? 0;
    if (($van_qty + $region_qty) !== $quantity) {
        throw new CartValidationException('Receive quantities must sum to the cart quantity.');
    }

    return ['van' => $van_qty, 'region' => $region_qty];
}

/** Every cart mutation requires POST, even when there are no input fields. */
function cart_require_write_request(string $action): void
{
    if (!in_array($action, ['add', 'update', 'remove', 'clear', 'bulk_add'], true)) {
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new ClientErrorException('Method not allowed', 405);
    }
    if (!csrf_verify_api()) {
        throw new ForbiddenException('Invalid CSRF token');
    }
}

function cart_validate_store(PDO $pdo, int $storeId): void
{
    if ($storeId === 0) {
        return;
    }
    $check = $pdo->prepare('SELECT id FROM stores WHERE id = ?');
    $check->execute([$storeId]);
    if (!$check->fetch()) {
        throw new CartValidationException('Invalid billing store');
    }
}

/**
 * Shared SQL values of a prevalidated cart item, in column order.
 *
 * @param array<string, mixed> $input
 * @param array{van: int, region: int} $split
 * @return list<mixed>
 */
function cart_item_values(array $input, array $split): array
{
    return [$input['quantity'], $input['notes'], $input['bill_to_store_id'] ?: null, $split['van'], $split['region']];
}

/**
 * Run the cart line update; moving a line to a billing store the same product already has a line for is refused
 * (the key column keeps one line per product and store).
 *
 * @param list<mixed> $params
 * @throws ConflictException
 */
function cart_update_line(PDOStatement $stmt, array $params): void
{
    try {
        $stmt->execute($params);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            throw new ConflictException('This product is already in your cart for that store. Change that line instead.', [], $e);
        }
        throw $e;
    }
}

/**
 * A cart line whose billing store was changed by an older cart edit still carries the key of its first store (0 when it
 * had none): adding the product for that key would merge into it and bill the new units to the edited store, and
 * adding it for the store it is billed to would make a second line. Before adding for $storeKey, move every drifted
 * line of the product that is keyed to it or billed to it to the key of the store it is billed to.
 *
 * @throws ConflictException when the cart already has another line for that product and store
 */
function cart_resync_key(PDO $pdo, int $userId, int $productId, int $storeKey): void
{
    // A plain read first: drifted lines are rare, and a locking UPDATE on every add would make concurrent adds of the
    // same line deadlock on the key's index range.
    $drifted = $pdo->prepare(
        'SELECT id, bill_to_store_key FROM cart_items WHERE user_id = ? AND product_id = ? AND (bill_to_store_key = ? OR COALESCE(bill_to_store_id, 0) = ?)
            AND COALESCE(bill_to_store_id, 0) <> bill_to_store_key'
    );
    $drifted->execute([$userId, $productId, $storeKey, $storeKey]);
    $resync = $pdo->prepare('UPDATE cart_items SET bill_to_store_key = COALESCE(bill_to_store_id, 0) WHERE id = ? AND bill_to_store_key = ?');
    try {
        foreach ($drifted->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $resync->execute([(int)$line['id'], (int)$line['bill_to_store_key']]);
        }
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            throw new ConflictException('Your cart has two lines for this product billed to the same store. Merge them in the cart, then add again.', [], $e);
        }
        throw $e;
    }
}
