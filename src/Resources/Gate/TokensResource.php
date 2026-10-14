<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate;

use Vernesoft\Core\Errors\VerneTokenException;
use Vernesoft\Core\HttpClient;
use Vernesoft\GateTokenVerifier;
use Vernesoft\Resources\Gate\Types\AccessToken;
use Vernesoft\Resources\Gate\Types\IntrospectResult;
use Vernesoft\Resources\Gate\Types\SignedToken;
use Vernesoft\Resources\Gate\Types\SignedTokenClaims;

class TokensResource
{
    /** @var array<string, GateTokenVerifier> */
    private array $verifiers = [];

    public function __construct(private HttpClient $httpClient) {}

    /**
     * Exchanges the API key for a short-lived opaque access token (`gat_live_…`) for
     * `$subject` — a user or a service account. Answers `201`.
     *
     * The key travels in the `Authorization` header like on every other call. (Until 1.4.0
     * this SDK sent it in the request body without the header, which the API refuses
     * with `401`.)
     *
     * @param  string[]|null  $scopes
     * @param  int|null  $ttlSeconds  default 3600, max 86400
     */
    public function create(
        string $subject,
        ?array $scopes = null,
        ?int $ttlSeconds = null,
    ): AccessToken {
        $body = ['subject' => $subject];

        if ($scopes !== null) {
            $body['scopes'] = $scopes;
        }

        if ($ttlSeconds !== null) {
            $body['ttl_seconds'] = $ttlSeconds;
        }

        $data = $this->httpClient->post('/v1/gate/tokens', $body);

        return AccessToken::fromArray($data);
    }

    /**
     * Validates a Gate access token and returns its attributes. An unknown, expired or
     * foreign token, or one whose user you deactivated or deleted, is `active: false` with
     * nothing else set.
     *
     * When the subject is an end user, `orgs` lists their organizations in the key's
     * project, read at the moment you ask.
     */
    public function introspect(string $accessToken): IntrospectResult
    {
        $data = $this->httpClient->post('/v1/gate/tokens/introspect', ['access_token' => $accessToken]);

        return IntrospectResult::fromArray($data ?? []);
    }

    /**
     * Gets a **signed JWT** (ES256) for a signed-in end user's session, for a platform that
     * verifies signatures instead of calling Gate (Supabase, an API gateway). The session
     * token is the credential: no API key is sent.
     *
     * The token is valid for 15 minutes and **cannot be revoked**; its `orgs` claim is as
     * old as the token. Every call mints a new one — ask again before the one you hold
     * expires. A revoked or expired session, or a deactivated user, is a `401`.
     */
    public function createSigned(string $sessionToken): SignedToken
    {
        $data = $this->httpClient->get(
            '/auth/sessions/whoami',
            ['tokenize_as' => 'gate_jwt'],
            ['X-Session-Token' => $sessionToken],
            skipAuth: true,
        );

        return SignedToken::fromArray($data ?? []);
    }

    /**
     * Verifies a Gate signed token offline against your tenant's public keys (fetched once
     * and cached) and returns its claims. Throws `VerneTokenException` when the token must
     * not be trusted — wrong issuer (another tenant), bad signature, expired, any algorithm
     * but ES256. See `GateTokenVerifier` to verify without a Gate client.
     *
     * A verified token is not proof the session is still alive: it cannot be revoked. For
     * anything that must follow a revocation or a role change at once, use
     * `sessions()->check()`, introspection or `organizations()->can()`.
     *
     * @param  int  $clockToleranceSeconds  allowed clock skew for `exp`, `nbf` and `iat`
     *
     * @throws VerneTokenException
     */
    public function verifySigned(string $token, string $tenantId, int $clockToleranceSeconds = GateTokenVerifier::DEFAULT_CLOCK_TOLERANCE_SECONDS): SignedTokenClaims
    {
        $cacheKey = $tenantId.'|'.$clockToleranceSeconds;

        $verifier = $this->verifiers[$cacheKey] ??= new GateTokenVerifier(
            tenantId: $tenantId,
            baseUrl: $this->httpClient->getBaseUrl(),
            clockToleranceSeconds: $clockToleranceSeconds,
            httpClient: $this->httpClient->getClient(),
        );

        return $verifier->verify($token);
    }
}
