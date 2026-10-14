<?php

declare(strict_types=1);

namespace Vernesoft;

use GuzzleHttp\ClientInterface;
use Vernesoft\Core\HttpClient;
use Vernesoft\Resources\Gate\AuditLogResource;
use Vernesoft\Resources\Gate\IdentitiesResource;
use Vernesoft\Resources\Gate\LifecycleResource;
use Vernesoft\Resources\Gate\OrganizationsResource;
use Vernesoft\Resources\Gate\SessionsResource;
use Vernesoft\Resources\Gate\SettingsResource;
use Vernesoft\Resources\Gate\TokensResource;
use Vernesoft\Resources\Gate\Types\AuthorizeResult;
use Vernesoft\Resources\Gate\Types\OidcExchangeResult;

/**
 * Gate — sign-in and users. Needs a key (`vrn_…`) with the `gate` scope; without it every
 * call is `403 forbidden`.
 */
class Gate
{
    private HttpClient $httpClient;

    private ?IdentitiesResource $identitiesResource = null;

    private ?TokensResource $tokensResource = null;

    private ?SettingsResource $settingsResource = null;

    private ?OrganizationsResource $organizationsResource = null;

    private ?SessionsResource $sessionsResource = null;

    private ?AuditLogResource $auditLogResource = null;

    private ?LifecycleResource $lifecycleResource = null;

    public function __construct(
        string $apiKey,
        string $baseUrl = HttpClient::DEFAULT_BASE_URL,
        int $timeoutSeconds = 30,
        ?ClientInterface $httpClient = null,
    ) {
        $this->httpClient = new HttpClient(
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            timeoutSeconds: $timeoutSeconds,
            httpClient: $httpClient,
        );
    }

    /** @internal The transport, for tests. */
    public function getHttpClient(): HttpClient
    {
        return $this->httpClient;
    }

    public function identities(): IdentitiesResource
    {
        return $this->identitiesResource ??= new IdentitiesResource($this->httpClient);
    }

    public function tokens(): TokensResource
    {
        return $this->tokensResource ??= new TokensResource($this->httpClient);
    }

    public function settings(): SettingsResource
    {
        return $this->settingsResource ??= new SettingsResource($this->httpClient);
    }

    public function organizations(): OrganizationsResource
    {
        return $this->organizationsResource ??= new OrganizationsResource($this->httpClient);
    }

    public function sessions(): SessionsResource
    {
        return $this->sessionsResource ??= new SessionsResource($this->httpClient);
    }

    public function auditLog(): AuditLogResource
    {
        return $this->auditLogResource ??= new AuditLogResource($this->httpClient);
    }

    public function lifecycle(): LifecycleResource
    {
        return $this->lifecycleResource ??= new LifecycleResource($this->httpClient);
    }

    /**
     * Asks whether a subject may perform an action on a resource. The answer is a
     * decision; your service enforces it.
     *
     * - `resource: 'organization:<id>'` — may this end user do `action` (a permission) in
     *   that organization? `reason` is one of the `AuthorizeReason` constants and `role`
     *   the role they hold. Prefer `organizations()->authorize()` / `can()`, which build
     *   the resource.
     * - any other resource — only *is `subject` one of your active end users?*; `reason`
     *   is `owned_by_tenant` (allowed), `subject_inactive`, `not_owned_by_tenant` or
     *   `not_a_known_identity`, and `message` says it in a sentence for your logs.
     *
     * @param  array<string, mixed>|null  $context  accepted and ignored by the API today
     */
    public function authorize(
        string $subject,
        string $action,
        string $resource,
        ?array $context = null,
    ): AuthorizeResult {
        $body = [
            'subject' => $subject,
            'action' => $action,
            'resource' => $resource,
        ];

        if ($context !== null) {
            $body['context'] = $context;
        }

        $data = $this->httpClient->post('/v1/gate/authorize', $body);

        return AuthorizeResult::fromArray($data);
    }

    /**
     * Returns the social login providers currently enabled for a tenant. This
     * is a public, unauthenticated endpoint — call it from your login /
     * registration page to decide which social buttons to render.
     *
     * @return string[]
     */
    public function getEnabledProviders(string $tenantId): array
    {
        $data = $this->httpClient->get('/public/gate/providers/'.HttpClient::seg($tenantId), skipAuth: true);

        return $data['providers'] ?? [];
    }

    /**
     * Starts a login flow with your API key and returns it (the identity service's flow
     * object); render its `ui.nodes` or post credentials to its `ui.action`. Every
     * argument is optional — `createLoginFlow()` works as in 1.3.0.
     *
     * @param  string|null  $returnTo  one of the project's registered return URLs, exactly
     *                                 as registered (`settings()->updateReturnUrls()`); the
     *                                 first one is used when absent
     * @param  string|null  $state  an opaque value of yours (1–256 of letters, digits,
     *                              `-`, `.`, `_`, `~`) returned unchanged on the redirect to
     *                              your return URL. Always send one for social login, and check it.
     * @param  string|null  $aal  `aal2` starts a step-up (second factor) flow; needs `$sessionToken`
     * @param  string|null  $sessionToken  the end user's session token for a step-up flow,
     *                                     sent as the `X-Session-Token` header (never in the URL)
     * @return array<string, mixed>
     */
    public function createLoginFlow(
        ?string $returnTo = null,
        ?string $state = null,
        ?string $aal = null,
        ?string $sessionToken = null,
    ): array {
        $headers = $sessionToken !== null ? ['X-Session-Token' => $sessionToken] : [];

        return $this->httpClient->get(
            '/v1/gate/auth/login',
            ['return_to' => $returnTo, 'state' => $state, 'aal' => $aal],
            $headers,
        ) ?? [];
    }

    /**
     * Starts a registration flow. Refused with `402 ACTIVE_USER_LIMIT_REACHED` when the
     * account is past its active-user limit.
     *
     * @param  string|null  $returnTo  as for `createLoginFlow()`
     * @param  string|null  $state  as for `createLoginFlow()`
     * @return array<string, mixed>
     */
    public function createRegistrationFlow(?string $returnTo = null, ?string $state = null): array
    {
        return $this->httpClient->get(
            '/v1/gate/auth/registration',
            ['return_to' => $returnTo, 'state' => $state],
        ) ?? [];
    }

    /**
     * Exchanges the one-time `code` your return URL received after a social login for the
     * user's session. The code works once, for about 60 seconds, and only with a key of
     * the project that started the login (otherwise `404`). Keep the session token on
     * your server.
     */
    public function exchangeOidcCode(string $code): OidcExchangeResult
    {
        $data = $this->httpClient->post('/v1/gate/auth/oidc/exchange', ['code' => $code]);

        return OidcExchangeResult::fromArray($data ?? []);
    }
}
