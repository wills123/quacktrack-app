<?php

/**
 * The service desk's review queue of tracked-asset scans that matched nothing in Snipe-IT (api/snipe-asset-review.php).
 * A review is resolved by creating the asset in Snipe-IT, linking an existing one, or dismissing it. Creating and
 * linking count as receiving one unit of the order item, so they take the order's row lock first, the lock the direct
 * receipts of api/orders.php take, and decide on the free units, the review and the person's right to receive under it.
 * The changes also hold a named lock on the review, so two requests for one review never both create a Snipe-IT asset.
 * Lock order: the review's named lock, the order row, the review row.
 */

declare(strict_types=1);

require_once __DIR__ . '/orders_status_receipts_assets.php';
require_once __DIR__ . '/snipe_response.php';
require_once __DIR__ . '/snipe_detail_history_service.php';
require_once __DIR__ . '/SnipeTokenManager.php';
require_once __DIR__ . '/input_schemas/snipe_review.php';

/** Seconds a change waits for another change of the same review. */
const SNIPE_REVIEW_LOCK_WAIT = 10;

const SNIPE_REVIEW_FIND = 'SELECT * FROM snipe_asset_review_queue WHERE id = ?';
const SNIPE_REVIEW_FIND_LOCKED = 'SELECT * FROM snipe_asset_review_queue WHERE id = ? FOR UPDATE';

/** The asset exists in Snipe-IT but no local receipt could be saved; the review keeps its id for a retry. */
final class SnipeReviewUnlinkedException extends RuntimeException
{
}

/**
 * Run $work while holding the review's named lock.
 *
 * @template T
 * @param callable(): T $work
 * @return T
 * @throws ConflictException when another change of the review holds it for too long
 */
function snipe_review_locked(PDO $pdo, int $reviewId, callable $work): mixed
{
    $name = 'qt_snipe_review_' . $reviewId;
    $take = $pdo->prepare('SELECT GET_LOCK(?, ?)');
    $take->execute([$name, SNIPE_REVIEW_LOCK_WAIT]);
    if ((int)$take->fetchColumn() !== 1) {
        throw new ConflictException('This review item is being handled by someone else. Try again in a moment.');
    }
    try {
        return $work();
    } finally {
        try {
            $pdo->prepare('DO RELEASE_LOCK(?)')->execute([$name]);
        } catch (Throwable $e) {
            Logger::error('snipe_review_unlock_failed', $e, ['lock' => $name]);
        }
    }
}

/**
 * The review row; with $lock it is read FOR UPDATE.
 * qm-ok: flag-param - the same row is read, only its locking differs
 *
 * @return array<string, mixed>
 * @throws NotFoundException
 */
