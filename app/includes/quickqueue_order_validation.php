<?php

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/input_schemas/external_orders.php';

/**
 * Validate the transport-level QuickQueue create-order contract.
 * Database lookups are intentionally handled by the service.
 *
 * Nothing is coerced or dropped: a value of the wrong type is a 400, a well-formed value that breaks
 * a rule is a 422, and the problems of one request are reported together.
 *
 * @return array<string, mixed>
 * @throws BadRequestException|UnprocessableException
 */
function quickqueue_validate_order_payload(mixed $payload): array
{
    if (!is_array($payload) || array_is_list($payload)) {
        throw new BadRequestException('Request body must be a JSON object.');
    }
    quickqueue_require_shapes($payload);

    $input = input_parse(external_orders_input_schemas()['create'], $payload);
    $key = $input['idempotency_key'];
    if (preg_match('/[\x00-\x1F\x7F]/', $key)) {
        throw new UnprocessableException('Idempotency key must not contain control characters.');
    }
    quickqueue_check_destination($payload, $input['region_id'] ?? null);

    $objects = external_orders_object_schemas();
    $requestedBy = input_parse($objects['requested_by'], $payload['requested_by']);
    if (!filter_var($requestedBy['email'], FILTER_VALIDATE_EMAIL)) {
        throw new UnprocessableException('Requester email must be a valid email address.');
    }

    return [
        'idempotency_key' => $key,
        'items' => quickqueue_unique_items($input['items']),
        'receive_to_van' => $payload['receive_to_van'],
        'receive_to_region' => $payload['receive_to_region'],
        'region_id' => $input['region_id'] ?? null,
        'region_location' => $input['region_location'],
        'store_id' => $input['store_id'],
        'requested_by' => ['email' => strtolower($requestedBy['email']), 'name' => $requestedBy['name']],
        'ticket' => quickqueue_parse_ticket($payload['external_ticket'] ?? null, $objects['external_ticket']),
    ];
}

/**
 * Digest used before typed line normalization; keep existing idempotency records replayable.
 * Called only after the new boundary has validated the original body.
 * @param array<string, mixed> $body
 * @param array<string, mixed> $normalized
 */
function quickqueue_legacy_request_hash(array $body, array $normalized): string
{
    $legacy = array_replace($normalized, [
        'items' => $body['items'],
        'ticket' => $body['external_ticket'] ?? null,
    ]);
    return hash('sha256', json_encode($legacy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

/**
 * Fields whose JSON type the schema system cannot express: the two booleans must be real booleans
 * (not the "yes" or 1 a form would send), items a list, and the two objects objects.
 *
 * @param array<string, mixed> $payload
 * @throws BadRequestException
 */
function quickqueue_require_shapes(array $payload): void
{
    $problems = [];
    foreach (['receive_to_van', 'receive_to_region'] as $destination) {
        if (!is_bool($payload[$destination] ?? null)) {
            $problems[$destination] = $destination . ' must be a boolean.';
        }
    }
    if (!is_array($payload['items'] ?? null) || !array_is_list($payload['items'])) {
        $problems['items'] = 'items must be a list of order lines.';
    }
    $problems += quickqueue_object_problems($payload);
    if ($problems !== []) {
        throw new BadRequestException(implode(' ', array_values($problems)), ['fields' => $problems]);
    }
}

/**
 * The shape problems of the two objects: requested_by is required, external_ticket may be null.
 *
 * @param array<string, mixed> $payload
 * @return array<string, string>
 */
function quickqueue_object_problems(array $payload): array
{
    $problems = [];
    if (!quickqueue_is_object($payload['requested_by'] ?? null)) {
        $problems['requested_by'] = 'requested_by must be an object.';
    }
    $ticket = $payload['external_ticket'] ?? null;
    if ($ticket !== null && !quickqueue_is_object($ticket)) {
        $problems['external_ticket'] = 'external_ticket must be an object or null.';
    }
    return $problems;
}

/** A decoded JSON object: an array that is not a list (an empty object decodes to []). */
function quickqueue_is_object(mixed $value): bool
{
    return is_array($value) && ($value === [] || !array_is_list($value));
}

/**
 * Exactly one receiving destination, and a region id only for a region.
 *
 * @param array<string, mixed> $payload
 * @throws UnprocessableException
 */
function quickqueue_check_destination(array $payload, ?int $regionId): void
{
    if ($payload['receive_to_van'] === $payload['receive_to_region']) {
        throw new UnprocessableException('Exactly one receiving destination must be selected.');
    }
    if ($payload['receive_to_region'] && $regionId === null) {
        throw new UnprocessableException('region_id is required when receiving to a region.');
    }
    if (!$payload['receive_to_region'] && $regionId !== null) {
        throw new UnprocessableException('region_id must be null when receiving to a van.');
    }
}

/**
 * The lines as typed records; one product may appear only once (SKUs compare without case).
 *
 * @param list<array<string, mixed>> $items
 * @return list<array{sku: string, quantity: int, notes: string}>
 * @throws UnprocessableException
 */
function quickqueue_unique_items(array $items): array
{
    $seen = [];
    $lines = [];
    foreach ($items as $item) {
        $sku = (string)$item['sku'];
        if (isset($seen[strtolower($sku)])) {
            throw new UnprocessableException('Duplicate SKUs are not allowed in one order.', ['sku' => $sku]);
        }
        $seen[strtolower($sku)] = true;
        $lines[] = ['sku' => $sku, 'quantity' => (int)$item['quantity'], 'notes' => (string)($item['notes'] ?? '')];
    }
    return $lines;
}

/**
 * The linked ticket, or null when none was sent.
 *
 * @param array<string, array<string, mixed>> $schema
 * @return array{system: string, id: string}|null
 * @throws UnprocessableException
 */
function quickqueue_parse_ticket(mixed $ticket, array $schema): ?array
{
    if ($ticket === null) {
        return null;
    }
    $parsed = input_parse($schema, $ticket);
    if ($parsed['system'] !== EXTERNAL_ORDERS_TICKET_SYSTEM) {
        throw new UnprocessableException('external_ticket.system must be "' . EXTERNAL_ORDERS_TICKET_SYSTEM . '".');
    }
    return ['system' => $parsed['system'], 'id' => $parsed['id']];
}
