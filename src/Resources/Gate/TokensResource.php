<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate;

use Vernesoft\Core\HttpClient;
use Vernesoft\Resources\Gate\Types\AccessToken;
use Vernesoft\Resources\Gate\Types\IntrospectResult;

class TokensResource
{
    public function __construct(
        private HttpClient $httpClient,
        private string $apiKey,
    ) {}

    public function create(
        string $subject,
        ?array $scopes = null,
        ?int $ttlSeconds = null,
    ): AccessToken {
        $body = [
            'api_key' => $this->apiKey,
            'subject' => $subject,
        ];

        if ($scopes !== null) {
            $body['scopes'] = $scopes;
        }

        if ($ttlSeconds !== null) {
            $body['ttl_seconds'] = $ttlSeconds;
        }

        $data = $this->httpClient->post('/v1/gate/tokens', $body, skipAuth: true);

        return AccessToken::fromArray($data);
    }

    public function introspect(string $accessToken): IntrospectResult
    {
        $data = $this->httpClient->post('/v1/gate/tokens/introspect', ['access_token' => $accessToken]);

        return IntrospectResult::fromArray($data);
    }
}
