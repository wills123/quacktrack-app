<?php

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/snipe_catalog_service.php';

/** Shared Snipe response boundary; retain each caller's log events and fallback.
 * @param array<string, mixed> $context
 */
function snipe_api_fail(Throwable $error, string $providerEvent, string $event, array $context, string $fallback): void
{
    if ($error instanceof SnipeUnavailableException) {
        api_error($error->getMessage(), 503);
    } elseif ($error instanceof SnipeProviderException) {
        Logger::error($providerEvent, $error, $context);
        api_error('Snipe-IT did not answer correctly. Try again later.', 502);
    } else {
        api_fail($error, $event, $context, $fallback);
    }
}
