<?php

declare(strict_types=1);

require_once __DIR__ . '/../input_schema.php';

/** @return array<string, array<string, mixed>> */
function client_error_logging_input_schema(): array
{
    return [
        'message' => in_string(max: 1000, required: true),
        'stack' => in_string(max: 4000, default: ''),
        'level' => in_enum(['error', 'warning', 'info', 'debug'], default: 'error'),
    ];
}

/** @param array<int|string, mixed> $source @return array<string, mixed> */
function client_error_logging_input(array $source): array
{
    $input = input_parse(client_error_logging_input_schema(), $source);
    $context = array_key_exists('context', $source) ? $source['context'] : [];
    if (!is_array($context)) {
        throw new BadRequestException('Context must be a JSON object or list.');
    }
    client_error_logging_check_context($context);
    if (count($context) > 20 || strlen(json_encode($context, JSON_THROW_ON_ERROR)) > 10000) {
        throw new UnprocessableException('Context must have at most 20 fields and 10000 encoded bytes.');
    }
    $input['context'] = $context;
    return $input;
}

/** @throws BadRequestException|UnprocessableException */
function client_error_logging_check_context(mixed $value, int $depth = 0): void
{
    if ($depth > 5) {
        throw new UnprocessableException('Context must have at most five nested levels.');
    }
    if (!is_array($value)) {
        client_error_logging_check_scalar($value);
        return;
    }
    foreach ($value as $key => $item) {
        if (is_string($key)) {
            input_parse(['key' => in_string()], ['key' => $key]);
        }
        client_error_logging_check_context($item, $depth + 1);
    }
}

/** Context leaves are finite JSON scalars with valid text. */
function client_error_logging_check_scalar(mixed $value): void
{
    if (is_string($value)) {
        input_parse(['value' => in_raw_string()], ['value' => $value]);
    } elseif (is_float($value) && !is_finite($value)) {
        throw new BadRequestException('Context numbers must be finite.');
    } elseif (!is_scalar($value) && $value !== null) {
        throw new BadRequestException('Context must contain JSON values.');
    }
}
