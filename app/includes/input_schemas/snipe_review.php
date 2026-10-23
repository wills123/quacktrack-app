<?php

/**
 * Input of api/snipe-asset-review.php (see includes/input_schema.php), one function per action: `options` reads the
 * query, the three changes read the form body. The asset review page posts its whole form with every action, so fields
 * an action does not name are ignored. Snipe-IT model and status ids are unsigned, so they may go past the signed range
 * of the local ids.
 */

declare(strict_types=1);

require_once __DIR__ . '/../api_errors.php';
require_once __DIR__ . '/../input_schema.php';

/** Each action and the one method it answers. */
const SNIPE_REVIEW_METHODS = ['options' => 'GET', 'create_asset' => 'POST', 'link_existing' => 'POST', 'dismiss' => 'POST'];

/** Largest id a Snipe-IT record can have (an unsigned INT). */
const SNIPE_REVIEW_PROVIDER_ID_MAX = 4294967295;

/** @return array<string, array<string, array<string, mixed>>> */
function snipe_review_input_schemas(): array
{
    $review = in_int(min: 1, max: 2147483647, required: true, label: 'Review');
    $note = in_string(max: 500, default: '', label: 'Review note', column: 'snipe_asset_review_queue.review_note');
    return [
        'options' => ['review_id' => $review],
        'create_asset' => [
            'review_id' => $review,
            'name' => in_string(max: 255, required: true, label: 'Asset name'),
            'asset_tag' => in_string(max: 255, required: true, label: 'Asset tag', column: 'purchase_order_asset_receipts.asset_tag'),
            'serial' => in_string(max: 255, default: '', label: 'Serial number', column: 'purchase_order_asset_receipts.serial'),
            'model_id' => in_int(min: 1, max: SNIPE_REVIEW_PROVIDER_ID_MAX, required: true, label: 'Model'),
            'status_id' => in_int(min: 1, max: SNIPE_REVIEW_PROVIDER_ID_MAX, required: true, label: 'Status'),
            'review_note' => $note,
        ],
        'link_existing' => [
            'review_id' => $review,
            'identifier' => in_string(max: 255, required: true, label: 'Asset identifier', column: 'snipe_asset_review_queue.identifier'),
        ],
        'dismiss' => ['review_id' => $review, 'review_note' => $note],
    ];
}

/**
 * @return array{review_id: int}
 * @throws BadRequestException|UnprocessableException
 */
function snipe_review_options_input(): array
{
    $values = input_parse(snipe_review_input_schemas()['options'], $_GET);
    return ['review_id' => $values['review_id']];
}

/**
 * @return array{review_id: int, name: string, asset_tag: string, serial: string, model_id: int, status_id: int, review_note: string}
 * @throws BadRequestException|UnprocessableException
 */
function snipe_review_create_input(): array
{
    $values = snipe_review_plain(input_parse(snipe_review_input_schemas()['create_asset'], $_POST), ['name', 'asset_tag', 'serial']);
    return ['review_id' => $values['review_id'], 'name' => $values['name'], 'asset_tag' => $values['asset_tag'], 'serial' => $values['serial'],
        'model_id' => $values['model_id'], 'status_id' => $values['status_id'], 'review_note' => $values['review_note']];
}

/**
 * @return array{review_id: int, identifier: string}
 * @throws BadRequestException|UnprocessableException
 */
function snipe_review_link_input(): array
{
    $values = snipe_review_plain(input_parse(snipe_review_input_schemas()['link_existing'], $_POST), ['identifier']);
    return ['review_id' => $values['review_id'], 'identifier' => $values['identifier']];
}

/**
 * @return array{review_id: int, review_note: string}
 * @throws BadRequestException|UnprocessableException
 */
function snipe_review_dismiss_input(): array
{
    $values = input_parse(snipe_review_input_schemas()['dismiss'], $_POST);
    return ['review_id' => $values['review_id'], 'review_note' => $values['review_note']];
}

/**
 * Refuse a line break or other control character in the asset's identity fields (422), as a direct receipt does: they
 * are sent to Snipe-IT and stored on the receipt. A note may span lines.
 *
 * @param array<string, mixed> $values
 * @param list<string> $keys
 * @return array<string, mixed>
 * @throws UnprocessableException
 */
function snipe_review_plain(array $values, array $keys): array
{
    $fields = [];
    foreach ($keys as $key) {
        if (preg_match('/[[:cntrl:]]/', (string)$values[$key]) === 1) {
            $fields[$key] = 'Control characters are not allowed.';
        }
    }
    if ($fields !== []) {
        throw new UnprocessableException('Control characters are not allowed.', ['fields' => $fields]);
    }
    return $values;
}
