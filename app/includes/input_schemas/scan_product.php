<?php

declare(strict_types=1);

require_once __DIR__ . '/../input_schema.php';

/** @return array<string, array<string, mixed>> */
function scan_product_input_schema(): array
{
    return ['barcode' => in_string(max: 100, required: true, column: 'products.barcode')];
}

/** @param array<string, mixed> $source @return array{barcode: string} */
function scan_product_input(array $source): array
{
    $input = input_parse(scan_product_input_schema(), $source);
    if (!preg_match('/^[a-zA-Z0-9:_-]+$/D', $input['barcode'])) {
        throw new UnprocessableException('Invalid barcode format.');
    }
    return ['barcode' => $input['barcode']];
}
