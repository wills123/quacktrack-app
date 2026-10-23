<?php

/**
 * Centralized audit logging helper for recording high-value actions.
 * Usage example:
 * AuditLogger::log('region_storage.update', [
 *     'entity_type' => 'product',
 *     'entity_id' => $productId,
 *     'description' => 'Adjusted inventory for North region',
 *     'metadata' => ['region_id' => $regionId, 'delta' => $delta]
 * ]);
 */
class AuditLogger
{
    /**
     * Record a generic audit event.
     */
    public static function log(string $action, array $context = []): void
    {
        $action = trim($action);
        if ($action === '') {
            return;
        }

        try {
            $pdo = db();
            $payload = self::buildPayload($action, $context);

            $stmt = $pdo->prepare('
                INSERT INTO audit_logs (
                    user_id, session_id, action, entity_type, entity_id,
                    description, metadata, ip_address, user_agent,
                    request_uri, request_method
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');

            $stmt->execute([
                $payload['user_id'],
                $payload['session_id'],
                $payload['action'],
                $payload['entity_type'],
                $payload['entity_id'],
                $payload['description'],
                $payload['metadata'],
                $payload['ip_address'],
                $payload['user_agent'],
                $payload['request_uri'],
                $payload['request_method']
            ]);
        } catch (Throwable $e) {
            // Never interrupt the user flow if audit logging fails
            Logger::error('audit_log_write_failed', $e, [
                'action' => $action,
                'context' => self::safeTruncate(json_encode($context))
            ]);
        }
    }

    /**
     * Convenience helper for region storage adjustments.
     */
    public static function logRegionStorageChange(array $details): void
    {
        $metadata = [
            'region_id' => $details['region_id'] ?? null,
            'region_name' => $details['region_name'] ?? null,
            'product_id' => $details['product_id'] ?? null,
            'product_sku' => $details['product_sku'] ?? null,
            'change_type' => $details['change_type'] ?? null,
            'quantity_before' => $details['quantity_before'] ?? null,
            'quantity_after' => $details['quantity_after'] ?? null,
            'delta' => $details['delta'] ?? null,
            'notes' => $details['notes'] ?? null
        ];

        self::log('region_storage.change', [
            'entity_type' => 'product',
            'entity_id' => $details['product_id'] ?? null,
            'description' => $details['description'] ?? 'Region storage adjustment',
            'metadata' => $metadata
        ]);
    }

    private static function buildPayload(string $action, array $context): array
    {
        $metadata = $context['metadata'] ?? [];
        if (!is_array($metadata)) {
            $metadata = ['value' => $metadata];
        }

        return [
            'user_id' => $context['user_id'] ?? ($_SESSION['uid'] ?? null),
            // Retain the nullable column for historical queries, but never
            // persist a browser credential in a new audit record.
            'session_id' => null,
            'action' => $action,
            'entity_type' => $context['entity_type'] ?? 'general',
            'entity_id' => $context['entity_id'] ?? null,
            'description' => self::safeTruncate($context['description'] ?? null, 255),
            'metadata' => self::encodeMetadata($metadata),
            'ip_address' => $context['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
            'user_agent' => self::safeTruncate($context['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null), 255),
            'request_uri' => self::safeTruncate($context['request_uri'] ?? ($_SERVER['REQUEST_URI'] ?? null), 255),
            'request_method' => $context['request_method'] ?? ($_SERVER['REQUEST_METHOD'] ?? null)
        ];
    }

    private static function encodeMetadata(array $metadata): ?string
    {
        if (empty($metadata)) {
            return null;
        }

        return self::safeTruncate(json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 2000);
    }

    private static function safeTruncate(?string $value, int $limit = 500): ?string
    {
        if ($value === null) {
            return null;
        }

        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit - 3) . '...';
    }
}
