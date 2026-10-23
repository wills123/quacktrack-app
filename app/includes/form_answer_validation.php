<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

/**
 * Form answer validation helpers (MVP)
 *
 * Goal: validate values by field type using the snapshotted campaign question definition.
 * Keep this file dependency-light so it can be tested locally without DB.
 */

function form_normalize_options(mixed $optionsJson): array
{
    if ($optionsJson === null || $optionsJson === '') {
        return [];
    }
    $decoded = json_decode((string)$optionsJson, true);
    if (!is_array($decoded)) {
        return [];
    }
    $opts = [];
    foreach ($decoded as $v) {
        $s = trim((string)$v);
        if ($s !== '') {
            $opts[] = $s;
        }
    }
    return array_values(array_unique($opts));
}

/**
 * @return array{ok:bool, text:?string, json:?string, error:?string}
 */
function form_validate_answer(array $questionRow, mixed $raw): array
{
    $type = strtolower(trim((string)($questionRow['type'] ?? '')));
    $required = (int)($questionRow['is_required'] ?? 0) === 1;

    $text = null;
    $json = null;

    // Treat missing as empty
    $isEmpty = $raw === null || $raw === '' || (is_array($raw) && count($raw) === 0);
    if ($required && $isEmpty) {
        return ['ok' => false, 'text' => null, 'json' => null, 'error' => 'Required field'];
    }
    if ($isEmpty) {
        return ['ok' => true, 'text' => null, 'json' => null, 'error' => null];
    }

    $opts = form_normalize_options($questionRow['options_json'] ?? null);

    switch ($type) {
        case 'short_text':
        case 'long_text':
            $s = trim((string)$raw);
            $max = ($type === 'long_text') ? 5000 : 255;
            if (mb_strlen($s) > $max) {
                return ['ok' => false, 'text' => null, 'json' => null, 'error' => 'Value too long'];
            }
            $text = $s;
            break;
        case 'number':
            $s = trim((string)$raw);
            if (!is_numeric($s)) {
                return ['ok' => false, 'text' => null, 'json' => null, 'error' => 'Invalid number'];
            }
            // Keep as text to avoid float rounding surprises.
            $text = $s;
            break;
        case 'date':
            $s = trim((string)$raw);
            // Expect YYYY-MM-DD from <input type=date>
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
                return ['ok' => false, 'text' => null, 'json' => null, 'error' => 'Invalid date'];
            }
            $text = $s;
            break;
        case 'yes_no':
            $s = strtolower(trim((string)$raw));
            if (!in_array($s, ['yes', 'no'], true)) {
                return ['ok' => false, 'text' => null, 'json' => null, 'error' => 'Invalid selection'];
            }
            $text = $s;
            break;
        case 'single_choice':
            $s = trim((string)$raw);
            if (!empty($opts) && !in_array($s, $opts, true)) {
                return ['ok' => false, 'text' => null, 'json' => null, 'error' => 'Invalid choice'];
            }
            if (mb_strlen($s) > 255) {
                return ['ok' => false, 'text' => null, 'json' => null, 'error' => 'Value too long'];
            }
            $text = $s;
            break;
        case 'multi_choice':
            // Primary: multi-select checkboxes submit an array (name[]).
            // Fallback: if options are not configured yet, the UI may render a text input.
            // In that case, accept a comma-separated list (or a single value) so users aren't blocked.
            if (!is_array($raw)) {
                if (empty($opts)) {
                    $s = trim((string)$raw);
                    if ($s === '') {
                        if ($required) {
                            return ['ok' => false, 'text' => null, 'json' => null, 'error' => 'Required field'];
                        }
                        return ['ok' => true, 'text' => null, 'json' => null, 'error' => null];
                    }
                    $parts = preg_split('/\s*,\s*/', $s) ?: [];
                    $vals = [];
                    foreach ($parts as $p) {
                        $p = trim((string)$p);
                        if ($p !== '') {
                            $vals[] = $p;
                        }
                    }
                    $vals = array_values(array_unique($vals));
                    $json = json_encode($vals, JSON_UNESCAPED_SLASHES);
                    break;
                }
                return ['ok' => false, 'text' => null, 'json' => null, 'error' => 'Invalid selection'];
            }

            $vals = [];
            foreach ($raw as $v) {
                $s = trim((string)$v);
                if ($s === '') {
                    continue;
                }
                if (!empty($opts) && !in_array($s, $opts, true)) {
                    return ['ok' => false, 'text' => null, 'json' => null, 'error' => 'Invalid choice'];
                }
                $vals[] = $s;
            }
            $vals = array_values(array_unique($vals));
            if ($required && empty($vals)) {
                return ['ok' => false, 'text' => null, 'json' => null, 'error' => 'Required field'];
            }
            $json = json_encode($vals, JSON_UNESCAPED_SLASHES);
            break;
        default:
            // Fallback: treat as short text
            $s = trim((string)$raw);
            if (mb_strlen($s) > 255) {
                return ['ok' => false, 'text' => null, 'json' => null, 'error' => 'Value too long'];
            }
            $text = $s;
            break;
    }

    return ['ok' => true, 'text' => $text !== '' ? $text : null, 'json' => $json, 'error' => null];
}
