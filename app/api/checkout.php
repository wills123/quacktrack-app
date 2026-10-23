<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/input_schemas/checkout.php';
require_once __DIR__ . '/../includes/checkout_support.php';
require_login();
// Security headers for API endpoints
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

const CHECKOUT_MIN_NOTE_LIMIT = 50;
const CHECKOUT_MAX_NOTE_LIMIT = 5000;
const CHECKOUT_ORDER_NUMBER_BYTES = 8;

/** A checkout rule the user broke (422); the message is shown to the user as is. */
class CheckoutValidationException extends ClientErrorException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message, array $details = [])
    {
        parent::__construct($message, 422, $details);
    }
}

$pdo = db();
$current_user_id = (int)$_SESSION['uid'];
$action = req_action();

function generate_secure_order_number(): string
{
    // random_bytes() throws when the system has no randomness; that is a server fault, not a reason to fall back to a guessable number.
    return 'ORD-' . strtoupper(bin2hex(random_bytes(CHECKOUT_ORDER_NUMBER_BYTES)));
}

/** The longest note (order or item) the settings allow, kept within sane bounds. */
function checkout_note_limit(): int
{
    return min(max((int)get_app_setting('stock_max_note_length', '500'), CHECKOUT_MIN_NOTE_LIMIT), CHECKOUT_MAX_NOTE_LIMIT);
}

function checkout_text_length(string $text): int
{
    return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
}

/** Changing actions take POST only: a GET carries no CSRF check, so it must never reach them. */
function checkout_require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        api_error('Method not allowed', 405);
    }
}

function checkout_csrf(): void
{
    if (!csrf_verify_api()) {
        throw new ForbiddenException('Invalid CSRF token');
    }
}

