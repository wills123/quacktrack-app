<?php

declare(strict_types=1);

require_once __DIR__ . '/OutboundHttp.php';

/**
 * QuickQueue API Client
 *
 * Server-side only client for QuickQueue's read API. Used to look up
 * ConnectWise Manage project data so a Deployment Quest Log campaign can be
 * pre-filled from a real project instead of typed by hand. Quack Track does
 * not talk to ConnectWise directly — QuickQueue owns that integration.
 *
 * @package Quacktrack
 */
class QuickQueueClient
{
    private const INSECURE_FLAG = 'QUICKQUEUE_ALLOW_INSECURE_HTTP';

    private string $baseUrl;
    private string $apiToken;
    private int $timeout;
    private static ?self $instance = null;

    private function __construct(string $baseUrl, string $apiToken, int $timeout)
    {
        // The bearer token travels to this URL.
        OutboundHttp::assertSecureUrl($baseUrl, self::INSECURE_FLAG);
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiToken = $apiToken;
        $this->timeout = $timeout;
    }

    /**
     * Get the shared client instance, built from QUICKQUEUE_API_BASE_URL /
     * QUICKQUEUE_API_TOKEN in .env.
     *
     * @throws RuntimeException if the QuickQueue API is not configured
     */
    public static function getInstance(): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $baseUrl = env('QUICKQUEUE_API_BASE_URL', '');
        $apiToken = env('QUICKQUEUE_API_TOKEN', '');
        if ($baseUrl === '' || $apiToken === '') {
            throw new RuntimeException('QuickQueue API configuration is incomplete. Check QUICKQUEUE_API_BASE_URL and QUICKQUEUE_API_TOKEN in .env');
        }

        self::$instance = new self($baseUrl, $apiToken, (int)env('QUICKQUEUE_API_TIMEOUT', '15'));
        return self::$instance;
    }

    /**
     * Search ConnectWise Manage projects by name/id, via QuickQueue's
     * GET /api/v1/connectwise/projects endpoint.
     *
     * @return array<int, array<string, mixed>>
     * @throws RuntimeException on any non-2xx response or transport failure
     */
    public function searchConnectWiseProjects(string $query): array
    {
        $response = $this->request('GET', '/api/v1/connectwise/projects', ['q' => $query]);
        if (!$response['success']) {
            throw new RuntimeException($response['error'] ?? 'QuickQueue project search failed.');
        }

        return $response['body']['projects'] ?? [];
    }

    /**
     * Fetch a single ConnectWise project by its CW project id. Returns null
     * (rather than throwing) on any failure, since this is used for optional
     * enrichment, not a required step.
     *
     * @return array<string, mixed>|null
     */
    public function getConnectWiseProject(string $id): ?array
    {
        $response = $this->request('GET', '/api/v1/connectwise/projects/' . rawurlencode($id));
        if (!$response['success']) {
            return null;
        }

        return $response['body']['project'] ?? null;
    }

    /**
     * Issue one HTTP request to QuickQueue and normalize the result. No
     * retry — this backs a synchronous, user-facing lookup, so failing fast
     * and surfacing an empty result is preferable to blocking the UI.
     *
     * @param array<string, mixed> $query
     * @return array{success: bool, body: array<string, mixed>, error: ?string}
     */
    private function request(string $method, string $endpoint, array $query = []): array
    {
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $sent = OutboundHttp::send($method, $url, [
            'Authorization: Bearer ' . $this->apiToken,
            'Accept: application/json',
            'User-Agent: Quacktrack/1.0',
        ], null, $this->timeout, self::INSECURE_FLAG);
        $httpCode = $sent['status'];

        if ($sent['error'] !== null) {
            Logger::info('quickqueue_api_error', $sent['error'], ['endpoint' => $endpoint]);
            return ['success' => false, 'body' => [], 'error' => 'Unable to reach QuickQueue.'];
        }
        $body = $sent['body'];

        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            return ['success' => false, 'body' => [], 'error' => 'Invalid response from QuickQueue.'];
        }

        $success = $httpCode >= 200 && $httpCode < 300;
        if (!$success) {
            $errorMessage = $decoded['error'] ?? "HTTP {$httpCode}";
            Logger::info('quickqueue_api_error', $errorMessage, ['endpoint' => $endpoint]);
            return ['success' => false, 'body' => [], 'error' => $errorMessage];
        }

        return ['success' => true, 'body' => $decoded, 'error' => null];
    }
}
