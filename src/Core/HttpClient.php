<?php

declare(strict_types=1);

namespace Vernesoft\Core;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Vernesoft\Core\Errors\VerneApiException;
use Vernesoft\Core\Errors\VerneException;

class HttpClient
{
    public const DEFAULT_BASE_URL = 'https://api.vernesoft.com';

    /**
     * The longest `Retry-After` the client waits out on its own before retrying a `429`.
     * Anything longer — Relay's monthly limit answers with the seconds until the 1st of
     * next month — is thrown at once, with `retryAfter` on the exception.
     */
    public const MAX_AUTO_RETRY_AFTER_SECONDS = 60;

    /** `429` codes that waiting a few seconds does not lift. */
    private const NON_RETRYABLE_429_CODES = ['RELAY_MESSAGE_LIMIT_REACHED'];

    private ClientInterface $client;

    private string $baseUrl;

    /** @var \Closure(int): void */
    private \Closure $sleeper;

    public function __construct(
        private string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        private int $timeoutSeconds = 30,
        ?ClientInterface $httpClient = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->client = $httpClient ?? new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => $this->timeoutSeconds,
        ]);
        $this->sleeper = static function (int $seconds): void {
            if ($seconds > 0) {
                sleep($seconds);
            }
        };
    }

    /** The base URL without a trailing slash. */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /** The underlying Guzzle client (shared with the signed-token verifier). */
    public function getClient(): ClientInterface
    {
        return $this->client;
    }

    /**
     * Replaces the function used to wait before retrying a short `429`. For tests.
     *
     * @param  callable(int): void  $sleeper
     */
    public function setSleeper(callable $sleeper): void
    {
        $this->sleeper = \Closure::fromCallable($sleeper);
    }

    /**
     * @param  array<string, mixed>  $query  `null` values are dropped.
     * @param  array<string, string>  $headers
     */
    public function get(string $path, array $query = [], array $headers = [], bool $skipAuth = false): ?array
    {
        return $this->request('GET', $path, null, $query, $headers, $skipAuth);
    }

    /**
     * @param  array<mixed>|object|null  $body  sent as JSON; `null` sends no body.
     * @param  array<string, string>  $headers
     */
    public function post(string $path, array|object|null $body = null, bool $skipAuth = false, array $headers = []): ?array
    {
        return $this->request('POST', $path, $body, [], $headers, $skipAuth);
    }

    /**
     * @param  array<mixed>|object|null  $body
     */
    public function put(string $path, array|object|null $body = null): ?array
    {
        return $this->request('PUT', $path, $body);
    }

    /**
     * @param  array<mixed>|object|null  $body
     */
    public function patch(string $path, array|object|null $body = null): ?array
    {
        return $this->request('PATCH', $path, $body);
    }

    public function delete(string $path): ?array
    {
        return $this->request('DELETE', $path);
    }

    /**
     * One path segment, URL-encoded: ids come from callers and must not reshape the path.
     */
    public static function seg(string $value): string
    {
        return rawurlencode($value);
    }

    /**
     * An RFC 3339 UTC timestamp from a `DateTimeInterface`; a string is passed through.
     */
    public static function formatTime(string|\DateTimeInterface $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return \DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $extraHeaders
     */
    private function request(
        string $method,
        string $path,
        array|object|null $body = null,
        array $query = [],
        array $extraHeaders = [],
        bool $skipAuth = false,
    ): ?array {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if (! $skipAuth) {
            $headers['Authorization'] = 'Bearer '.$this->apiKey;
        }

        $options = [
            'headers' => array_merge($headers, $extraHeaders),
            'http_errors' => false,
        ];

        $queryString = self::buildQuery($query);
        if ($queryString !== '') {
            $path .= (str_contains($path, '?') ? '&' : '?').$queryString;
        }

        if ($body !== null) {
            $options['json'] = $body;
        }

        for ($attempt = 1; ; $attempt++) {
            $response = $this->send($method, $path, $options);
            $status = $response->getStatusCode();

            if ($status < 400) {
                return $this->parseResponse($response);
            }

            $error = $this->buildException($response);

            // One retry of a POST on a short 429 (the per-minute burst ceilings). Never on a
            // limit that lifts at the end of the month, and never on a 402.
            $wait = $error->retryAfter ?? 1;
            if (
                $status === 429
                && $attempt === 1
                && $method === 'POST'
                && $wait <= self::MAX_AUTO_RETRY_AFTER_SECONDS
                && ! in_array($error->getErrorCode(), self::NON_RETRYABLE_429_CODES, true)
            ) {
                ($this->sleeper)($wait);

                continue;
            }

            throw $error;
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function send(string $method, string $path, array $options): ResponseInterface
    {
        try {
            return $this->client->request($method, $path, $options);
        } catch (GuzzleException $e) {
            throw new VerneException('Network error: '.$e->getMessage(), 0, $e);
        }
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

        if (! is_array($decoded)) {
            throw new VerneException('Unexpected API response: not a JSON object or array.');
        }

        return $decoded;
    }

    private function buildException(ResponseInterface $response): VerneApiException
    {
        $status = $response->getStatusCode();
        $retryAfter = self::parseRetryAfter($response->getHeaderLine('Retry-After'));
        $raw = (string) $response->getBody();
        $decoded = $raw === '' ? null : json_decode($raw, true);

        if ($raw !== '' && json_last_error() !== JSON_ERROR_NONE) {
            $decoded = $raw;
        }

        return VerneApiException::fromResponse($status, $decoded, $retryAfter);
    }

    /**
     * Seconds from a `Retry-After` header (delta-seconds or an HTTP date), or null.
     */
    public static function parseRetryAfter(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            $seconds = (float) $raw;

            return $seconds >= 0 ? (int) ceil($seconds) : null;
        }

        $at = strtotime($raw);
        if ($at === false) {
            return null;
        }

        return max(0, $at - time());
    }

    /**
     * `a=1&b=2` from a params array: `null` dropped, a `DateTimeInterface` as RFC 3339
     * UTC, a bool as `true`/`false`.
     *
     * @param  array<string, mixed>  $params
     */
    public static function buildQuery(array $params): string
    {
        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            if ($value instanceof \DateTimeInterface) {
                $value = self::formatTime($value);
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $pairs[] = rawurlencode((string) $key).'='.rawurlencode((string) $value);
        }

        return implode('&', $pairs);
    }
}
