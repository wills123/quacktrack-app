<?php

declare(strict_types=1);

require_once __DIR__ . '/../input_schema.php';

/** @return array<string, array<string, array<string, mixed>>> */
function templates_input_schemas(): array
{
    $id = ['template_id' => in_int(min: 1, max: 2147483647, required: true, column: 'order_templates.id')];
    return [
        'create' => [
            'name' => in_string(max: 255, required: true, column: 'order_templates.name'),
            'description' => in_text(default: '', column: 'order_templates.description'),
            'is_public' => in_flag(default: false),
        ],
        'list' => [],
        'apply' => $id,
        'delete' => $id,
    ];
}

/** @return array<string, mixed> */
function templates_input(string $action): array
{
    $schemas = templates_input_schemas();
    $schema = $schemas[$action] ?? throw new BadRequestException('Invalid action');
    return input_parse($schema, $action === 'list' ? $_GET : $_POST);
}
