<?php

declare(strict_types=1);

require_once __DIR__ . '/SnipeHttp.php';

/**
 * Snipe IT API Client
 *
 * Provides a PHP interface to the Snipe IT REST API with:
 * - Bearer token authentication (server-side only)
 * - HTTP method wrappers (GET, POST, PUT, PATCH, DELETE)
 * - Response caching to reduce API calls within a request
 * - Logging of all API calls to snipe_it_sync_log for monitoring
 * - Singleton pattern for convenient access
 *
 * Usage:
 *   $client = SnipeItClient::getInstance();
 *   $assets = $client->listAssets(['limit' => 50]);
 *   $asset = $client->getAsset(123);
 *   $result = $client->checkoutAsset(123, ['assigned_user' => 5]);
 *
 * @package Quacktrack
 */
class SnipeItClient
{
    /** Longest a read waits for Snipe-IT's rate limit to clear before its one retry (seconds). */
    private const MAX_RATE_LIMIT_WAIT = 3;
    /** Rows per page when walking a long list, and the most rows listAll() will collect. */
    private const PAGE_SIZE = 100;
    private const LIST_ALL_MAX_ROWS = 2000;
    /** How long cached reference lists stay fresh (seconds). */
    private const REFERENCE_TTL = 600;
    /** Cache entry (see cachedGet) holding the Assets page stat strip numbers. */
    public const STATS_CACHE_KEY = 'asset_stats';

    private string $baseUrl;
    private string $apiKey;
    private int $timeout;
    private int $cacheTtl;
    private static ?self $instance = null;
    private array $cache = [];
    private ?int $currentUserId = null;
/**
     * Private constructor (use getInstance() instead)
     */
    private function __construct(string $baseUrl, string $apiKey, int $timeout, int $cacheTtl)
    {
        SnipeHttp::assertSecureUrl($baseUrl);
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->timeout = $timeout;
        $this->cacheTtl = $cacheTtl;
    }

    /**
     * Get singleton instance of SnipeItClient
     *
     * @throws RuntimeException if Snipe IT is not enabled or API config missing
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $enabled = env('SNIPE_IT_ENABLED', '0');
        if ($enabled !== '1') {
            throw new RuntimeException('Snipe IT integration is not enabled. Set SNIPE_IT_ENABLED=1 in .env');
        }

        $baseUrl = env('SNIPE_IT_API_URL', '');
        $apiKey = env('SNIPE_IT_API_KEY', '');
        if ($baseUrl === '' || $apiKey === '') {
            throw new RuntimeException('Snipe IT API configuration is incomplete. Check SNIPE_IT_API_URL and SNIPE_IT_API_KEY in .env');
        }

        self::$instance = new self($baseUrl, $apiKey, (int)env('SNIPE_IT_API_TIMEOUT', '15'), (int)env('SNIPE_IT_CACHE_TTL', '300'));
        return self::$instance;
    }

    /**
     * Create a non-singleton client instance using a user's personal OAuth token.
     *
     * Use this for write operations (checkout, checkin, update) so that Snipe-IT's
     * audit log records the individual user rather than the shared service account.
     * Does NOT replace or affect the singleton returned by getInstance().
     *
     * @param string $personalToken User's OAuth access token
     * @param int    $userId        Quack Track user ID (for local audit logging)
     * @throws RuntimeException if the API URL is not configured
     */
    public static function forToken(string $personalToken, int $userId = 0): self
    {
        if ($personalToken === '') {
            throw new RuntimeException('Personal access token is required.');
        }

        $baseUrl = env('SNIPE_IT_API_URL', '');
        if ($baseUrl === '') {
            throw new RuntimeException('Snipe-IT API URL not configured. Check SNIPE_IT_API_URL in .env');
        }

        $instance = new self($baseUrl, $personalToken, (int)env('SNIPE_IT_API_TIMEOUT', '15'), (int)env('SNIPE_IT_CACHE_TTL', '300'));
        if ($userId > 0) {
            $instance->setCurrentUserId($userId);
        }
        return $instance;
    }

