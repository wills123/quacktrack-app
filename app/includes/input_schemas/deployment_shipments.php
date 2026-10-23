<?php

/**
 * Request input of api/deployment-shipments.php, one schema per action (see includes/input_schema.php).
 * The service (kit_shipment_service.php) keeps its own state and business-rule checks.
 */

declare(strict_types=1);

require_once __DIR__ . '/../input_schema.php';
require_once __DIR__ . '/../deployment_constants.php';

/**
 * Every action's schema, keyed by action name.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function deployment_shipment_input_schemas(): array
{
    $shipmentId = ['shipment_id' => in_int(min: 1, required: true, label: 'Shipment ID')];
    $reason = ['reason' => in_string(max: 500)];

    return [
        'list' => [
            'deployment_id' => in_int(min: 1, required: true, label: 'Deployment ID'),
            'status' => in_enum(DEPLOYMENT_SHIPMENT_STATUSES),
            'limit' => in_int(min: 1, max: 200, default: 100, clamp: true),
        ],
        'get' => $shipmentId,
        'packing_list' => $shipmentId,
        'request' => [
            'deployment_id' => in_int(min: 1, required: true, label: 'Deployment ID'),
            'deployment_quest_id' => in_int(min: 0, label: 'Quest ID'),
            'kit_template_id' => in_int(min: 1, required: true, label: 'Kit'),
            'request_key' => in_string(max: 64),
            'needed_by' => in_string(max: 10),
            'request_notes' => in_string(max: 2000, column: 'kit_shipments.request_notes'),
            'destination_name' => in_string(max: 190, column: 'kit_shipments.destination_name'),
            'destination_address' => in_string(max: 2000, column: 'kit_shipments.destination_address'),
            'quantities' => in_map(in_int(min: 0, max: 1000), label: 'Quantities'),
        ],
        'approve' => $shipmentId + $reason,
        'decline' => $shipmentId + $reason,
        'dispatch' => $shipmentId + [
            'serials' => in_map(in_list(in_string(max: 190), max: 200, csv: true), label: 'Serials'),
            'carrier' => in_string(max: 100, column: 'kit_shipments.carrier'),
            'tracking_number' => in_string(max: 190, column: 'kit_shipments.tracking_number'),
        ],
        'receive' => $shipmentId + [
            'received_quantities' => in_map(in_int(min: 0), label: 'Received quantities'),
            'notes' => in_string(max: 500),
        ],
        'cancel' => $shipmentId + $reason,
    ];
}

/**
 * Parse the request for $action. Ids may arrive in the query string or the body, so both are read
 * (the body wins).
 *
 * @return array<string, mixed>
 */
function deployment_shipment_input(string $action): array
{
    static $schemas = null;
    $schemas ??= deployment_shipment_input_schemas();
    return input_parse($schemas[$action], $_POST + $_GET);
}
