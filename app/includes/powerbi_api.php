<?php

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/api_key_auth.php';
require_once __DIR__ . '/input_schemas/powerbi.php';
require_once __DIR__ . '/powerbi_service.php';

/**
 * api/powerbi.php: stateless, X-Api-Key only (no session is ever started or read). Order of checks: method (405), a
 * missing key (401, before the database is opened), key (401 for every kind of refusal), usage bookkeeping, rate limit (429), table (400), query (400 wrong type, 422 rule),
 * then the table. A database fault is a 500 with a generic message, never an authentication failure, and the request
 * still counts as a use of its key.
 */

/** Answer one table request; every answer ends the request. */
function powerbi_dispatch(string $table): void
{
    $keyId = null;
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            header('Allow: GET');
            throw new ClientErrorException('Only GET requests are allowed.', 405);
        }
        $apiKey = (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
        powerbi_require_key($apiKey);
        $pdo = db();
        $keyId = powerbi_authenticate($pdo, $apiKey, time());
        if (powerbi_record_use($pdo, $keyId, (string)($_SERVER['REMOTE_ADDR'] ?? ''), time()) > quacktrack_api_key_rate_limit()) {
            throw new ClientErrorException('Rate limit exceeded.', 429);
        }
        if (!in_array($table, POWERBI_TABLES, true)) {
            throw new BadRequestException('Unknown table: ' . mb_substr($table, 0, 64) . '. Valid tables: ' . implode(', ', POWERBI_TABLES) . '.');
        }
        echo json_encode(powerbi_table_answer($pdo, $table, powerbi_input()));
    } catch (ClientError $e) {
        api_fail($e, 'powerbi_api_error', ['table' => $table, 'key_id' => $keyId]);
    } catch (Throwable $e) {
        // An external connector reads this export: it never gets the database's message, in any environment.
        $errorId = uniqid('ERR-', true);
        Logger::error('powerbi_api_error', $e, ['table' => $table, 'key_id' => $keyId, 'error_id' => $errorId]);
        api_error('Internal server error.', 500, ['error_id' => $errorId]);
    }
}
