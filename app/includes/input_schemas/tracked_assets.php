<?php

/**
 * Body of api/tracked-assets.php `dispose` (see includes/input_schema.php): the receipt, what happened to the asset,
 * and an optional note (purchase_order_asset_receipts.disposition_note).
 */

declare(strict_types=1);

require_once __DIR__ . '/../api_errors.php';
require_once __DIR__ . '/../input_schema.php';

/** Dispositions a receipt can be given, and the Snipe-IT status type each one sets. */
const TRACKED_ASSETS_DISPOSITIONS = ['returned' => 'deployable', 'repair' => 'undeployable', 'retired' => 'archived', 'lost' => 'archived'];

/** @return array<string, array<string, mixed>> */
function tracked_assets_input_schema(): array
{
    return [
        'receipt_id' => in_int(min: 1, max: 2147483647, required: true, label: 'Receipt'),
        'disposition' => in_enum(array_keys(TRACKED_ASSETS_DISPOSITIONS), required: true, label: 'Disposition'),
        'note' => in_string(max: 500, default: '', label: 'Note', column: 'purchase_order_asset_receipts.disposition_note'),
    ];
}

/**
 * @return array{receipt_id: int, disposition: string, note: string}
 * @throws BadRequestException|UnprocessableException
 */
function tracked_assets_input(): array
{
    $values = input_parse(tracked_assets_input_schema(), $_POST);
    return ['receipt_id' => $values['receipt_id'], 'disposition' => $values['disposition'], 'note' => $values['note']];
}