function snipe_review_find(PDO $pdo, int $reviewId, bool $lock = false): array
{
    $stmt = $pdo->prepare($lock ? SNIPE_REVIEW_FIND_LOCKED : SNIPE_REVIEW_FIND);
    $stmt->execute([$reviewId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new NotFoundException('This review item does not exist.');
}

/**
 * @param array<string, mixed> $review
 * @throws ConflictException
 */
function snipe_review_require_pending(array $review): void
{
    if ($review['status'] !== 'pending') {
        throw new ConflictException('This review item is already resolved.');
    }
}

/** Whoever may receive the order may resolve its reviews; the service desk (assets.manage) may receive any order here. */
function snipe_review_viewer(int $actorId): OrdersViewer
{
    return new OrdersViewer($actorId, (int)($_SESSION['region_id'] ?? 0), false, false, has_permission('orders.manage') || has_permission('assets.manage'));
}

/**
 * The checks of a review that can still receive a unit: the person may receive the order (403), the item is a tracked
 * asset (422) with a free unit on an order that is not cancelled (409), and the review is pending (409).
 *
 * @param array<string, mixed> $review
 * @param array<string, mixed> $order
 * @return array<string, mixed> the order item
 * @throws ClientErrorException
 */
function snipe_review_check(PDO $pdo, array $review, array $order, OrdersViewer $viewer): array
{
    $item = orders_status_receipts_item($pdo, (int)$review['order_item_id']);
    orders_status_receipts_check_item($pdo, $item, $order, $viewer);
    snipe_review_require_pending($review);
    return $item;
}

/**
 * A review that can still receive a unit, checked without locks (a quick answer before any Snipe-IT request).
 *
 * @return array<string, mixed>
 * @throws ClientErrorException
 */
function snipe_review_open(PDO $pdo, int $reviewId, OrdersViewer $viewer): array
{
    $review = snipe_review_find($pdo, $reviewId);
    snipe_review_check($pdo, $review, orders_status_receipts_order($pdo, (int)$review['order_id']), $viewer);
    return $review;
}

/**
 * Record $asset as one unit of the review's order item and resolve the review as $resolution['status'], all under the
 * order's lock. A null note keeps the review's note. Returns the destination and the order's new status.
 *
 * @param array<string, mixed> $asset
 * @param array{review_id: int, status: string, note: ?string} $resolution
 * @return array{destination: array<string, mixed>, order_status: string}
 * @throws ClientErrorException|SnipeProviderException
 */
function snipe_review_commit(PDO $pdo, OrdersViewer $viewer, array $asset, array $resolution): array
{
    ['review_id' => $reviewId, 'status' => $status, 'note' => $note] = $resolution;
    // Read outside the transaction (a review never changes its order): a plain read inside it would fix the snapshot
    // before the order lock is taken, and the receipts counted under the lock would miss one committed meanwhile.
    $orderId = (int)snipe_review_find($pdo, $reviewId)['order_id'];
    return orders_browse_transaction($pdo, static function () use ($pdo, $orderId, $reviewId, $viewer, $asset, $status, $note): array {
        $order = orders_status_receipts_locked_order($pdo, $orderId);
        $review = snipe_review_find($pdo, $reviewId, true);
        $item = snipe_review_check($pdo, $review, $order, $viewer);
        $destination = orders_status_receipts_destination($pdo, $order);
        orders_status_receipts_insert_asset($pdo, $item, $asset, $destination, $viewer->userId);
        $pdo->prepare('UPDATE snipe_asset_review_queue SET status = ?, snipe_asset_id = ?, review_note = IF(? IS NULL, review_note, NULLIF(?, \'\')),
            reviewed_by = ?, resolved_at = NOW() WHERE id = ?')->execute([$status, $asset['id'], $note, $note, $viewer->userId, $reviewId]);
        return ['destination' => $destination, 'order_status' => syncOrderReceiptStatus($pdo, (int)$order['id'])];
    });
}

/**
 * Point the received asset at its destination in Snipe-IT, unless it is already assigned there (the receipt checked
 * that). A failure is reported, never raised: the receipt stands.
 *
 * @param array<string, mixed> $asset
 * @param array<string, mixed> $destination
 * @return array<string, mixed>
 */
function snipe_review_sync(PDO $pdo, array $asset, array $destination, int $actorId, string $note): array
{
    if (isset($asset['assigned_to'])) {
        return ['synced' => true, 'code' => 'already_assigned', 'message' => 'Snipe-IT asset is already assigned to this destination.'];
    }
    return orders_status_receipts_sync($pdo, (int)$asset['id'], ['note' => $note] + $destination, $actorId);
}

/**
 * `link_existing`: receive the Snipe-IT asset whose serial or tag is exactly the identifier.
 *
 * @param array{review_id: int, identifier: string} $in
 * @return array<string, mixed>
 * @throws ClientErrorException|SnipeProviderException
 */
function snipe_review_link(PDO $pdo, SnipeItClient $client, OrdersViewer $viewer, array $in): array
{
    $review = snipe_review_open($pdo, $in['review_id'], $viewer);
    $asset = orders_status_receipts_find_asset($client, $in['identifier']) ?? throw new NotFoundException('No Snipe-IT asset matched that identifier.');
    $done = snipe_review_commit($pdo, $viewer, $asset, ['review_id' => $in['review_id'], 'status' => 'linked', 'note' => null]);
    $note = 'Existing asset linked from Quack Track review for order #' . (int)$review['order_id'];
    return ['message' => 'Existing Snipe-IT asset linked to the order.', 'asset_id' => (int)$asset['id'], 'order_status' => $done['order_status'],
        'snipe_sync' => snipe_review_sync($pdo, $asset, $done['destination'], $viewer->userId, $note)];
}

/**
 * `create_asset`: create the asset in Snipe-IT with the person's own token and receive it. An asset an earlier attempt
 * created but could not link is linked instead of creating another one.
 *
 * @param array{review_id: int, name: string, asset_tag: string, serial: string, model_id: int, status_id: int, review_note: string} $in
 * @return array<string, mixed>
 * @throws ClientErrorException|SnipeProviderException|SnipeReviewUnlinkedException
 */
function snipe_review_create(PDO $pdo, SnipeItClient $client, OrdersViewer $viewer, array $in): array
{
    $review = snipe_review_open($pdo, $in['review_id'], $viewer);
    $recovered = snipe_review_recovered_asset($client, $review);
    $asset = $recovered ?? snipe_review_new_asset($client, $viewer->userId, $in);
    try {
        $done = snipe_review_commit($pdo, $viewer, $asset, ['review_id' => $in['review_id'], 'status' => 'created', 'note' => $in['review_note']]);
    } catch (Throwable $e) {
        snipe_review_unlinked($pdo, $in['review_id'], (int)$asset['id'], $e);
    }
    $note = 'Asset created from Quack Track review for order #' . (int)$review['order_id'];
    return ['message' => $recovered === null ? 'Asset created in Snipe-IT and linked to the order.' : 'Previously created Snipe-IT asset was linked to the order.',
        'asset_id' => (int)$asset['id'], 'order_status' => $done['order_status'], 'snipe_sync' => snipe_review_sync($pdo, $asset, $done['destination'], $viewer->userId, $note)];
}

/**
 * The asset an earlier attempt created for this review, when Snipe-IT still has it; null when there is none.
 *
 * @param array<string, mixed> $review
 * @return array<string, mixed>|null
 * @throws SnipeProviderException
 */
function snipe_review_recovered_asset(SnipeItClient $client, array $review): ?array
{
    $id = (int)($review['snipe_asset_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    $answer = $client->request('GET', '/hardware/' . $id);
    if (snipe_review_confirmed_missing($answer)) {
        return null;
    }
    return snipe_review_recovery_record($answer, $id);
}

/**
 * Validate the recovered provider record before it can become a receipt.
 * @param array<string, mixed> $answer
 * @return array<string, mixed>
 * @throws SnipeProviderException
 */
function snipe_review_recovery_record(array $answer, int $id): array
{
    $asset = $answer['data'] ?? null;
    if (($answer['error'] ?? null) !== null || !is_array($asset) || ($asset['status'] ?? null) === 'error') {
        throw new SnipeProviderException('Asset lookup failed.');
    }
    orders_status_receipts_validate_asset($asset);
    if (orders_status_receipts_provider_id($asset['id']) !== $id) {
        throw new SnipeProviderException('Snipe-IT returned another asset.');
    }
    $asset['serial'] ??= '';
    return $asset;
}

/**
 * A supported missing-asset reply must not also carry an asset identity or payload.
 * @param array<string, mixed> $answer
 */
function snipe_review_confirmed_missing(array $answer): bool
{
    if (!snipe_detail_history_says_missing($answer)) {
        return false;
    }
    $data = $answer['data'] ?? null;
    return !is_array($data) || (!array_key_exists('id', $data) && ($data['payload'] ?? null) === null);
}

/**
 * Create the asset in Snipe-IT, after checking that no asset has its serial or tag yet.
 *
 * @param array{name: string, asset_tag: string, serial: string, model_id: int, status_id: int, review_note: string} $in
 * @return array{id: int, serial: string, asset_tag: string}
 * @throws ClientErrorException|SnipeProviderException
 */
function snipe_review_new_asset(SnipeItClient $client, int $actorId, array $in): array
{
    foreach (array_filter([$in['serial'], $in['asset_tag']], static fn(string $value): bool => $value !== '') as $identifier) {
        if (orders_status_receipts_find_asset($client, $identifier) !== null) {
            throw new ConflictException('Snipe-IT already has an asset with that serial or tag. Link the existing asset instead.');
        }
    }
    $token = SnipeTokenManager::getValid($actorId);
    if ($token === null) {
        throw new ClientErrorException('Reconnect your Snipe-IT account before creating an asset.', 401, ['snipe_auth_required' => true]);
    }
    $answer = SnipeItClient::forToken($token, $actorId)->request('POST', '/hardware', ['name' => $in['name'], 'asset_tag' => $in['asset_tag'],
        'serial' => $in['serial'], 'model_id' => $in['model_id'], 'status_id' => $in['status_id'], 'notes' => $in['review_note']]);
    if (($answer['status'] ?? 0) === 401) {
        SnipeTokenManager::revokeIfCurrent($actorId, $token);
        throw new ClientErrorException('Snipe-IT no longer accepts your sign-in. Please reconnect your account.', 401, ['snipe_auth_required' => true]);
    }
    return ['id' => snipe_review_created_id($answer), 'serial' => $in['serial'], 'asset_tag' => $in['asset_tag']];
}

/**
 * The id of the asset Snipe-IT says it created; anything that is not a confirmed asset is unusable.
 *
 * @param array<string, mixed> $answer
 * @throws UnprocessableException|SnipeProviderException
 */
function snipe_review_created_id(array $answer): int
{
    $status = (int)($answer['status'] ?? 0);
    $data = is_array($answer['data'] ?? null) ? $answer['data'] : [];
    snipe_review_refusal($status, $data);
    if (!snipe_review_confirmed($status, $data)) {
        throw new SnipeProviderException('Snipe-IT did not confirm the new asset (HTTP ' . $status . ').');
    }
    return orders_status_receipts_provider_id($data['payload']['id'] ?? null);
}

/**
 * Whether a write answer is a 2xx that says success and carries the record.
 *
 * @param array<mixed> $data
 */
function snipe_review_confirmed(int $status, array $data): bool
{
    return $status >= 200 && $status < 300 && ($data['status'] ?? null) === 'success' && is_array($data['payload'] ?? null);
}

/**
 * A refusal (an error status on a 2xx or 4xx answer) is a 422 with Snipe-IT's own message.
 *
 * @param array<mixed> $data
 * @throws UnprocessableException
 */
function snipe_review_refusal(int $status, array $data): void
{
    if ($status < 200 || $status >= 500 || ($data['status'] ?? null) !== 'error') {
        return;
    }
    $why = snipe_messages_to_string($data['messages'] ?? $data['message'] ?? '');
    throw new UnprocessableException('Snipe-IT would not create the asset' . ($why !== '' ? ': ' . mb_substr($why, 0, 500) : '.'));
}

/**
 * The asset exists in Snipe-IT but the receipt was refused or could not be saved: keep its id on the review (while it is
 * pending) so a retry links it, log it for reconciliation, and answer with the refusal's own status when there is one.
 *
 * @throws ClientErrorException|SnipeReviewUnlinkedException
 */
function snipe_review_unlinked(PDO $pdo, int $reviewId, int $assetId, Throwable $error): never
{
    try {
        $pdo->prepare("UPDATE snipe_asset_review_queue SET snipe_asset_id = ? WHERE id = ? AND status = 'pending'")->execute([$assetId, $reviewId]);
    } catch (Throwable $markError) {
        Logger::error('snipe_asset_review_recovery_marker', $markError, ['review_id' => $reviewId, 'asset_id' => $assetId]);
    }
    Logger::error('snipe_asset_review_unlinked', $error, ['review_id' => $reviewId, 'asset_id' => $assetId]);
    $message = 'Snipe-IT asset ' . $assetId . ' exists, but it was not linked to the order';
    if ($error instanceof ClientErrorException) {
        throw new ClientErrorException($message . ': ' . $error->getMessage(), $error->getHttpStatus(), $error->getDetails(), $error);
    }
    throw new SnipeReviewUnlinkedException($message . '. Try again to link it, or contact an administrator.', 0, $error);
}

/**
 * `options`: the form defaults for a review (the product's Snipe-IT template, else the product name and the scanned
 * identifier) and the models and status labels to choose from.
 *
 * @return array<string, mixed>
 * @throws ClientErrorException|SnipeProviderException
 */
function snipe_review_options(PDO $pdo, SnipeItClient $client, OrdersViewer $viewer, int $reviewId): array
{
    $review = snipe_review_open($pdo, $reviewId, $viewer);
    $stmt = $pdo->prepare('SELECT p.name AS product_name, t.template_name, t.model_id, t.status_id, t.asset_name_template, t.asset_tag_prefix, t.default_notes
        FROM products p LEFT JOIN snipe_asset_templates t ON t.product_id = p.id AND t.is_active = 1 WHERE p.id = ?');
    $stmt->execute([(int)$review['product_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $identifier = (string)$review['identifier'];
    $product = (string)($row['product_name'] ?? '');
    $name = trim(str_replace(['{{product_name}}', '{{serial}}'], [$product, $identifier], (string)($row['asset_name_template'] ?? '')));
    $statuses = snipe_review_statuses($client);
    return ['review_id' => $reviewId, 'identifier' => $identifier, 'product_name' => $product, 'has_template' => !empty($row['template_name']),
        'defaults' => ['name' => $name !== '' ? $name : $product, 'asset_tag' => trim((string)($row['asset_tag_prefix'] ?? '') . $identifier), 'serial' => $identifier,
            'model_id' => (int)($row['model_id'] ?? 0), 'status_id' => snipe_review_default_status((int)($row['status_id'] ?? 0), $statuses),
            'note' => (string)($row['default_notes'] ?? '')],
        'models' => array_map(static fn(array $model): array => ['id' => $model['id'], 'name' => $model['name']], snipe_review_reference($client, 'models')),
        'statuses' => $statuses];
}

/**
 * The rows of a Snipe-IT reference list as id, name and type; every row must carry a usable id.
 *
 * @return list<array{id: int, name: string, type: string}>
 * @throws SnipeProviderException
 */
function snipe_review_reference(SnipeItClient $client, string $kind): array
{
    $list = $client->referenceList($kind);
    if (!is_array($list['rows'] ?? null)) {
        throw new SnipeProviderException('Snipe-IT did not list its ' . $kind . '.');
    }
    return array_map(static fn(mixed $row): array => snipe_review_reference_row($kind, $row), array_values($list['rows']));
}

/**
 * One reference row as id, name (Unnamed when it has none) and lower-case type ('' when it has none).
 *
 * @return array{id: int, name: string, type: string}
 * @throws SnipeProviderException
 */
function snipe_review_reference_row(string $kind, mixed $row): array
{
    if (!is_array($row)) {
        throw new SnipeProviderException('Snipe-IT listed a malformed ' . $kind . ' row.');
    }
    $name = $row['name'] ?? null;
    $type = $row['type'] ?? $row['status_type'] ?? '';
    return ['id' => orders_status_receipts_provider_id($row['id'] ?? null), 'name' => is_string($name) && $name !== '' ? $name : 'Unnamed',
        'type' => is_string($type) ? strtolower($type) : ''];
}

/**
 * The status labels; only a deployable one lets the asset be checked out to its destination afterwards.
 *
 * @return list<array{id: int, name: string, deployable: bool}>
 * @throws SnipeProviderException
 */
function snipe_review_statuses(SnipeItClient $client): array
{
    return array_map(static fn(array $status): array => ['id' => $status['id'],
        'name' => $status['name'] . ($status['type'] !== '' && $status['type'] !== 'deployable' ? ' (not deployable)' : ''),
        'deployable' => $status['type'] === 'deployable'], snipe_review_reference($client, 'statuslabels'));
}

/**
 * The template's status, else a deployable one (preferring one whose name contains "ready"), else none (0).
 *
 * @param list<array{id: int, name: string, deployable: bool}> $statuses
 */
function snipe_review_default_status(int $templateStatus, array $statuses): int
{
    if ($templateStatus > 0) {
        return $templateStatus;
    }
    $deployable = array_values(array_filter($statuses, static fn(array $status): bool => $status['deployable']));
    foreach ($deployable as $status) {
        if (stripos($status['name'], 'ready') !== false) {
            return $status['id'];
        }
    }
    return $deployable[0]['id'] ?? 0;
}

/**
 * `dismiss`: close a pending review without receiving anything, under the order's lock like the other resolutions.
 *
 * @param array{review_id: int, review_note: string} $in
 * @throws ClientErrorException
 */
function snipe_review_dismiss(PDO $pdo, int $actorId, array $in): void
{
    $orderId = (int)snipe_review_find($pdo, $in['review_id'])['order_id'];
    orders_browse_transaction($pdo, static function () use ($pdo, $orderId, $actorId, $in): void {
        orders_status_receipts_locked_order($pdo, $orderId);
        snipe_review_require_pending(snipe_review_find($pdo, $in['review_id'], true));
        $pdo->prepare("UPDATE snipe_asset_review_queue SET status = 'dismissed', review_note = NULLIF(?, ''), reviewed_by = ?, resolved_at = NOW() WHERE id = ?")
            ->execute([$in['review_note'], $actorId, $in['review_id']]);
    });
}
