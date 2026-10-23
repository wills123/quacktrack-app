<?php

/**
 * Disposing of a received tracked asset (api/tracked-assets.php): check it in to Snipe-IT and set its status with the
 * person's own Snipe-IT token (so Snipe-IT's audit log names them), then record the disposition locally.
 *
 * Snipe-IT and the database cannot share a transaction, so the steps are built to be repeated instead: only one
 * disposal of a receipt runs at a time (a named lock), Snipe-IT is changed before the local record, the check-in is
 * skipped when the asset is already checked in, and the status update sets an absolute value. When Snipe-IT was changed
 * but the local record could not be saved, the receipt stays active, the gap is logged for reconciliation, and doing
 * the same disposal again finishes it.
 */

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/snipe_catalog_service.php';

/** Seconds to wait for another disposal of the same receipt to finish. */
const TRACKED_ASSETS_LOCK_WAIT = 10;

/** Snipe-IT refused or failed a step; the message is safe to show and says what was (not) changed. */
final class TrackedAssetProviderException extends RuntimeException
{
}

/** Snipe-IT was changed but the local record could not be saved; the gap is logged and repeating the disposal finishes it. */
final class TrackedAssetUnsavedException extends RuntimeException
{
}

/**
 * Run $work while holding the receipt's disposal lock.
 *
 * @template T
 * @param callable(): T $work
 * @return T
 * @throws ConflictException when another disposal holds it for too long
 */
function tracked_assets_locked(PDO $pdo, int $receiptId, callable $work): mixed
{
    $name = 'qt_tracked_asset_receipt_' . $receiptId;
    $lock = $pdo->prepare('SELECT GET_LOCK(?, ?)');
    $lock->execute([$name, TRACKED_ASSETS_LOCK_WAIT]);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new ConflictException('This asset receipt is being changed by someone else. Try again in a moment.');
    }
    try {
        return $work();
    } finally {
        tracked_assets_unlock($pdo, $name);
    }
}

/**
 * Release a disposal lock. A failure is logged, never raised: the disposal has finished (or failed) on its own terms,
 * and MySQL releases a named lock when the connection ends anyway.
 */
function tracked_assets_unlock(PDO $pdo, string $name): void
{
    try {
        $pdo->prepare('DO RELEASE_LOCK(?)')->execute([$name]);
    } catch (Throwable $e) {
        Logger::error('tracked_asset_unlock_failed', $e, ['lock' => $name]);
    }
}

/**
 * The receipt's Snipe-IT asset, while the receipt is still active.
 *
 * @throws NotFoundException|ConflictException
 */
function tracked_assets_active_asset(PDO $pdo, int $receiptId): int
{
    $receipt = $pdo->prepare('SELECT snipe_asset_id, disposition FROM purchase_order_asset_receipts WHERE id = ?');
    $receipt->execute([$receiptId]);
    $row = $receipt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new NotFoundException('This asset receipt does not exist.');
    }
    if ($row['disposition'] !== 'active') {
        throw new ConflictException('This asset receipt is already resolved.');
    }
    return (int)$row['snipe_asset_id'];
}

/**
 * Check the asset in (unless Snipe-IT says it is already checked in) and set the status of the disposition's type
 * when Snipe-IT has such a label. Returns whether the status was set.
 *
 * @throws TrackedAssetProviderException
 */
function tracked_assets_update_snipe(SnipeItClient $service, SnipeItClient $personal, int $assetId, string $statusType, string $note): bool
{
    $asset = $service->getAsset($assetId);
    if ($asset === null || ($asset['id'] ?? null) !== $assetId) {
        throw new TrackedAssetProviderException('Snipe-IT did not return this asset. Nothing was changed.');
    }
    if (!empty($asset['assigned_to']) && tracked_assets_refused($personal->checkinAsset($assetId, ['note' => $note]))) {
        throw new TrackedAssetProviderException('Snipe-IT could not check in this asset. The local disposition was not changed.');
    }
    $statusId = tracked_assets_status_id($service, $statusType);
    if ($statusId === null) {
        return false;
    }
    if (tracked_assets_refused($personal->updateAsset($assetId, ['status_id' => $statusId, 'notes' => $note]))) {
        throw new TrackedAssetProviderException('Snipe-IT checked in the asset but could not update its status. The local disposition was not changed.');
    }
    return true;
}

/**
 * Whether a Snipe-IT write did not go through: no usable answer, or one with an error status.
 *
 * @param array<mixed>|null $answer
 */
function tracked_assets_refused(?array $answer): bool
{
    return !is_string($answer['status'] ?? null) || strtolower($answer['status']) !== 'success';
}

/** The first status label of $type. */
function tracked_assets_status_id(SnipeItClient $service, string $type): ?int
{
    foreach ((array)($service->referenceList('statuslabels')['rows'] ?? []) as $label) {
        if (is_array($label) && tracked_assets_label_type($label) === $type && is_int($label['id'] ?? null) && $label['id'] > 0) {
            return $label['id'];
        }
    }
    return null;
}

/**
 * A status label's type: Snipe-IT reports it as "type"; older versions as status_type or status_meta.
 *
 * @param array<mixed> $label
 */
function tracked_assets_label_type(array $label): string
{
    return strtolower((string)($label['type'] ?? $label['status_type'] ?? $label['status_meta'] ?? ''));
}

/**
 * Store the disposition while the receipt is locked and still active; another path (an order item deleted with its
 * receipts, a disposal outside this endpoint) may have changed it while Snipe-IT was being updated.
 *
 * @param array{receipt_id: int, disposition: string, note: string} $in
 * @throws RuntimeException when the receipt is gone or already resolved
 */
function tracked_assets_save(PDO $pdo, array $in, int $actorId): void
{
    $pdo->beginTransaction();
    try {
        $current = $pdo->prepare('SELECT disposition FROM purchase_order_asset_receipts WHERE id = ? FOR UPDATE');
        $current->execute([$in['receipt_id']]);
        if ($current->fetchColumn() !== 'active') {
            throw new RuntimeException('The receipt was not active any more when the disposal was recorded.');
        }
        $pdo->prepare('UPDATE purchase_order_asset_receipts SET disposition = ?, disposition_note = ?, disposed_by = ?, disposed_at = NOW() WHERE id = ?')
            ->execute([$in['disposition'], $in['note'] === '' ? null : $in['note'], $actorId, $in['receipt_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Record the disposition. Called after Snipe-IT was changed, so a failure here is logged as a gap to reconcile; the
 * receipt stays active and the same disposal can be repeated.
 *
 * @param array{receipt_id: int, disposition: string, note: string} $in
 */
function tracked_assets_record(PDO $pdo, array $in, int $actorId, int $assetId): void
{
    try {
        tracked_assets_save($pdo, $in, $actorId);
    } catch (Throwable $e) {
        Logger::error('tracked_asset_reconcile_needed', $e, [
            'receipt_id' => $in['receipt_id'], 'snipe_asset_id' => $assetId, 'disposition' => $in['disposition'], 'user_id' => $actorId,
        ]);
        throw new TrackedAssetUnsavedException('Snipe-IT was updated but Quack Track could not save the change. Try again: repeating it finishes the change.', 0, $e);
    }
}
