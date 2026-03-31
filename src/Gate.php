<?php

declare(strict_types=1);

namespace Vernesoft;

use GuzzleHttp\ClientInterface;
use Vernesoft\Core\HttpClient;
use Vernesoft\Resources\Gate\IdentitiesResource;
use Vernesoft\Resources\Gate\TokensResource;
use Vernesoft\Resources\Gate\Types\AuthorizeResult;

class Gate
{
    private HttpClient $httpClient;

    private ?IdentitiesResource $identitiesResource = null;

    private ?TokensResource $tokensResource = null;

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
}
