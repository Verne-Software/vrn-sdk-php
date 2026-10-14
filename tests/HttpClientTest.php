<?php

declare(strict_types=1);

namespace Vernesoft\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Vernesoft\Clockwork;
use Vernesoft\Core\Errors\VerneApiException;
use Vernesoft\Core\HttpClient;
use Vernesoft\Gate;
use Vernesoft\Passepartout;
use Vernesoft\Relay;

/**
 * The transport: the 429 retry policy and the error shapes the API really sends.
 */
class HttpClientTest extends TestCase
{
    private const KEY = 'vrn_9f8a7c1b2d3e4f50';

    /**
     * @param  Response[]  $responses
     * @return array{0: Client, 1: MockHandler}
     */
    private function guzzle(array $responses, array &$history = []): array
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        return [new Client(['handler' => $stack]), $mock];
    }

    private function caught(callable $call): VerneApiException
    {
        try {
            $call();
        } catch (VerneApiException $e) {
            return $e;
        }
        $this->fail('Expected a VerneApiException.');
    }

    // --- 429 retry policy ---

    public function test_monthly_relay_limit_is_thrown_at_once_with_retry_after_and_details(): void
    {
        $history = [];
        [$client, $mock] = $this->guzzle([
            new Response(429, ['Retry-After' => '1209600'], json_encode(['error' => [
                'code' => 'RELAY_MESSAGE_LIMIT_REACHED',
                'message' => 'You have sent the 10000 messages the Free plan includes this month.',
                'request_id' => 'req_1',
                'plan' => 'free',
                'limit' => 10000,
                'current' => 10000,
            ]])),
            new Response(202, [], '{}'),
        ], $history);
        $relay = new Relay(apiKey: self::KEY, httpClient: $client);

        $e = $this->caught(fn () => $relay->messages()->send(eventType: 'ping', payload: []));

        $this->assertCount(1, $history);
        $this->assertSame(429, $e->getCode());
        $this->assertSame('RELAY_MESSAGE_LIMIT_REACHED', $e->getErrorCode());
        $this->assertSame(1209600, $e->retryAfter);
        $this->assertSame(['plan' => 'free', 'limit' => 10000, 'current' => 10000], $e->details);
        $this->assertSame('req_1', $e->getRequestId());
    }

    public function test_relay_limit_code_is_not_retried_even_with_a_short_retry_after(): void
    {
        $history = [];
        [$client] = $this->guzzle([
            new Response(429, ['Retry-After' => '1'], json_encode(['error' => ['code' => 'RELAY_MESSAGE_LIMIT_REACHED', 'message' => 'x']])),
            new Response(202, [], '{}'),
        ], $history);
        $relay = new Relay(apiKey: self::KEY, httpClient: $client);

        $this->caught(fn () => $relay->messages()->send(eventType: 'ping', payload: []));

        $this->assertCount(1, $history);
    }

    public function test_a_429_with_retry_after_over_a_minute_is_not_retried(): void
    {
        $history = [];
        [$client] = $this->guzzle([
            new Response(429, ['Retry-After' => '61'], json_encode(['error' => ['code' => 'rate_limited', 'message' => 'slow down']])),
            new Response(202, [], '{}'),
        ], $history);
        $relay = new Relay(apiKey: self::KEY, httpClient: $client);

        $e = $this->caught(fn () => $relay->messages()->send(eventType: 'ping', payload: []));

        $this->assertCount(1, $history);
        $this->assertSame(61, $e->retryAfter);
        $this->assertSame('rate_limited', $e->getErrorCode());
    }

    public function test_a_402_is_never_retried_and_exposes_details(): void
    {
        $history = [];
        [$client] = $this->guzzle([
            new Response(402, [], json_encode(['error' => [
                'code' => 'UPGRADE_REQUIRED', 'message' => 'limit', 'request_id' => 'r', 'kind' => 'cron', 'limit' => 10, 'current' => 10,
            ]])),
            new Response(201, [], '{}'),
        ], $history);
        $clockwork = new Clockwork(apiKey: self::KEY, httpClient: $client);

        $e = $this->caught(fn () => $clockwork->jobs()->create(name: 'n', schedule: '0 * * * *', url: 'https://example.com'));

        $this->assertCount(1, $history);
        $this->assertSame(402, $e->getStatus());
        $this->assertSame('UPGRADE_REQUIRED', $e->getErrorCode());
        $this->assertSame('cron', $e->details['kind']);
    }

    public function test_a_short_429_is_retried_once_after_waiting(): void
    {
        $history = [];
        [$client] = $this->guzzle([
            new Response(429, ['Retry-After' => '7'], json_encode(['error' => ['code' => 'rate_limited', 'message' => 'x']])),
            new Response(201, [], json_encode(['access_token' => 'gat_live_x', 'expires_at' => '', 'subject' => 's', 'tenant_id' => 't'])),
        ], $history);
        $gate = new Gate(apiKey: self::KEY, httpClient: $client);
        $slept = [];
        $gate->getHttpClient()->setSleeper(function (int $s) use (&$slept): void {
            $slept[] = $s;
        });

        $token = $gate->tokens()->create(subject: 's');

        $this->assertSame('gat_live_x', $token->accessToken);
        $this->assertCount(2, $history);
        $this->assertSame([7], $slept);
    }

    public function test_a_429_without_retry_after_waits_one_second_and_retries_once_only(): void
    {
        $history = [];
        $limited = fn () => new Response(429, [], json_encode(['error' => ['code' => 'rate_limited', 'message' => 'x']]));
        [$client] = $this->guzzle([$limited(), $limited(), $limited()], $history);
        $gate = new Gate(apiKey: self::KEY, httpClient: $client);
        $slept = [];
        $gate->getHttpClient()->setSleeper(function (int $s) use (&$slept): void {
            $slept[] = $s;
        });

        $e = $this->caught(fn () => $gate->tokens()->create(subject: 's'));

        $this->assertCount(2, $history);
        $this->assertSame([1], $slept);
        $this->assertNull($e->retryAfter);
    }

    public function test_a_429_on_get_is_not_retried(): void
    {
        $history = [];
        [$client] = $this->guzzle([
            new Response(429, ['Retry-After' => '0'], json_encode(['error' => ['code' => 'rate_limited', 'message' => 'x']])),
            new Response(200, [], '[]'),
        ], $history);
        $clockwork = new Clockwork(apiKey: self::KEY, httpClient: $client);

        $this->caught(fn () => $clockwork->jobs()->list());

        $this->assertCount(1, $history);
    }

    // --- error bodies ---

    public function test_missing_scope_403_is_the_standard_envelope(): void
    {
        [$client] = $this->guzzle([
            new Response(403, [], json_encode(['error' => [
                'code' => 'forbidden',
                'message' => 'This API key is not authorized for Passepartout.',
                'request_id' => 'req_x',
            ]])),
        ]);
        $pp = new Passepartout(apiKey: self::KEY, httpClient: $client);

        $e = $this->caught(fn () => $pp->loginStart());

        $this->assertSame(403, $e->getCode());
        $this->assertSame('forbidden', $e->getErrorCode());
        $this->assertSame('req_x', $e->getRequestId());
        $this->assertSame('This API key is not authorized for Passepartout.', $e->getMessage());
        $this->assertSame([], $e->details);
    }

    public function test_a_string_error_keeps_its_sentence_with_code_unknown(): void
    {
        [$client] = $this->guzzle([
            new Response(400, [], json_encode(['error' => 'Invalid cron schedule. Use five fields.'])),
        ]);
        $clockwork = new Clockwork(apiKey: self::KEY, httpClient: $client);

        $e = $this->caught(fn () => $clockwork->jobs()->create(name: 'n', schedule: 'bad', url: 'https://example.com'));

        $this->assertSame('unknown', $e->getErrorCode());
        $this->assertSame('Invalid cron schedule. Use five fields.', $e->getMessage());
        $this->assertNull($e->getRequestId());
        $this->assertSame(['error' => 'Invalid cron schedule. Use five fields.'], $e->body);
    }

    public function test_a_numeric_code_is_never_used_as_the_code(): void
    {
        [$client] = $this->guzzle([
            new Response(404, [], json_encode(['error' => ['code' => 404, 'status' => 'Not Found', 'message' => 'Unable to locate the resource']])),
        ]);
        $gate = new Gate(apiKey: self::KEY, httpClient: $client);

        $e = $this->caught(fn () => $gate->sessions()->revokeAll('u1'));

        $this->assertSame(404, $e->getCode());
        $this->assertSame('unknown', $e->getErrorCode());
        $this->assertSame('Unable to locate the resource', $e->getMessage());
        $this->assertSame(['status' => 'Not Found'], $e->details);
    }

    public function test_an_envelope_without_request_id_has_a_null_request_id(): void
    {
        [$client] = $this->guzzle([
            new Response(400, [], json_encode(['error' => ['code' => 'invalid_request', 'message' => 'subject is required']])),
        ]);
        $gate = new Gate(apiKey: self::KEY, httpClient: $client);

        $e = $this->caught(fn () => $gate->tokens()->create(subject: ''));

        $this->assertSame('invalid_request', $e->getErrorCode());
        $this->assertNull($e->getRequestId());
    }

    public function test_mfa_required_exposes_required_and_current_aal_in_details(): void
    {
        [$client] = $this->guzzle([
            new Response(403, [], json_encode(['error' => [
                'code' => 'mfa_required', 'message' => 'A second factor is required.', 'request_id' => 'req_m',
                'required_aal' => 'aal2', 'current_aal' => 'aal1',
            ]])),
        ]);
        $gate = new Gate(apiKey: self::KEY, httpClient: $client);

        $e = $this->caught(fn () => $gate->sessions()->check('ory_st_x'));

        $this->assertSame('mfa_required', $e->getErrorCode());
        $this->assertSame(['required_aal' => 'aal2', 'current_aal' => 'aal1'], $e->getDetails());
    }

    public function test_a_body_that_is_not_json_is_still_an_api_exception_with_the_status(): void
    {
        [$client] = $this->guzzle([new Response(502, [], '<html>bad gateway</html>')]);
        $gate = new Gate(apiKey: self::KEY, httpClient: $client);

        $e = $this->caught(fn () => $gate->identities()->get('x'));

        $this->assertSame(502, $e->getCode());
        $this->assertSame('unknown', $e->getErrorCode());
        $this->assertSame('HTTP 502', $e->getMessage());
        $this->assertSame('<html>bad gateway</html>', $e->body);
    }

    public function test_an_empty_error_body_is_http_status(): void
    {
        [$client] = $this->guzzle([new Response(500, [], '')]);
        $gate = new Gate(apiKey: self::KEY, httpClient: $client);

        $e = $this->caught(fn () => $gate->identities()->get('x'));

        $this->assertSame('HTTP 500', $e->getMessage());
        $this->assertNull($e->body);
    }

    public function test_retry_after_is_read_on_any_error(): void
    {
        [$client] = $this->guzzle([new Response(503, ['Retry-After' => '30'], json_encode(['error' => ['code' => 'upstream_unavailable', 'message' => 'x']]))]);
        $gate = new Gate(apiKey: self::KEY, httpClient: $client);

        $e = $this->caught(fn () => $gate->identities()->get('x'));

        $this->assertSame(30, $e->retryAfter);
    }

    // --- helpers ---

    public function test_build_query_drops_null_and_formats_dates_and_bools(): void
    {
        $query = HttpClient::buildQuery([
            'limit' => 5,
            'cursor' => null,
            'from' => new \DateTimeImmutable('2026-10-14T12:00:00+02:00'),
            'flag' => true,
            'action' => 'a b',
        ]);

        $this->assertSame('limit=5&from=2026-10-14T10%3A00%3A00Z&flag=true&action=a%20b', $query);
    }

    public function test_parse_retry_after_accepts_seconds_and_http_dates(): void
    {
        $this->assertSame(120, HttpClient::parseRetryAfter('120'));
        $this->assertNull(HttpClient::parseRetryAfter(''));
        $this->assertNull(HttpClient::parseRetryAfter('soon'));
        $inAMinute = gmdate('D, d M Y H:i:s', time() + 60).' GMT';
        $this->assertGreaterThanOrEqual(58, HttpClient::parseRetryAfter($inAMinute));
    }

    public function test_requests_send_accept_json(): void
    {
        $history = [];
        [$client] = $this->guzzle([new Response(200, [], '[]')], $history);
        $clockwork = new Clockwork(apiKey: self::KEY, httpClient: $client);

        $clockwork->jobs()->list();

        $this->assertSame('application/json', $history[0]['request']->getHeaderLine('Accept'));
    }
}
