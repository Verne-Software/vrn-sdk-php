<?php

declare(strict_types=1);

namespace Vernesoft;

use GuzzleHttp\ClientInterface;
use Vernesoft\Core\HttpClient;
use Vernesoft\Resources\Passepartout\Types\LoginStart;
use Vernesoft\Resources\Passepartout\Types\LoginStatus;
use Vernesoft\Resources\Passepartout\Types\TokenIntrospection;

class Passepartout
{
    private HttpClient $httpClient;

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

    public function loginStart(): LoginStart
    {
        $data = $this->httpClient->post('/v1/passepartout/login/start');

        return LoginStart::fromArray($data);
    }

    public function loginStatus(string $nonce): LoginStatus
    {
        $data = $this->httpClient->get('/v1/passepartout/login/status?nonce='.rawurlencode($nonce));

        return LoginStatus::fromArray($data);
    }

    public function introspect(string $accessToken): TokenIntrospection
    {
        $data = $this->httpClient->post('/v1/passepartout/tokens/introspect', ['access_token' => $accessToken]);

        return TokenIntrospection::fromArray($data);
    }
}
