<?php

declare(strict_types=1);

namespace Vernesoft;

use GuzzleHttp\ClientInterface;
use Vernesoft\Core\HttpClient;
use Vernesoft\Resources\Gate\IdentitiesResource;
use Vernesoft\Resources\Gate\SettingsResource;
use Vernesoft\Resources\Gate\TokensResource;
use Vernesoft\Resources\Gate\Types\AuthorizeResult;

class Gate
{
    private HttpClient $httpClient;

    private ?IdentitiesResource $identitiesResource = null;

    private ?TokensResource $tokensResource = null;

    private ?SettingsResource $settingsResource = null;

    public function __construct(
        private string $apiKey,
        string $baseUrl = 'https://api.vernesoft.com',
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

    public function identities(): IdentitiesResource
    {
        return $this->identitiesResource ??= new IdentitiesResource($this->httpClient);
    }

    public function tokens(): TokensResource
    {
        return $this->tokensResource ??= new TokensResource($this->httpClient, $this->apiKey);
    }

    public function settings(): SettingsResource
    {
        return $this->settingsResource ??= new SettingsResource($this->httpClient);
    }

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
        $data = $this->httpClient->get('/public/gate/providers/'.rawurlencode($tenantId));

        return $data['providers'] ?? [];
    }

    /**
     * Initializes a Kratos login flow using your Gate API key. Call this from
     * your server and pass the returned flow to your browser-side code to
     * render social login buttons. The flow already contains only the
     * providers your tenant has enabled.
     *
     * @return array<string, mixed>
     */
    public function createLoginFlow(): array
    {
        return $this->httpClient->get('/v1/gate/auth/login') ?? [];
    }
}
