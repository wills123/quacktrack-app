<?php

declare(strict_types=1);

require_once __DIR__ . '/../input_schema.php';

/** @return array<string, array<string, mixed>> */
function change_password_input_schema(): array
{
    // The existing strength policy owns the new password's 72-byte bound and its legacy feedback.
    // Plaintext credentials are not bound to the unrelated password_hash storage column.
    return [
        'current_password' => in_raw_string(required: true),
        'new_password' => in_raw_string(required: true),
        'confirm_password' => in_raw_string(required: true),
    ];
}

/**
 * @param array<int|string, mixed> $source
 * @return array<string, mixed>
 */
function change_password_input(array $source): array
{
    $input = input_parse(change_password_input_schema(), $source);
    if ($input['new_password'] !== $input['confirm_password']) {
        throw new BadRequestException('New passwords do not match');
    }
    return $input;
}
