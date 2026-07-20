<?php

declare(strict_types=1);

namespace Vernesoft\Core;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Vernesoft\Core\Errors\VerneApiException;
use Vernesoft\Core\Errors\VerneException;

class HttpClient
{
    private ClientInterface $client;

    public function __construct(
        private string $apiKey,
        private string $baseUrl = 'https://api.vernesoft.com',
        private int $timeoutSeconds = 30,
        ?ClientInterface $httpClient = null,
    ) {
        $this->client = $httpClient ?? new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => $this->timeoutSeconds,
        ]);
    }

    public function get(string $path, array $query = []): ?array
    {
        return $this->request('GET', $path, [], $query);
    }

    public function post(string $path, array $body = [], bool $skipAuth = false): ?array
    {
        return $this->requestWithRetry('POST', $path, $body, [], $skipAuth);
    }

    public function put(string $path, array $body = []): ?array
    {
        return $this->request('PUT', $path, $body);
    }

    public function patch(string $path, array $body = []): ?array
    {
        return $this->request('PATCH', $path, $body);
    }

    public function delete(string $path): ?array
    {
        return $this->request('DELETE', $path);
    }

    private function requestWithRetry(string $method, string $path, array $body, array $query, bool $skipAuth): ?array
    {
        try {
            return $this->send($method, $path, $body, $query, $skipAuth);
        } catch (VerneApiException $e) {
            if ($e->getCode() === 429) {
                // Will be handled by retry logic below — re-throw for now,
                // but we need the raw response for Retry-After. Use a flag approach.
                throw $e;
            }
            throw $e;
        }
    }

    private function request(string $method, string $path, array $body = [], array $query = [], bool $skipAuth = false): ?array
    {
        return $this->send($method, $path, $body, $query, $skipAuth);
    }

    private function send(string $method, string $path, array $body, array $query, bool $skipAuth): ?array
    {
        $headers = ['Content-Type' => 'application/json'];

        if (! $skipAuth) {
            $headers['Authorization'] = 'Bearer '.$this->apiKey;
        }

        $options = ['headers' => $headers];

        if (! empty($query)) {
            $options['query'] = $query;
        }

        if (! empty($body)) {
            $options['json'] = $body;
        }

        try {
            $response = $this->client->request($method, $path, $options);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $status = $response->getStatusCode();

                if ($status === 429 && $method === 'POST') {
                    $retryAfter = (int) ($response->getHeaderLine('Retry-After') ?: '1');
                    sleep($retryAfter);

                    try {
                        $response = $this->client->request($method, $path, $options);
                    } catch (RequestException $e2) {
                        if ($e2->hasResponse()) {
                            $this->throwApiException($e2->getResponse());
                        }
                        throw new VerneException($e2->getMessage(), 0, $e2);
                    }

                    return $this->parseResponse($response);
                }

                $this->throwApiException($response);
            }

            throw new VerneException($e->getMessage(), 0, $e);
        }

        return $this->parseResponse($response);
    }

    private function parseResponse(ResponseInterface $response): ?array
    {
        if ($response->getStatusCode() === 204) {
            return null;
        }

        $body = (string) $response->getBody();

        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new VerneException('Failed to decode API response: '.json_last_error_msg());
        }

        return $decoded;
    }

    private function throwApiException(ResponseInterface $response): never
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! isset($decoded['error'])) {
            throw new VerneException('API error (HTTP '.$status.'): '.$body);
        }

        $error = $decoded['error'];

        throw new VerneApiException(
            errorCode: $error['code'] ?? 'unknown',
            message: $error['message'] ?? 'Unknown error',
            httpStatus: $status,
            requestId: $error['request_id'] ?? '',
        );
    }
}
