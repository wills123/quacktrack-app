<?php

declare(strict_types=1);

require_once __DIR__ . '/OutboundHttp.php';

/**
 * The provider could not be reached, answered with something unusable, or refused a call. Callers that only log
 * failures can keep catching RuntimeException.
 */
class AcumaticaProviderException extends RuntimeException
{
    /** HTTP statuses that mean "not the request's fault": credentials, rights, a missing or unsupported route, throttling, a gateway. */
    private const NOT_A_REFUSAL = [0, 401, 403, 404, 405, 406, 408, 415, 429, 501, 502, 503, 504];

    public function __construct(string $message, private readonly int $providerStatus = 0, private readonly bool $businessError = false)
    {
        parent::__construct($message);
    }

    /** The HTTP status Acumatica answered with; 0 when no usable answer arrived (transport, timeout, bad JSON). */
    public function getProviderStatus(): int
    {
        return $this->providerStatus;
    }

    /**
     * Acumatica understood the request and refused it. It reports business-rule errors as 4xx or as 500 with an
     * "exceptionMessage"; a bare 500 is a server failure, not a refusal.
     */
    public function isRefusal(): bool
    {
        if ($this->providerStatus < 400 || in_array($this->providerStatus, self::NOT_A_REFUSAL, true)) {
            return false;
        }
        return $this->providerStatus !== 500 || $this->businessError;
    }
}

/**
 * Acumatica ERP REST API Client
 *
 * A generic, feature-agnostic wrapper around Acumatica's contract-based REST
 * API. Not tied to kits or any other single feature -- any part of the app
 * can call AcumaticaClient::getInstance() to look up live stock levels,
 * shipment/tracking info, or create a purchase order.
 *
 * Every purchase order this client creates is always inserted on Hold
 * (Acumatica's own draft state). This client never releases a PO. A human
 * always reviews and releases it inside Acumatica itself -- that is a
 * deliberate, non-configurable safety decision, not an oversight.
 *
 * Acumatica's REST API uses cookie-based session auth (not a bearer token),
 * so unlike SnipeItClient this client logs in on demand and persists the
 * session cookie to a shared cache file so consecutive requests across
 * separate PHP requests don't each pay for a fresh login.
 *
 * Usage:
 *   $client = AcumaticaClient::getInstance();
 *   $levels = $client->getStockLevels(['AALEGO500', 'AACOMPUT01']);
 *   $po = $client->createPurchaseOrder('VENDOR01', [
 *       ['inventoryId' => 'AALEGO500', 'qty' => 5, 'warehouseId' => 'WHOLESALE'],
 *   ]);
 *
 * @package Quacktrack
 */
class AcumaticaClient
{
    private string $instanceUrl;
    private string $username;
    private string $password;
    private string $company;
    private ?string $branch;
    private string $endpointName;
    private string $apiVersion;
    private int $timeout;
    private int $cacheTtl;
    private static ?self $instance = null;
    /** @var array<string, array{time: int, value: array{status: int, data: mixed, error: string|null, business?: bool}}> */
    private array $cache = [];
    private ?int $currentUserId = null;
    private ?string $sessionCookie = null;

    private const INSECURE_FLAG = 'ACUMATICA_ALLOW_INSECURE_HTTP';
    private const SESSION_CACHE_FILE = 'quacktrack_acumatica_session.json';
    private const SESSION_MAX_AGE_SEC = 1200; // re-login after 20 minutes regardless

    private function __construct(
        string $instanceUrl,
        string $username,
        string $password,
        string $company,
        ?string $branch,
        string $endpointName,
        string $apiVersion,
        int $timeout,
        int $cacheTtl
    ) {
        // The service account password and session cookie travel to this URL.
        OutboundHttp::assertSecureUrl($instanceUrl, self::INSECURE_FLAG);
        $this->instanceUrl = rtrim($instanceUrl, '/');
        $this->username = $username;
        $this->password = $password;
        $this->company = $company;
        $this->branch = $branch;
        $this->endpointName = $endpointName;
        $this->apiVersion = $apiVersion;
        $this->timeout = $timeout;
        $this->cacheTtl = $cacheTtl;
    }

