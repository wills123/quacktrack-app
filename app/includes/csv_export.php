<?php

declare(strict_types=1);

/**
 * Minimal CSV helpers for exports.
 */

function csv_escape(string|int|float|bool|null $value): string
{
    $s = (string)($value ?? '');
    // Quoting alone does not prevent a spreadsheet from interpreting untrusted text as a formula.
    // Preserve numeric values; text beginning with control characters or formula markers is text.
    // A plain number is not a formula even when the database driver hands it over as a string (DECIMAL columns do).
    $plainNumber = preg_match('/^[+-]?\d+(?:\.\d+)?$/D', $s) === 1;
    $formula = is_string($value) && !$plainNumber && (
        preg_match('/^[\x00-\x20]*(?:[=+\-@]|＝|＋|－|＠)/', $s) === 1
        || strpbrk(substr($s, 0, 1), "\t\r\n") !== false
    );
    if ($formula) {
        $s = "'" . $s;
    }
    $needsQuotes = $formula || strpbrk($s, ",\n\r\"") !== false;
    $s = str_replace('"', '""', $s);
    return $needsQuotes ? '"' . $s . '"' : $s;
}

/**
 * @param array<array-key, string|int|float|bool|null> $row
 */
function csv_row(array $row): string
{
    return implode(',', array_map('csv_escape', $row)) . "\n";
}

/** @return resource */
function csv_open_output()
{
    $output = fopen('php://output', 'w');
    if ($output === false) {
        throw new RuntimeException('Could not open the output stream for the export.');
    }
    return $output;
}

/**
 * @param resource $output
 * @param array<array-key, string|int|float|bool|null> $row
 */
function csv_write_row($output, array $row): void
{
    $line = csv_row($row);
    if (fwrite($output, $line) !== strlen($line)) {
        throw new RuntimeException('Could not write the CSV export.');
    }
}
