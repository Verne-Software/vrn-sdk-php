<?php

declare(strict_types=1);

namespace Vernesoft;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Vernesoft\Core\Errors\VerneException;
use Vernesoft\Core\Errors\VerneTokenException;
use Vernesoft\Core\HttpClient;
use Vernesoft\Resources\Gate\Types\SignedTokenClaims;

/**
 * Verifies Gate signed tokens (ES256 JWTs) offline, against your tenant's published
 * public keys. No API key is involved: anything that receives a token can verify it.
 * Needs `ext-openssl`.
 *
 * What it checks: the algorithm is ES256 (anything else, `none` included, is refused),
 * the key is the one the token's `kid` names in your tenant's key set, the signature,
 * `iss` is **your** issuer (a token of another Gate tenant is refused even though its
 * signature is valid), `exp`, `nbf` and `iat`, and that `sub` is present.
 *
 * What it cannot check: whether the session behind the token is still alive. A signed
 * token lives 15 minutes and cannot be revoked; for anything that must follow a
 * revocation or a role change at once, ask `sessions()->check()` or introspection.
 *
 * The key set is cached for its `Cache-Control: max-age` (five minutes by default) and
 * refetched once when a token names a key it has not seen — which is how a key rotation
 * is followed — at most once per 30 seconds. Keep one verifier per tenant and reuse it.
 */
class GateTokenVerifier
{
    public const DEFAULT_CLOCK_TOLERANCE_SECONDS = 10;

    /** Cache lifetime when the key set answers without `Cache-Control: max-age`. */
    private const DEFAULT_JWKS_MAX_AGE_SECONDS = 300;

    /** An unknown `kid` refetches the key set at most this often. */
    private const MIN_REFETCH_INTERVAL_SECONDS = 30;

    /** DER prefix of a P-256 SubjectPublicKeyInfo, before the 65-byte uncompressed point. */
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public readonly string $issuer;

    public readonly string $jwksUrl;

    private ClientInterface $client;

    /** @var array<string, \OpenSSLAsymmetricKey> */
    private array $keys = [];

    private ?int $fetchedAt = null;

    private int $maxAgeSeconds = self::DEFAULT_JWKS_MAX_AGE_SECONDS;

    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param  string  $tenantId  your tenant id; the issuer is `<baseUrl>/auth/<tenantId>`
     * @param  string  $baseUrl  the same base URL as the API client
     * @param  int  $clockToleranceSeconds  allowed clock skew for `exp`, `nbf` and `iat`
     * @param  int  $timeoutSeconds  timeout of a key-set request
     * @param  ClientInterface|null  $httpClient  Guzzle client for the key-set request
     * @param  callable(): int|null  $clock  current Unix time; for tests
     */
    public function __construct(
        string $tenantId,
        string $baseUrl = HttpClient::DEFAULT_BASE_URL,
        private int $clockToleranceSeconds = self::DEFAULT_CLOCK_TOLERANCE_SECONDS,
        private int $timeoutSeconds = 10,
        ?ClientInterface $httpClient = null,
        ?callable $clock = null,
    ) {
        if ($tenantId === '') {
            throw new VerneException('GateTokenVerifier needs your tenantId.');
        }
        if (! function_exists('openssl_verify')) {
            throw new VerneException('GateTokenVerifier needs the openssl PHP extension (ext-openssl).');
        }

        $this->issuer = rtrim($baseUrl, '/').'/auth/'.$tenantId;
        $this->jwksUrl = $this->issuer.'/.well-known/jwks.json';
        $this->client = $httpClient ?? new Client(['timeout' => $this->timeoutSeconds]);
        $this->clock = $clock === null ? static fn (): int => time() : \Closure::fromCallable($clock);
    }

