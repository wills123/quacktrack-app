<?php

declare(strict_types=1);

require_once __DIR__ . '/company_sso_config.php';
require_once __DIR__ . '/OutboundHttp.php';

/** The protocol library handles signatures; this boundary enforces the application's stricter policy. */
class CompanyOidcClient extends \Jumbojett\OpenIDConnectClient
{
    private string $trustedIssuer;

    /** @param array{issuer: string, client_id: string, client_secret: string, callback: string, max_age: int} $config */
    public function __construct(array $config)
    {
        parent::__construct($config['issuer'], $config['client_id'], $config['client_secret']);
        $this->trustedIssuer = $config['issuer'];
        $this->setIssuer($config['issuer']);
        $this->setIssuerValidator(static fn(string $issuer): bool => $issuer === $config['issuer']);
        $this->setRedirectURL($config['callback']);
        $this->setCodeChallengeMethod('S256');
        // Require PKCE even when a provider omits this optional discovery attribute (including Entra).
        $this->providerConfigParam(['code_challenge_methods_supported' => ['S256']]);
        $this->setAllowImplicitFlow(false);
        $this->addScope(['profile', 'email']);
        $this->addAuthParam(['max_age' => $config['max_age']]);
        $this->setTimeout(15);
    }

    public function redirect(string $url): void
    {
        $endpoint = explode('?', $url, 2)[0];
        if (!company_sso_same_origin($endpoint, $this->trustedIssuer)) {
            throw new RuntimeException('The SSO authorization endpoint is unsafe.');
        }
        parent::redirect($url);
    }

    protected function commitSession(): void
    {
        $keys = [];
        foreach (['openid_connect_state', 'openid_connect_nonce', 'openid_connect_code_verifier'] as $key) {
            $keys[$key] = $_SESSION[$key];
        }
        $_SESSION['company_sso_flow'] = [
            'state' => $keys['openid_connect_state'], 'keys' => $keys,
            'started' => time(), 'issuer' => $this->trustedIssuer, 'client_id' => $this->getClientID(),
        ];
        // Only the bounded flow is persisted. The callback restores these library keys for verification once.
        foreach (array_keys($keys) as $key) {
            unset($_SESSION[$key]);
        }
        parent::commitSession();
    }

    protected function fetchURL(string $url, ?string $post_body = null, array $headers = []): string
    {
        if (!company_sso_same_origin($url, $this->trustedIssuer)) {
            throw new RuntimeException('An SSO endpoint is outside the configured provider origin.');
        }
        if ($post_body !== null) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $response = OutboundHttp::send($post_body === null ? 'GET' : 'POST', $url, $headers, $post_body, 15);
        if ($response['error'] !== null || $response['status'] !== 200) {
            throw new RuntimeException('The SSO provider request failed.');
        }
        return $response['body'];
    }

    public function verifyJWTSignature(string $jwt): bool
    {
        $parts = explode('.', $jwt);
        $header = count($parts) === 3 ? json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true) : null;
        if (!is_array($header) || ($header['alg'] ?? null) !== 'RS256') {
            return false;
        }
        if (isset($header['jwk']) || isset($header['jku']) || isset($header['x5u']) || isset($header['crit'])) {
            return false;
        }
        return parent::verifyJWTSignature($jwt);
    }

    protected function verifyJWTClaims(mixed $claims, ?string $accessToken = null): bool
    {
        if (!company_sso_claims_valid($claims, $this->getClientID(), $this->getNonce())) {
            return false;
        }
        return parent::verifyJWTClaims($claims, $accessToken);
    }
}

/** Mandatory claims are checked before the library's issuer, signature and access-token binding checks. */
function company_sso_claims_valid(mixed $claims, string $clientId, mixed $nonce): bool
{
    if (!company_sso_identity_claims_valid($claims) || !is_string($nonce)) {
        return false;
    }
    return hash_equals($nonce, $claims->nonce)
        && company_sso_audience_valid($claims, $clientId)
        && company_sso_times_valid($claims);
}

function company_sso_identity_claims_valid(mixed $claims): bool
{
    return is_object($claims) && is_string($claims->sub ?? null)
        && $claims->sub !== '' && strlen($claims->sub) <= 255
        && is_string($claims->iss ?? null) && is_string($claims->nonce ?? null);
}

function company_sso_audience_valid(object $claims, string $clientId): bool
{
    $audiences = is_array($claims->aud ?? null) ? $claims->aud : [$claims->aud ?? null];
    if (!in_array($clientId, $audiences, true)) {
        return false;
    }
    return (count($audiences) === 1 && !isset($claims->azp)) || ($claims->azp ?? null) === $clientId;
}

function company_sso_times_valid(object $claims): bool
{
    return is_int($claims->exp ?? null) && $claims->exp > time()
        && is_int($claims->iat ?? null) && $claims->iat <= time() + 60
        && $claims->iat >= time() - 600
        && (!isset($claims->nbf) || (is_int($claims->nbf) && $claims->nbf <= time() + 60));
}
