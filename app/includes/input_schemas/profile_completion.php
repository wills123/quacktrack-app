<?php

declare(strict_types=1);

require_once __DIR__ . '/../input_schema.php';

/** @return array<string, array<string, array<string, mixed>>> */
function profile_completion_input_schemas(): array
{
    return [
        'check_completion' => [],
        'complete_profile' => [
            'first_name' => in_string(max: 80, required: true, column: 'users.first_name'),
            'last_name' => in_string(max: 80, required: true, column: 'users.last_name'),
            'username' => in_string(max: 50, required: true, column: 'users.username'),
            'region_id' => in_int(min: 1, max: 2147483647, required: true, column: 'users.region_id'),
        ],
    ];
}

/** @return array<string, mixed> */
function profile_completion_input(string $action): array
{
    $schema = profile_completion_input_schemas()[$action] ?? throw new BadRequestException('Invalid action');
    $input = input_parse($schema, $_POST);
    if ($action === 'complete_profile') {
        if (preg_match('/\A[A-Za-z0-9._-]{3,50}\z/', $input['username']) !== 1) {
            throw new UnprocessableException('Username must be 3–50 characters: letters, numbers, dots, underscores or hyphens.');
        }
        foreach (['first_name', 'last_name'] as $field) {
            if (preg_match('/[\x00-\x1F\x7F]/', $input[$field]) === 1) {
                throw new UnprocessableException('Names must not contain control characters.');
            }
        }
    }
    return $input;
}