    /**
     * Set the current user ID for logging purposes
     *
     * @param int $userId
     * @return void
     */
    public function setCurrentUserId(int $userId): void
    {
        $this->currentUserId = $userId;
    }

    /**
     * Make an HTTP request to Snipe IT API
     *
     * @param string $method HTTP method: GET, POST, PUT, PATCH, DELETE
     * @param string $endpoint API endpoint (e.g., '/hardware', '/hardware/123', '/categories')
     * @param array $data Request body data (for POST/PUT/PATCH) or query params (for GET)
     * @return array Response: ['status' => int, 'data' => array, 'error' => string|null]
     */
    public function request(string $method, string $endpoint, array $data = []): array
    {
        $startTime = microtime(true);
        $method = strtoupper($method);
        $endpoint = '/' . ltrim($endpoint, '/');

        // Check cache for GET requests
        $cacheKey = null;
        if ($method === 'GET') {
            $cacheKey = $this->getCacheKey($method, $endpoint, $data);
            $cached = $this->getFromCache($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $url = $this->baseUrl . $endpoint;
        $body = null;
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $body = json_encode($data, JSON_UNESCAPED_SLASHES);
        } elseif ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }

        try {
            $headers = [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: Quacktrack/1.0',
            ];
            $response = SnipeHttp::send($method, $url, $headers, $body, $this->timeout);
            if ($method !== 'GET') {
                // Any write may have changed asset counts, even if the reply was an error or
                // never arrived, so drop the shared stats here rather than at every call site.
                $this->bustFileCache(self::STATS_CACHE_KEY);
            }
            if ($response['status'] === 429 && $method === 'GET') {
                // Over Snipe-IT's per-minute limit. A read is safe to repeat, so wait the
                // time the server asked for (capped, so a page never hangs) and try once more.
                usleep(min(max($response['retry_after'] ?? 1, 1), self::MAX_RATE_LIMIT_WAIT) * 1_000_000);
                $response = SnipeHttp::send($method, $url, $headers, $body, $this->timeout);
            }
            $responseTime = (int)((microtime(true) - $startTime) * 1000);

            if ($response['error'] !== null) {
                throw new RuntimeException($response['error']);
            }

            $httpCode = $response['status'];
            $responseData = json_decode($response['body'], true);
            if ($responseData === null && $response['body'] !== '') {
                throw new RuntimeException('Invalid JSON response from Snipe IT API');
            }

            $success = $httpCode >= 200 && $httpCode < 300;
            if ($success && $method === 'GET' && !is_array($responseData)) {
                throw new RuntimeException('Invalid JSON response from Snipe IT API');
            }
            $catalog = ['/hardware', '/users', '/categories', '/models', '/statuslabels', '/locations', '/manufacturers', '/suppliers'];
            if ($success && $method === 'GET' && in_array($endpoint, $catalog, true)) {
                if (!is_array($responseData['rows'] ?? null) || !array_is_list($responseData['rows']) || !is_int($responseData['total'] ?? null) || $responseData['total'] < 0) {
                    throw new RuntimeException('Invalid list response from Snipe IT API');
                }
                foreach ($responseData['rows'] as $row) {
                    if (!is_array($row) || array_is_list($row)) {
                        throw new RuntimeException('Invalid row response from Snipe IT API');
                    }
                }
            }
            $errorMessage = null;
            if (!$success) {
                $reported = $responseData['message'] ?? $responseData['error'] ?? "HTTP {$httpCode}";
                $errorMessage = is_string($reported) ? $reported : (string)json_encode($reported);
                if ($httpCode === 429) {
                    $errorMessage = 'Snipe-IT is rate limiting requests. Please try again in a moment.';
                }
            }

            $this->logApiCall($endpoint, $method, $success ? 'success' : 'error', $responseTime, is_array($responseData) ? count($responseData['rows'] ?? [$responseData]) : 0, $errorMessage, $this->currentUserId);

            $result = ['status' => $httpCode, 'data' => $responseData, 'error' => $errorMessage];
            if ($cacheKey !== null && $success) {
                $this->setCache($cacheKey, $result);
            }
            return $result;
        } catch (Throwable $e) {
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            $this->logApiCall($endpoint, $method, 'error', $responseTime, 0, $e->getMessage(), $this->currentUserId);
            return [
                'status' => 0,
                'data' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Make a GET request
     *
     * @param string $endpoint
     * @param array $params Query parameters
     * @return array Response data or null on error
     */
    public function get(string $endpoint, array $params = []): ?array
    {
        $result = $this->request('GET', $endpoint, $params);
        // A reply that is valid JSON but not an object or list (a bare string or number) is as unusable as none.
        return $result['error'] === null && is_array($result['data']) ? $result['data'] : null;
    }

    /**
     * Make a POST request
     *
     * @param string $endpoint
     * @param array $data Request body
     * @return array Response data or null on error
     */
    public function post(string $endpoint, array $data): ?array
    {
        $result = $this->request('POST', $endpoint, $data);
        // A reply that is valid JSON but not an object or list (a bare string or number) is as unusable as none.
        return $result['error'] === null && is_array($result['data']) ? $result['data'] : null;
    }

    /**
     * Make a PUT request
     *
     * @param string $endpoint
     * @param array $data Request body
     * @return array Response data or null on error
     */
    public function put(string $endpoint, array $data): ?array
    {
        $result = $this->request('PUT', $endpoint, $data);
        // A reply that is valid JSON but not an object or list (a bare string or number) is as unusable as none.
        return $result['error'] === null && is_array($result['data']) ? $result['data'] : null;
    }

    /**
     * Make a PATCH request
     *
     * @param string $endpoint
     * @param array $data Request body
     * @return array Response data or null on error
     */
    public function patch(string $endpoint, array $data): ?array
    {
        $result = $this->request('PATCH', $endpoint, $data);
        // A reply that is valid JSON but not an object or list (a bare string or number) is as unusable as none.
        return $result['error'] === null && is_array($result['data']) ? $result['data'] : null;
    }

    /**
     * Make a DELETE request
     *
     * @param string $endpoint
     * @return bool True if successful
     */
    public function delete(string $endpoint): bool
    {
        $result = $this->request('DELETE', $endpoint);
        return $result['error'] === null;
    }

    /**
     * List all assets from Snipe IT
     *
     * @param array $params Query parameters (limit, offset, search, status_id, etc.)
     * @return array|null Assets data with pagination
     */
    public function listAssets(array $params = []): ?array
    {
        $defaultParams = [
            'limit' => 50,
            'offset' => 0,
        ];
        $params = array_merge($defaultParams, $params);
        return $this->get('/hardware', $params);
    }

    /**
     * List hardware currently checked out to a specific Snipe-IT user.
     *
     * @param int $userId Snipe-IT user ID
     * @param array $params Query parameters (limit, offset, etc.)
     * @return array|null Assets data with pagination
     */
    public function listUserAssets(int $userId, array $params = []): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $defaultParams = [
            'limit' => 100,
            'offset' => 0,
        ];
        $params = array_merge($defaultParams, $params);
        return $this->get("/users/{$userId}/assets", $params);
    }

    /**
     * Get a single asset by ID
     *
     * @param int $id Asset ID in Snipe IT
     * @return array|null Asset data
     */
    public function getAsset(int $id): ?array
    {
        return $this->get("/hardware/{$id}");
    }

    /**
     * Look up a single asset by serial number or asset tag.
     * Searches Snipe-IT hardware with the given string and returns the first
     * matching asset, or null if none found.
     *
     * @param string $serial Serial number or asset tag to search for
     * @return array|null Asset data or null
     */
    public function getAssetBySerial(string $serial): ?array
    {
        $serial = trim($serial);
        if ($serial === '') {
            return null;
        }
        $response = $this->get('/hardware', [
            'search' => $serial,
            'limit'  => 10,
            'offset' => 0,
        ]);
        $rows = $response['rows'] ?? [];
        foreach ($rows as $asset) {
        // Match against serial number or asset tag (exact, case-insensitive)
            $assetSerial = strtolower(trim($asset['serial'] ?? ''));
            $assetTag    = strtolower(trim($asset['asset_tag'] ?? ''));
            $needle      = strtolower($serial);
            if ($assetSerial === $needle || $assetTag === $needle) {
                return $asset;
            }
        }
        return null;
    }

    /**
     * Check out an asset to a user
     *
     * @param int $assetId Snipe IT asset ID
     * @param array $data Checkout details (assigned_user, note, etc.)
     * @return array|null Checkout response
     */
    public function checkoutAsset(int $assetId, array $data): ?array
    {
        return $this->post("/hardware/{$assetId}/checkout", $data);
    }

    /**
     * Check in an asset (return to unassigned)
     *
     * @param int $assetId Snipe IT asset ID
     * @param array $data Checkin details (note, etc.)
     * @return array|null Checkin response
     */
    public function checkinAsset(int $assetId, array $data = []): ?array
    {
        return $this->post("/hardware/{$assetId}/checkin", $data);
    }

    /**
     * Update an asset's details
     *
     * @param int $assetId Snipe IT asset ID
     * @param array $data Fields to update (name, notes, status_id, etc.)
     * @return array|null Update response
     */
    public function updateAsset(int $assetId, array $data): ?array
    {
        return $this->patch("/hardware/{$assetId}", $data);
    }

    /**
     * Create an asset from an approved Snipe-IT model template.
     *
     * @param array $data Snipe-IT hardware creation payload
     * @return array|null Created asset response
     */
    public function createAsset(array $data): ?array
    {

        return $this->post('/hardware', $data);
    }
    /**
     * Every row of a Snipe-IT list endpoint, not just the first page.
     *
     * Snipe-IT returns at most the requested `limit` rows, so a plain list call silently
     * drops everything past it (a dropdown would simply be missing models). This walks
     * the pages using the reported `total`, up to LIST_ALL_MAX_ROWS.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null null when any page fails (a partial list must not pass for a complete one); otherwise has "total" and "rows"
     */
    public function listAll(string $endpoint, array $params = []): ?array
    {
        $rows = [];
        $total = 0;
        $offset = 0;
        do {
            $page = $this->get($endpoint, array_merge($params, ['limit' => self::PAGE_SIZE, 'offset' => $offset]));
            if ($page === null) {
                return null;
            }
            $batch = is_array($page['rows'] ?? null) ? $page['rows'] : [];
            $total = (int)($page['total'] ?? count($batch));
            array_push($rows, ...$batch);
            $offset += self::PAGE_SIZE;
        } while ($batch !== [] && $offset < $total && count($rows) < self::LIST_ALL_MAX_ROWS);

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * A reference list (models, status labels, locations, ...) shared across requests.
     * These change rarely, so reading them from Snipe-IT on every page load only spends
     * rate limit. Held on disk for REFERENCE_TTL seconds and complete (all pages).
     *
     * @return array<string, mixed>|null "total" and "rows", as for any Snipe-IT list
     */
    public function referenceList(string $kind): ?array
    {
        static $endpoints = [
            'categories' => '/categories',
            'models' => '/models',
            'statuslabels' => '/statuslabels',
            'locations' => '/locations',
            'manufacturers' => '/manufacturers',
            'suppliers' => '/suppliers',
        ];
        if (!isset($endpoints[$kind])) {
            throw new InvalidArgumentException('Unknown Snipe-IT reference list: ' . $kind);
        }
        return $this->cachedGet($kind, fn() => $this->listAll($endpoints[$kind]), self::REFERENCE_TTL);
    }

    /**
     * List all asset categories
     *
     * @param array $params Query parameters
     * @return array|null Categories data
     */
    public function listCategories(array $params = []): ?array
    {
        return $this->get('/categories', array_merge(['limit' => 100], $params));
    }

    /**
     * List all asset models
     *
     * @param array $params Query parameters
     * @return array|null Models data
     */
    public function listModels(array $params = []): ?array
    {
        return $this->get('/models', array_merge(['limit' => 100], $params));
    }

    /**
     * List all status labels
     *
     * @param array $params Query parameters
     * @return array|null Status labels data
     */
    public function listStatusLabels(array $params = []): ?array
    {
        return $this->get('/statuslabels', array_merge(['limit' => 100], $params));
    }

    /**
     * List all locations
     *
     * @param array $params Query parameters
     * @return array|null Locations data
     */
    public function listLocations(array $params = []): ?array
    {
        return $this->get('/locations', array_merge(['limit' => 100], $params));
    }

    /**
     * List all users
     *
     * @param array $params Query parameters
     * @return array|null Users data
     */
    public function listUsers(array $params = []): ?array
    {
        return $this->get('/users', array_merge(['limit' => 100], $params));
    }

    /**
     * List all manufacturers (cached)
     */
    public function listManufacturers(array $params = []): ?array
    {
        return $this->get('/manufacturers', array_merge(['limit' => 100], $params));
    }

    /**
     * List all suppliers (cached)
     */
    public function listSuppliers(array $params = []): ?array
    {
        return $this->get('/suppliers', array_merge(['limit' => 100], $params));
    }

    /**
     * Get the profile (and permission set) of the user this client's token belongs to.
     * Only meaningful on a client made with forToken(); the shared API key would
     * return the service account.
     *
     * @return array|null Decoded /users/me response, or null on any failure
     */
    public function getCurrentUser(): ?array
    {
        return $this->get('/users/me');
    }

    /**
     * Get a user by ID
     *
     * @param int $id User ID in Snipe IT
     * @return array|null User data
     */
    public function getUserById(int $id): ?array
    {
        return $this->get("/users/{$id}");
    }

    /**
     * List activity/audit log
     *
     * @param array $params Query parameters (action_type, target_id, user_id, etc.)
     * @return array|null Activity data
     */
    public function listActivities(array $params = []): ?array
    {
        return $this->get('/reports/activity', array_merge(['limit' => 50], $params));
    }

    // =========================================================================
    // Persistent File Cache (for slow-changing filter data)
    // =========================================================================

    /**
     * Get cached data from disk, or fetch fresh and cache it.
     *
     * Used for endpoints like /categories, /models, /statuslabels, /locations
     * that rarely change. Avoids an extra Snipe-IT round-trip on every page load.
     *
     * @param string $key    Unique cache key (used as filename)
     * @param callable(): (?array) $fetch Returns fresh data (array|null)
     * @param int $ttl       Seconds before the cache entry expires (default 10 min)
     */
    public function cachedGet(string $key, callable $fetch, int $ttl = 600): ?array
    {
        $file = $this->cacheFile($key);
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return $fetch(); // no usable cache directory: still answer, just uncached
        }

        // Return from disk cache if still fresh
        if (is_file($file) && (time() - (int)filemtime($file)) < $ttl) {
            $raw = file_get_contents($file);
            $data = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($data)) {
                return $data;
            }
        }

        // Fetch fresh data and write it atomically, so a concurrent reader never sees half a file
        $data = $fetch();
        if ($data !== null) {
            $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, json_encode($data), LOCK_EX) !== false) {
                @chmod($tmp, 0600);
                if (!@rename($tmp, $file)) {
                    @unlink($tmp);
                }
            }
        }