/** The purchasing email to use: the one chosen, else the user's preference, else the default. */
function checkout_resolve_email_id(PDO $pdo, int $userId, int $chosen): int
{
    if ($chosen <= 0) {
        $stmt = $pdo->prepare('
            SELECT COALESCE(u.preferred_purchasing_email_id,
                           (SELECT id FROM purchasing_emails WHERE is_default = 1 AND is_active = 1 LIMIT 1),
                           (SELECT id FROM purchasing_emails WHERE is_active = 1 ORDER BY sort_order LIMIT 1))
            FROM users u
            WHERE u.id = ?
        ');
        $stmt->execute([$userId]);
        $chosen = (int)$stmt->fetchColumn();
    }
    if ($chosen <= 0) {
        throw new CheckoutValidationException('Please select a purchasing email.');
    }
    $check = $pdo->prepare('SELECT id FROM purchasing_emails WHERE id = ? AND is_active = 1');
    $check->execute([$chosen]);
    if (!$check->fetch()) {
        throw new CheckoutValidationException('Selected purchasing email is not available.');
    }
    return $chosen;
}

/** The name of the ship-to store. */
function checkout_store_name(PDO $pdo, int $storeId): string
{
    if ($storeId <= 0) {
        throw new CheckoutValidationException('Please select a store before sending the order.');
    }
    $stmt = $pdo->prepare('SELECT name FROM stores WHERE id = ?');
    $stmt->execute([$storeId]);
    $store = $stmt->fetch();
    if (!$store) {
        throw new CheckoutValidationException('The selected store is no longer valid. Please refresh and try again.');
    }
    return (string)$store['name'];
}

/** @return list<array<string, mixed>> cart rows with stock summed before joining, so locations cannot duplicate order quantities */
function checkout_cart_rows(PDO $pdo, int $userId, string $region): array
{
    $stmt = $pdo->prepare('
        SELECT ci.id, ci.quantity, ci.notes, ci.bill_to_store_id,
               ci.receive_to_van_qty, ci.receive_to_region_qty,
               p.id as product_id, p.product_id as sku, p.name, p.status,
               COALESCE(vs.quantity, 0) as van_qty,
               COALESCE(rs.quantity, 0) as region_qty,
               s.name as bill_to_store_name
        FROM cart_items ci
        JOIN products p ON ci.product_id = p.id
        LEFT JOIN stores s ON ci.bill_to_store_id = s.id
        LEFT JOIN (
            SELECT product_id, SUM(quantity) AS quantity
            FROM van_stock WHERE user_id = ? GROUP BY product_id
        ) vs ON vs.product_id = p.id
        LEFT JOIN (
            SELECT product_id, SUM(quantity) AS quantity
            FROM region_storage WHERE region = ? GROUP BY product_id
        ) rs ON rs.product_id = p.id
        WHERE ci.user_id = ?
        ORDER BY p.name ASC
    ');
    $stmt->execute([$userId, $region, $userId]);
    return $stmt->fetchAll();
}

/**
 * How many units of a cart row go to the van and to region storage; a split that does not add up to
 * the quantity means everything goes to the van.
 *
 * @param array<string, mixed> $row
 * @return array{0: int, 1: int}
 */
function checkout_receive_split(array $row): array
{
    $quantity = (int)$row['quantity'];
    $van = (int)($row['receive_to_van_qty'] ?? 0);
    $region = (int)($row['receive_to_region_qty'] ?? 0);
    if ($van + $region !== $quantity) {
        return [$quantity, 0];
    }
    if ($region > 0 && empty($_SESSION['region_id'])) {
        throw new CheckoutValidationException('Cannot receive to region storage - you are not assigned to a region.');
    }
    return [$van, $region];
}

/**
 * One cart row as an order line, with its destination split checked and its note bounded.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function checkout_order_line(array $row, int $noteLimit): array
{
    $notes = trim((string)($row['notes'] ?? ''));
    if (checkout_text_length($notes) > $noteLimit) {
        throw new CheckoutValidationException("Item notes cannot exceed {$noteLimit} characters.");
    }
    [$van, $region] = checkout_receive_split($row);
    return [
        'id' => $row['id'],
        'product_id' => (int)$row['product_id'],
        'sku' => $row['sku'] ?? '',
        'name' => $row['name'] ?? '',
        'status' => $row['status'] ?? '',
        'quantity' => (int)$row['quantity'],
        'notes' => $notes,
        'van_qty' => (int)$row['van_qty'],
        'region_qty' => (int)$row['region_qty'],
        'receive_to_van_qty' => $van,
        'receive_to_region_qty' => $region,
        'bill_to_store_id' => $row['bill_to_store_id'] ? (int)$row['bill_to_store_id'] : null,
        'bill_to_store_name' => $row['bill_to_store_name'] ?? null,
    ];
}

/**
 * Cart rows merged per (product, billing store), so duplicate rows cannot break the order's unique key.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function checkout_merge_lines(array $rows, int $noteLimit): array
{
    $merged = [];
    foreach ($rows as $row) {
        $line = checkout_order_line($row, $noteLimit);
        $key = $line['product_id'] . '_' . (int)$line['bill_to_store_id'];
        if (!isset($merged[$key])) {
            $merged[$key] = $line;
            continue;
        }
        $merged[$key]['quantity'] += $line['quantity'];
        $merged[$key]['receive_to_van_qty'] += $line['receive_to_van_qty'];
        $merged[$key]['receive_to_region_qty'] += $line['receive_to_region_qty'];
        $merged[$key]['notes'] = implode("\n", array_filter([$merged[$key]['notes'], $line['notes']], static fn (string $note): bool => $note !== ''));
    }
    return array_values($merged);
}

/**
 * Refuse lines that are inactive or that stock on hand cannot cover (when checkout validation is on).
 *
 * @param list<array<string, mixed>> $lines
 */
function checkout_check_stock(array $lines): void
{
    if (!is_checkout_validation_enabled()) {
        return;
    }
    $problems = [];
    foreach ($lines as $line) {
        $available = $line['van_qty'] + $line['region_qty'];
        if ($line['status'] !== 'active') {
            $problems[] = "{$line['sku']} (inactive)";
        } elseif ($available < $line['quantity']) {
            $problems[] = "{$line['sku']} (need {$line['quantity']}, have {$available})";
        }
    }
    if ($problems !== []) {
        throw new CheckoutValidationException('Please adjust the highlighted items before sending the order.', ['items' => $problems]);
    }
}

/**
 * Write the order and its lines and empty the cart, in the caller's transaction.
 *
 * @param list<array<string, mixed>> $lines
 * @return array{0: int, 1: string} the order id and number
 */
function checkout_write_order(PDO $pdo, int $userId, int $storeId, string $notes, array $lines): array
{
    $toVan = (int)(array_sum(array_column($lines, 'receive_to_van_qty')) > 0);
    $toRegion = (int)(array_sum(array_column($lines, 'receive_to_region_qty')) > 0);
    if ($toVan + $toRegion === 0) {
        throw new CheckoutValidationException('Please select at least one delivery destination.');
    }
    $maxPerItem = get_max_order_quantity();
    foreach ($lines as $line) {
        if ($line['quantity'] > $maxPerItem) {
            throw new CheckoutValidationException('Item limits exceeded. Adjust quantities for: ' . $line['sku'], ['items' => [$line['sku']]]);
        }
    }
    $orderNumber = generate_secure_order_number();
    $pdo->prepare('
        INSERT INTO purchase_orders (user_id, order_number, store_id, notes, total_items, status, receive_to_van, receive_to_region)
        VALUES (?, ?, ?, ?, ?, "sent", ?, ?)
    ')->execute([$userId, $orderNumber, $storeId, $notes, count($lines), $toVan, $toRegion]);
    $orderId = (int)$pdo->lastInsertId();
    $itemStmt = $pdo->prepare('
        INSERT INTO purchase_order_items
            (order_id, product_id, quantity, notes, bill_to_store_id, bill_to_store_key, receive_to_van_qty, receive_to_region_qty)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    foreach ($lines as $line) {
        $itemStmt->execute([
            $orderId, $line['product_id'], $line['quantity'], $line['notes'], $line['bill_to_store_id'],
            (int)$line['bill_to_store_id'], $line['receive_to_van_qty'], $line['receive_to_region_qty'],
        ]);
    }
    $pdo->prepare('DELETE FROM cart_items WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('UPDATE purchase_orders SET sent_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$orderId]);
    return [$orderId, $orderNumber];
}

/**
 * Validate the cart and create the order. Nothing here sends mail or calls Snipe-IT: the response
 * carries a mailto link for the user's own mail client.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed> the response sent to the client (and stored for replays of the key)
 */
function checkout_place_order(PDO $pdo, int $userId, array $in): array
{
    $noteLimit = checkout_note_limit();
    $notes = (string)$in['notes'];
    if (checkout_text_length($notes) > $noteLimit) {
        throw new CheckoutValidationException("Order notes cannot exceed {$noteLimit} characters.");
    }
    $storeId = (int)$in['store_id'];
    $storeName = checkout_store_name($pdo, $storeId);
    $emailId = checkout_resolve_email_id($pdo, $userId, (int)$in['purchasing_email_id']);
    $region = (string)($_SESSION['region'] ?? '');
    $lines = checkout_merge_lines(checkout_cart_rows($pdo, $userId, $region), $noteLimit);
    if ($lines === []) {
        throw new CheckoutValidationException('Your cart is empty. Add items before sending the order.');
    }
    checkout_check_stock($lines);

    $pdo->beginTransaction();
    [$orderId, $orderNumber] = checkout_write_order($pdo, $userId, $storeId, $notes, $lines);
    $pdo->commit();

    $email = generate_email_from_template($emailId, $orderNumber, $storeName, $lines, $notes, $region ?: 'Unknown', $storeId);
    $pdo->prepare('UPDATE users SET preferred_purchasing_email_id = ? WHERE id = ?')->execute([$emailId, $userId]);
    return [
        'success' => true,
        'order_id' => $orderId,
        'order_number' => $orderNumber,
        'mailto_link' => $email['mailto_link'],
        'email_subject' => $email['subject'],
        'email_body' => $email['body'],
        'purchasing_email' => $email['purchasing_email'],
        'cc_addresses' => $email['cc_addresses'],
        'message' => 'Order created successfully',
    ];
}

function checkout_api_create_order(PDO $pdo, int $userId): void
{
    checkout_require_post();
    checkout_csrf();
    $in = checkout_input('create_order');
    $key = checkout_idempotency_key((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''), (string)($in['idempotency_key'] ?? ''));
    $fingerprint = checkout_request_fingerprint($in);
    checkout_acquire_user_lock($pdo, $userId);
    try {
        $response = checkout_idempotency_lookup($userId, $key, $fingerprint);
        if ($response === null) {
            checkout_enforce_rate_limit(checkout_rate_limit_bucket($userId));
            $response = checkout_place_order($pdo, $userId, $in);
            checkout_idempotency_store($userId, $key, $fingerprint, $response);
        }
        echo json_encode($response);
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack(); // never release the lock while this request's order is still uncommitted
        }
        checkout_release_user_lock($pdo, $userId);
    }
}

try {
    switch ($action) {
        case 'create_order':
            checkout_api_create_order($pdo, $current_user_id);
            break;
        default:
            throw new BadRequestException('Invalid action');
    }
} catch (Exception $e) {
    // api_fail() answers every ClientError itself (status, details, endpoint context), so this
    // catch does not hand them to the boundary via qt_rethrow_bad_input().
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    api_fail($e, 'checkout_api_error', [
        'action' => $action,
        'user_id' => $current_user_id,
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    ], 'Checkout operation failed. Please try again.');
}