    /**
     * Verifies `$token` and returns its claims.
     *
     * @throws VerneTokenException when the token must not be trusted; `$e->reason` says why
     */
    public function verify(string $token): SignedTokenClaims
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new VerneTokenException(VerneTokenException::MALFORMED, 'Not a compact JWT.');
        }
        [$rawHeader, $rawPayload, $rawSignature] = $parts;
        $header = self::decodeJson($rawHeader, 'header');
        $payload = self::decodeJson($rawPayload, 'payload');

        $alg = $header['alg'] ?? null;
        if ($alg !== 'ES256') {
            throw new VerneTokenException(
                VerneTokenException::UNSUPPORTED_ALG,
                'Token algorithm '.json_encode($alg).' is not accepted; Gate signs with ES256.',
            );
        }
        $kid = $header['kid'] ?? null;
        if (! is_string($kid) || $kid === '') {
            throw new VerneTokenException(VerneTokenException::MALFORMED, 'Token header has no `kid`.');
        }

        $key = $this->keyFor($kid);

        $signature = self::base64UrlDecode($rawSignature);
        if ($signature === null || strlen($signature) !== 64) {
            throw new VerneTokenException(VerneTokenException::BAD_SIGNATURE, 'Token signature is not a P-256 signature.');
        }
        $valid = openssl_verify($rawHeader.'.'.$rawPayload, self::rawSignatureToDer($signature), $key, OPENSSL_ALGO_SHA256);
        if ($valid !== 1) {
            throw new VerneTokenException(VerneTokenException::BAD_SIGNATURE, 'Token signature does not verify.');
        }

        if (($payload['iss'] ?? null) !== $this->issuer) {
            throw new VerneTokenException(
                VerneTokenException::WRONG_ISSUER,
                'Token issuer '.json_encode($payload['iss'] ?? null).' is not '.$this->issuer.'.',
            );
        }

        $now = ($this->clock)();
        $exp = $payload['exp'] ?? null;
        if (! is_int($exp) && ! is_float($exp)) {
            throw new VerneTokenException(VerneTokenException::MISSING_CLAIM, 'Token has no `exp`.');
        }
        if ($now > $exp + $this->clockToleranceSeconds) {
            throw new VerneTokenException(VerneTokenException::EXPIRED, 'Token has expired.');
        }
        foreach (['nbf', 'iat'] as $claim) {
            $value = $payload[$claim] ?? null;
            if ((is_int($value) || is_float($value)) && $value > $now + $this->clockToleranceSeconds) {
                throw new VerneTokenException(VerneTokenException::NOT_YET_VALID, 'Token is not valid yet.');
            }
        }
        $sub = $payload['sub'] ?? null;
        if (! is_string($sub) || $sub === '') {
            throw new VerneTokenException(VerneTokenException::MISSING_CLAIM, 'Token has no `sub`.');
        }

        return SignedTokenClaims::fromArray($payload);
    }

    private function keyFor(string $kid): \OpenSSLAsymmetricKey
    {
        $now = ($this->clock)();
        if ($this->fetchedAt === null || $now - $this->fetchedAt > $this->maxAgeSeconds) {
            // With keys already cached, a failed refresh keeps them: a stale key set is
            // still the right one unless a token says otherwise.
            $this->refresh(required: $this->keys === []);
        }

        if (! isset($this->keys[$kid]) && ($this->fetchedAt === null || ($this->clock)() - $this->fetchedAt >= self::MIN_REFETCH_INTERVAL_SECONDS)) {
            // A kid we have not seen: a new key may have started signing. Ask once.
            $this->refresh(required: true);
        }

        if (! isset($this->keys[$kid])) {
            throw new VerneTokenException(VerneTokenException::UNKNOWN_KID, 'No key '.$kid.' in '.$this->jwksUrl.'.');
        }

        return $this->keys[$kid];
    }

    private function refresh(bool $required): void
    {
        try {
            $this->load();
        } catch (VerneTokenException $e) {
            if ($required) {
                throw $e;
            }
        }
    }

    private function load(): void
    {
        try {
            $response = $this->client->request('GET', $this->jwksUrl, [
                'headers' => ['Accept' => 'application/json'],
                'http_errors' => false,
                'timeout' => $this->timeoutSeconds,
            ]);
        } catch (GuzzleException $e) {
            throw new VerneTokenException(VerneTokenException::JWKS_UNAVAILABLE, 'Could not fetch '.$this->jwksUrl.': '.$e->getMessage(), $e);
        }

        if ($response->getStatusCode() !== 200) {
            throw new VerneTokenException(VerneTokenException::JWKS_UNAVAILABLE, $this->jwksUrl.' answered HTTP '.$response->getStatusCode().'.');
        }

        $body = json_decode((string) $response->getBody(), true);
        if (! is_array($body) || ! isset($body['keys']) || ! is_array($body['keys'])) {
            throw new VerneTokenException(VerneTokenException::JWKS_UNAVAILABLE, $this->jwksUrl.' has no `keys`.');
        }

        $keys = [];
        foreach ($body['keys'] as $jwk) {
            if (! is_array($jwk) || ($jwk['kty'] ?? null) !== 'EC' || ($jwk['crv'] ?? null) !== 'P-256' || ! is_string($jwk['kid'] ?? null)) {
                continue;
            }
            if (isset($jwk['use']) && $jwk['use'] !== 'sig') {
                continue;
            }
            $key = self::publicKeyFromJwk($jwk);
            if ($key !== null) {
                $keys[$jwk['kid']] = $key;
            }
        }

        $this->keys = $keys;
        $this->fetchedAt = ($this->clock)();
        $this->maxAgeSeconds = preg_match('/max-age=(\d+)/i', $response->getHeaderLine('Cache-Control'), $m) === 1
            ? (int) $m[1]
            : self::DEFAULT_JWKS_MAX_AGE_SECONDS;
    }

    /**
     * A P-256 JWK (`x`, `y`) as an OpenSSL public key, through a PEM SubjectPublicKeyInfo.
     *
     * @param  array<string, mixed>  $jwk
     */
    private static function publicKeyFromJwk(array $jwk): ?\OpenSSLAsymmetricKey
    {
        $x = is_string($jwk['x'] ?? null) ? self::base64UrlDecode($jwk['x']) : null;
        $y = is_string($jwk['y'] ?? null) ? self::base64UrlDecode($jwk['y']) : null;
        if ($x === null || $y === null || strlen($x) !== 32 || strlen($y) !== 32) {
            return null;
        }

        $der = hex2bin(self::P256_SPKI_PREFIX)."\x04".$x.$y;
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);

        return $key === false ? null : $key;
    }

    /**
     * A JWS ECDSA signature (`r‖s`, 32 bytes each) as the DER `SEQUENCE { r, s }` OpenSSL
     * verifies.
     */
    private static function rawSignatureToDer(string $signature): string
    {
        $encode = static function (string $int): string {
            $int = ltrim($int, "\x00");
            if ($int === '' || (ord($int[0]) & 0x80) !== 0) {
                $int = "\x00".$int;
            }

            return "\x02".chr(strlen($int)).$int;
        };

        $body = $encode(substr($signature, 0, 32)).$encode(substr($signature, 32, 32));

        return "\x30".chr(strlen($body)).$body;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJson(string $segment, string $what): array
    {
        $bytes = self::base64UrlDecode($segment);
        if ($bytes !== null) {
            $value = json_decode($bytes, true);
            if (is_array($value) && ! array_is_list($value)) {
                return $value;
            }
        }

        throw new VerneTokenException(VerneTokenException::MALFORMED, 'Token '.$what.' is not base64url JSON.');
    }

    private static function base64UrlDecode(string $segment): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]*$/', $segment) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($segment, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