        return $data;
    }

    /**
     * Bust a specific persistent cache entry (call after mutating operations).
     */
    public function bustFileCache(string $key): void
    {
        $file = $this->cacheFile($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function cacheFile(string $key): string
    {
        return sys_get_temp_dir() . '/quacktrack_snipe_cache/' . preg_replace('/[^a-z0-9_-]/i', '_', $key) . '.json';
    }

    // =========================================================================
    // Private Helper Methods
    // =========================================================================

    /**
     * Log an API call to the snipe_it_sync_log table
     *
     * @param string $endpoint API endpoint
     * @param string $action HTTP method
     * @param string $status 'success' or 'error'
     * @param int $responseTimeMs Response time in milliseconds
     * @param int $recordCount Number of records returned
     * @param string|null $errorMessage Error details if status is 'error'
     * @param int|null $userId User ID who triggered the call
     * @return void
     */
    private function logApiCall(string $endpoint, string $action, string $status, int $responseTimeMs, int $recordCount, ?string $errorMessage, ?int $userId): void
    {
        // Response text can echo request detail; keep only a bounded summary.
        $errorMessage = $errorMessage !== null ? mb_substr($errorMessage, 0, 500) : null;
        try {
            $pdo = db();
            $stmt = $pdo->prepare('
                INSERT INTO snipe_it_sync_log (endpoint, action, status, response_time_ms, record_count, error_message, user_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $endpoint,
                $action,
                $status,
                $responseTimeMs,
                $recordCount,
                $errorMessage,
                $userId > 0 ? $userId : null,
            ]);
            $this->purgeOldLogRows($pdo);
        } catch (Throwable $e) {
        // Silently fail if logging fails (don't break the API request)
            Logger::error('snipe_it_client_logging_failed', $e, [
                'endpoint' => $endpoint,
                'action' => $action,
            ]);
        }
    }

    /**
     * Keep snipe_it_sync_log from growing forever: about once per 200 logged calls,
     * drop rows older than SNIPE_SYNC_LOG_RETENTION_DAYS (default 90; 0 keeps everything).
     */
    private function purgeOldLogRows(PDO $pdo): void
    {
        $days = (int)env('SNIPE_SYNC_LOG_RETENTION_DAYS', '90');
        if ($days <= 0 || random_int(1, 200) !== 1) {
            return;
        }
        $pdo->prepare('DELETE FROM snipe_it_sync_log WHERE created_at < (NOW() - INTERVAL ? DAY) LIMIT 5000')->execute([$days]);
    }

    /**
     * Generate a cache key for a GET request
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $params Query parameters
     * @return string Cache key
     */
    private function getCacheKey(string $method, string $endpoint, array $params): string
    {
        $key = $method . ':' . $endpoint;
        if (!empty($params)) {
            $key .= ':' . hash('sha256', json_encode($params));
        }
        return $key;
    }

    /**
     * Get a value from cache if it exists and hasn't expired
     *
     * @param string $key Cache key
     * @return array|null Cached value or null if not found/expired
     */
    private function getFromCache(string $key): ?array
    {
        if (!isset($this->cache[$key])) {
            return null;
        }

        $entry = $this->cache[$key];
        $age = time() - $entry['time'];
        if ($age > $this->cacheTtl) {
            unset($this->cache[$key]);
            return null;
        }

        return $entry['value'];
    }

    /**
     * Store a value in cache
     *
     * @param string $key Cache key
     * @param array $value Value to cache
     * @return void
     */
    private function setCache(string $key, array $value): void
    {
        $this->cache[$key] = [
            'value' => $value,
            'time' => time(),
        ];
    }

    /**
     * Clear all cached GET responses (call after any mutating operation)
     */
    public function clearCache(): void
    {
        $this->cache = [];
        // Asset counts shared across requests are now out of date as well.
        $this->bustFileCache(self::STATS_CACHE_KEY);
    }
}
