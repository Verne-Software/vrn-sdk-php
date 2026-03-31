<?php

declare(strict_types=1);

namespace Vernesoft;

use GuzzleHttp\ClientInterface;
use Vernesoft\Core\HttpClient;
use Vernesoft\Resources\Relay\MessagesResource;

class Relay
{
    private HttpClient $httpClient;

    private ?MessagesResource $messagesResource = null;

    public function __construct(
        string $apiKey,
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

    public function messages(): MessagesResource
    {
        return $this->messagesResource ??= new MessagesResource($this->httpClient);
    }
}