    /**
     * Get singleton instance of AcumaticaClient
     *
     * @throws RuntimeException if Acumatica is not enabled or config is incomplete
     */
    public static function getInstance(): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        if (env('ACUMATICA_ENABLED', '0') !== '1') {
            throw new RuntimeException('Acumatica integration is not enabled. Set ACUMATICA_ENABLED=1 in .env');
        }

        $instanceUrl = env('ACUMATICA_API_URL', '');
        $username = env('ACUMATICA_USERNAME', '');
        $password = env('ACUMATICA_PASSWORD', '');
        $company = env('ACUMATICA_COMPANY', '');
        if ($instanceUrl === '' || $username === '' || $password === '' || $company === '') {
            throw new RuntimeException('Acumatica API configuration is incomplete. Check ACUMATICA_API_URL, ACUMATICA_USERNAME, ACUMATICA_PASSWORD, and ACUMATICA_COMPANY in .env');
        }

        $branch = env('ACUMATICA_BRANCH', '');

        self::$instance = new self(
            $instanceUrl,
            $username,
            $password,
            $company,
            $branch !== '' ? $branch : null,
            env('ACUMATICA_ENDPOINT_NAME', 'Default'),
            env('ACUMATICA_API_VERSION', '25.200.001'),
            (int)env('ACUMATICA_API_TIMEOUT', '30'),
            (int)env('ACUMATICA_CACHE_TTL', '300')
        );
        return self::$instance;
    }

    public function setCurrentUserId(int $userId): void
    {
        $this->currentUserId = $userId;
    }

    // ------------------------------------------------------------------
    // Public, feature-agnostic helpers
    // ------------------------------------------------------------------

    /**
     * Look up a single stock item, including per-warehouse quantities.
     *
     * @return array|null Decoded StockItem, or null if not found
     */
    public function getStockItem(string $inventoryId): ?array
    {
        $result = $this->request('GET', '/StockItem/' . rawurlencode($inventoryId), ['$expand' => 'WarehouseDetails']);
        if ($result['status'] === 404) {
            return null;
        }
        if ($result['error'] !== null) {
            throw new AcumaticaProviderException("Acumatica getStockItem({$inventoryId}) failed: {$result['error']}", $result['status'], $result['business'] ?? false);
        }
        return $result['data'];
    }

    /**
     * Look up on-hand quantities for a batch of items in one pass.
     *
     * @param string[] $inventoryIds
     * @param list<string>|null $failed Receives the InventoryIDs whose lookup errored (as opposed to not existing)
     * @return array<string, array{qtyOnHand: float, warehouses: array<int, array{warehouseId: string, qtyOnHand: float}>}>
     *   Keyed by InventoryID. Items that don't exist or errored are simply omitted.
     */
    public function getStockLevels(array $inventoryIds, ?array &$failed = null): array
    {
        $levels = [];
        $failed = [];
        foreach (array_unique($inventoryIds) as $inventoryId) {
            try {
                $item = $this->getStockItem($inventoryId);
            } catch (Throwable $e) {
                Logger::error('acumatica_stock_level_lookup_failed', $e, ['inventory_id' => $inventoryId]);
                $failed[] = $inventoryId;
                continue;
            }
            if ($item === null) {
                continue;
            }

            $warehouses = [];
            $total = 0.0;
            foreach ($item['WarehouseDetails'] ?? [] as $wd) {
                $qty = (float)($wd['QtyOnHand']['value'] ?? 0);
                $warehouses[] = [
                    'warehouseId' => $wd['WarehouseID']['value'] ?? '',
                    'qtyOnHand'   => $qty,
                ];
                $total += $qty;
            }

            $levels[$inventoryId] = [
                'qtyOnHand'  => $total,
                'warehouses' => $warehouses,
            ];
        }
        return $levels;
    }

    /**
     * Look up a shipment, including package/tracking-number details.
     *
     * @return array|null Decoded Shipment, or null if not found
     */
    public function getShipment(string $shipmentNbr): ?array
    {
        $result = $this->request('GET', '/Shipment/' . rawurlencode($shipmentNbr), ['$expand' => 'Packages,Details']);
        if ($result['status'] === 404) {
            return null;
        }
        if ($result['error'] !== null) {
            throw new AcumaticaProviderException("Acumatica getShipment({$shipmentNbr}) failed: {$result['error']}", $result['status'], $result['business'] ?? false);
        }
        return $result['data'];
    }

    /**
     * Look up a purchase order, including line-level receipt status.
     *
     * @return array|null Decoded PurchaseOrder, or null if not found
     */
    public function getPurchaseOrder(string $orderNbr): ?array
    {
        $result = $this->request('GET', '/PurchaseOrder/' . rawurlencode($orderNbr), ['$expand' => 'Details']);
        if ($result['status'] === 404) {
            return null;
        }
        if ($result['error'] !== null) {
            throw new AcumaticaProviderException("Acumatica getPurchaseOrder({$orderNbr}) failed: {$result['error']}", $result['status'], $result['business'] ?? false);
        }
        return $result['data'];
    }

    /**
     * Create a new purchase order in Acumatica. ALWAYS inserted on Hold --
     * this client will never release a PO. A human reviews and releases it
     * inside Acumatica. This is intentional, not a missing feature.
     *
     * @param string $vendorId Acumatica Vendor.VendorID
     * @param array<int, array{inventoryId: string, qty: float, warehouseId?: string|null, unitCost?: float|null}> $lines
     * @param string|null $description
     * @param string|null $vendorRef Your own reference number, shown on the PO
     * @return array The created PurchaseOrder (includes the assigned OrderNbr)
     */
    public function createPurchaseOrder(string $vendorId, array $lines, ?string $description = null, ?string $vendorRef = null): array
    {
        if (empty($lines)) {
            throw new InvalidArgumentException('createPurchaseOrder requires at least one line.');
        }

        $details = [];
        foreach ($lines as $line) {
            if (empty($line['inventoryId']) || empty($line['qty'])) {
                throw new InvalidArgumentException('Each PO line requires inventoryId and qty.');
            }
            $detail = [
                'InventoryID' => ['value' => $line['inventoryId']],
                'OrderQty'    => ['value' => $line['qty']],
            ];
            if (!empty($line['warehouseId'])) {
                $detail['WarehouseID'] = ['value' => $line['warehouseId']];
            }
            if (isset($line['unitCost'])) {
                $detail['UnitCost'] = ['value' => $line['unitCost']];
            }
            $details[] = $detail;
        }

        $payload = [
            'VendorID' => ['value' => $vendorId],
            'Hold'     => ['value' => true],
            'Details'  => $details,
        ];
        if ($description !== null) {
            $payload['Description'] = ['value' => $description];
        }
        if ($vendorRef !== null) {
            $payload['VendorRef'] = ['value' => $vendorRef];
        }

        $result = $this->request('PUT', '/PurchaseOrder', [], $payload);
        if ($result['error'] !== null) {
            throw new AcumaticaProviderException("Acumatica createPurchaseOrder failed: {$result['error']}", $result['status'], $result['business'] ?? false);
        }

        $order = $result['data'];
        if (($order['Hold']['value'] ?? true) !== true) {
            // Should be unreachable (we always send Hold=true), but never let a
            // PO we created silently end up released without us knowing.
            Logger::error('acumatica_po_not_on_hold', new RuntimeException('PO created without Hold=true'), [
                'order_nbr' => $order['OrderNbr']['value'] ?? null,
            ]);
        }
        return $order;
    }

    // ------------------------------------------------------------------
    // HTTP + session plumbing
    // ------------------------------------------------------------------

    /**
     * Make an HTTP request against the Acumatica contract-based endpoint.
     *
     * @param string $method GET, POST, PUT, DELETE
     * @param string $path Entity path, e.g. '/StockItem/AALEGO500'
     * @param array $query Query-string parameters (e.g. $expand, $select, $filter)
     * @param array $body Request body (for PUT/POST)
     * @return array{status:int, data:mixed, error:string|null, business?:bool}
     */
    public function request(string $method, string $path, array $query = [], array $body = []): array
    {
        $method = strtoupper($method);

        if ($method === 'GET') {
            $cacheKey = $this->getCacheKey($method, $path, $query);
            $cached = $this->getFromCache($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $this->ensureSession();

        $result = $this->doRequest($method, $path, $query, $body);

        // A 401 means our cached session expired server-side; log in once more
        // and retry a single time before giving up.
        if ($result['status'] === 401) {
            $this->login();
            $result = $this->doRequest($method, $path, $query, $body);
        }

        if ($method === 'GET' && $result['error'] === null) {
            $this->setCache($this->getCacheKey($method, $path, $query), $result);
        }

        return $result;
    }

    /** @return array{status: int, data: mixed, error: string|null, business?: bool} */
    private function doRequest(string $method, string $path, array $query, array $body): array
    {
        $startTime = microtime(true);
        $entityBase = "{$this->instanceUrl}/entity/{$this->endpointName}/{$this->apiVersion}";
        $url = $entityBase . '/' . ltrim($path, '/');
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $errorMessage = null;
        try {
            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: Quacktrack/1.0',
            ];
            if ($this->sessionCookie !== null) {
                $headers[] = 'Cookie: ' . $this->sessionCookie;
            }

            $payload = in_array($method, ['POST', 'PUT', 'PATCH'], true)
                ? json_encode($body, JSON_UNESCAPED_SLASHES)
                : null;
            $sent = OutboundHttp::send($method, $url, $headers, $payload === false ? null : $payload, $this->timeout, self::INSECURE_FLAG);
            $httpCode = $sent['status'];
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            if ($sent['error'] !== null) {
                throw new RuntimeException($sent['error']);
            }
            $response = $sent['body'];

            $responseData = $response !== '' ? json_decode($response, true) : null;
            if ($responseData === null && $response !== '' && json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Invalid JSON response from Acumatica API');
            }

            $success = $httpCode >= 200 && $httpCode < 300;
            if ($success && (!is_array($responseData) || array_is_list($responseData))) {
                throw new RuntimeException('Invalid entity response from Acumatica API');
            }
            if (!$success) {
                // Acumatica's "message" is generic; the reason a business rule failed is in "exceptionMessage".
                $reason = trim(implode(' ', array_filter([
                    is_array($responseData) && is_string($responseData['message'] ?? null) ? $responseData['message'] : '',
                    is_array($responseData) && is_string($responseData['exceptionMessage'] ?? null) ? $responseData['exceptionMessage'] : '',
                ])));
                $errorMessage = $reason !== '' ? $reason : "HTTP {$httpCode}";
            }

            $this->logApiCall($path, $method, $success ? 'success' : 'error', $responseTime, is_array($responseData) && array_is_list($responseData) ? count($responseData) : ($responseData !== null ? 1 : 0), $errorMessage, $this->currentUserId);

            return [
                'status' => $httpCode,
                'data'   => $responseData,
                'error'  => $errorMessage,
                'business' => !$success && is_array($responseData) && is_string($responseData['exceptionMessage'] ?? null) && $responseData['exceptionMessage'] !== '',
            ];
        } catch (Throwable $e) {
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            $this->logApiCall($path, $method, 'error', $responseTime, 0, $e->getMessage(), $this->currentUserId);
            return [
                'status' => 0,
                'data'   => null,
                'error'  => $e->getMessage(),
            ];
        }
    }

    /**
     * Ensure we have a usable session cookie, reusing a cached one shared
     * across PHP requests when possible so we're not logging in every time.
     */
    private function ensureSession(): void
    {
        if ($this->sessionCookie !== null) {
            return;
        }

        $cacheFile = sys_get_temp_dir() . '/' . self::SESSION_CACHE_FILE;
        // Only trust a cache file this process's user created, not one planted in the shared temp dir.
        // getmyuid() is the owner of the running script (root in the release image), not the
        // PHP-FPM worker, so compare against the effective user instead.
        if (is_file($cacheFile) && @fileowner($cacheFile) === self::processUid()) {
            $raw = file_get_contents($cacheFile);
            $cached = $raw !== false ? json_decode($raw, true) : null;
            if (
                is_array($cached) && ($cached['username'] ?? null) === $this->username
                && (time() - ($cached['time'] ?? 0)) < self::SESSION_MAX_AGE_SEC
            ) {
                $this->sessionCookie = $cached['cookie'];
                return;
            }
        }

        $this->login();
    }

    private static function processUid(): int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    }

    private function login(): void
    {
        $startTime = microtime(true);
        $url = "{$this->instanceUrl}/entity/auth/login";
        $payload = [
            'name'     => $this->username,
            'password' => $this->password,
            'company'  => $this->company,
        ];
        if ($this->branch !== null) {
            $payload['branch'] = $this->branch;
        }

        $sent = OutboundHttp::send(
            'POST',
            $url,
            ['Content-Type: application/json', 'Accept: application/json'],
            json_encode($payload, JSON_UNESCAPED_SLASHES) ?: null,
            $this->timeout,
            self::INSECURE_FLAG
        );
        $httpCode = $sent['status'];
        $responseTime = (int)((microtime(true) - $startTime) * 1000);

        if ($sent['error'] !== null) {
            $this->logApiCall('/auth/login', 'login', 'error', $responseTime, 0, $sent['error'], $this->currentUserId);
            throw new AcumaticaProviderException('Acumatica login failed: ' . $sent['error']);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logApiCall('/auth/login', 'login', 'error', $responseTime, 0, "HTTP {$httpCode}", $this->currentUserId);
            throw new AcumaticaProviderException("Acumatica login failed: HTTP {$httpCode}", 401);
        }

        $cookies = [];
        foreach ($sent['headers'] as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $cookiePair = trim(substr($line, strlen('Set-Cookie:')));
                $cookiePair = explode(';', $cookiePair)[0];
                $cookies[] = $cookiePair;
            }
        }
        if (empty($cookies)) {
            $this->logApiCall('/auth/login', 'login', 'error', $responseTime, 0, 'No session cookie returned', $this->currentUserId);
            throw new AcumaticaProviderException('Acumatica login succeeded but returned no session cookie.');
        }

        $this->sessionCookie = implode('; ', $cookies);
        $this->logApiCall('/auth/login', 'login', 'success', $responseTime, 0, null, $this->currentUserId);

        $cacheFile = sys_get_temp_dir() . '/' . self::SESSION_CACHE_FILE;
        // The cookie is a live Acumatica session: create the file private to this user
        // before writing it, and swap it in atomically.
        $tmp = $cacheFile . '.' . getmypid() . '.tmp';
        if (@touch($tmp) && @chmod($tmp, 0600)) {
            $written = @file_put_contents($tmp, json_encode([
                'username' => $this->username,
                'cookie'   => $this->sessionCookie,
                'time'     => time(),
            ]));
            if ($written === false || !@rename($tmp, $cacheFile)) {
                @unlink($tmp);
            }
        }
    }

    private function logApiCall(string $endpoint, string $action, string $status, int $responseTimeMs, int $recordCount, ?string $errorMessage, ?int $userId): void
    {
        try {
            $pdo = db();
            $stmt = $pdo->prepare('
                INSERT INTO acumatica_sync_log (endpoint, action, status, response_time_ms, record_count, error_message, user_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$endpoint, $action, $status, $responseTimeMs, $recordCount, $errorMessage, $userId]);
        } catch (Throwable $e) {
            Logger::error('acumatica_client_logging_failed', $e, ['endpoint' => $endpoint]);
        }
    }

    private function getCacheKey(string $method, string $path, array $params): string
    {
        $key = $method . ':' . $path;
        if (!empty($params)) {
            $key .= ':' . hash('sha256', json_encode($params));
        }
        return $key;
    }

    /** @return array{status: int, data: mixed, error: string|null, business?: bool}|null */
    private function getFromCache(string $key): ?array
    {
        if (!isset($this->cache[$key])) {
            return null;
        }
        $entry = $this->cache[$key];
        if ((time() - $entry['time']) > $this->cacheTtl) {
            unset($this->cache[$key]);
            return null;
        }
        return $entry['value'];
    }

    /** @param array{status: int, data: mixed, error: string|null, business?: bool} $value */
    private function setCache(string $key, array $value): void
    {
        $this->cache[$key] = ['value' => $value, 'time' => time()];
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }
}
