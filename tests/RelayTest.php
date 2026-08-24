<?php

declare(strict_types=1);

namespace Vernesoft\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Vernesoft\Core\Errors\VerneApiException;
use Vernesoft\Relay;

class RelayTest extends TestCase
{
    private function makeRelay(array $responses): Relay
    {
        $mock = new MockHandler($responses);
        $guzzle = new Client(['handler' => HandlerStack::create($mock)]);

        return new Relay(apiKey: 'vrn_relay_test_sk_abc', httpClient: $guzzle);
    }

    public function test_send_returns_message(): void
    {
        $relay = $this->makeRelay([
            new Response(202, [], json_encode([
                'id' => 'msg_001',
                'event_type' => 'user.created',
                'status' => 'accepted',
                'timestamp' => '2026-01-01T00:00:00Z',
            ])),
        ]);

        $message = $relay->messages()->send(
            eventType: 'user.created',
            payload: ['id' => '123'],
        );

        $this->assertSame('msg_001', $message->id);
        $this->assertSame('user.created', $message->eventType);
        $this->assertSame('accepted', $message->status);
        $this->assertSame('2026-01-01T00:00:00Z', $message->timestamp);
    }

    public function test_send_with_optional_params(): void
    {
        $relay = $this->makeRelay([
            new Response(202, [], json_encode([
                'id' => 'msg_002',
                'event_type' => 'order.placed',
                'status' => 'accepted',
                'timestamp' => '2026-01-01T00:00:00Z',
            ])),
        ]);

        $message = $relay->messages()->send(
            eventType: 'order.placed',
            payload: ['amount' => 100],
            idempotencyKey: 'idem_key_001',
            channels: ['team-a', 'team-b'],
        );

        $this->assertSame('msg_002', $message->id);
    }

    public function test_list_returns_messages(): void
    {
        $relay = $this->makeRelay([
            new Response(200, [], json_encode([
                'data' => [
                    [
                        'id' => 'msg_001',
                        'event_type' => 'user.created',
                        'status' => 'accepted',
                        'timestamp' => '2026-01-01T00:00:00Z',
                    ],
                ],
                'has_more' => true,
                'next_cursor' => 'cursor_abc',
            ])),
        ]);

        $page = $relay->messages()->list(limit: 10, eventType: 'user.created');

        $this->assertCount(1, $page->data);
        $this->assertSame('msg_001', $page->data[0]->id);
        $this->assertTrue($page->hasMore);
        $this->assertSame('cursor_abc', $page->nextCursor);
    }

    public function test_list_with_cursor(): void
    {
        $relay = $this->makeRelay([
            new Response(200, [], json_encode([
                'data' => [],
                'has_more' => false,
                'next_cursor' => null,
            ])),
        ]);

        $page = $relay->messages()->list(limit: 20, cursor: 'cursor_abc');

        $this->assertCount(0, $page->data);
        $this->assertFalse($page->hasMore);
        $this->assertNull($page->nextCursor);
    }

    public function test_send_throws_verne_api_exception_on400(): void
    {
        $relay = $this->makeRelay([
            new Response(400, [], json_encode([
                'error' => [
                    'code' => 'invalid_payload',
                    'message' => "Field 'event_type' is required.",
                    'request_id' => 'req_abc123',
                ],
            ])),
        ]);

        $this->expectException(VerneApiException::class);
        $this->expectExceptionCode(400);

        $relay->messages()->send(eventType: 'ping', payload: []);
    }

    public function test_send_api_exception_carries_error_code_and_request_id(): void
    {
        $relay = $this->makeRelay([
            new Response(400, [], json_encode([
                'error' => [
                    'code' => 'invalid_payload',
                    'message' => "Field 'event_type' is required.",
                    'request_id' => 'req_abc123',
                ],
            ])),
        ]);

        try {
            $relay->messages()->send(eventType: 'ping', payload: []);
            $this->fail('Expected VerneApiException');
        } catch (VerneApiException $e) {
            $this->assertSame('invalid_payload', $e->getErrorCode());
            $this->assertSame('req_abc123', $e->getRequestId());
            $this->assertSame(400, $e->getCode());
        }
    }

    public function test_send_throws_on401(): void
    {
        $relay = $this->makeRelay([
            new Response(401, [], json_encode([
                'error' => [
                    'code' => 'unauthorized',
                    'message' => 'Invalid API key.',
                    'request_id' => 'req_xyz',
                ],
            ])),
        ]);

        $this->expectException(VerneApiException::class);
        $this->expectExceptionCode(401);

        $relay->messages()->send(eventType: 'ping', payload: []);
    }

    public function test_send_retries_on429_and_succeeds(): void
    {
        $relay = $this->makeRelay([
            new Response(429, ['Retry-After' => '0'], json_encode([
                'error' => [
                    'code' => 'rate_limit_exceeded',
                    'message' => 'Too many requests.',
                    'request_id' => 'req_rate',
                ],
            ])),
            new Response(202, [], json_encode([
                'id' => 'msg_retry',
                'event_type' => 'user.created',
                'status' => 'accepted',
                'timestamp' => '2026-01-01T00:00:00Z',
            ])),
        ]);

        $message = $relay->messages()->send(eventType: 'user.created', payload: []);

        $this->assertSame('msg_retry', $message->id);
    }

    /**
     * A repeated idempotency key replays; it does not fail.
     *
     * This test asserted a 409 until the gateway grew a translation layer for
     * `/v1/relay/*` and the reference was corrected to match what Relay
     * actually does: the second send returns 202 with the *originally* accepted
     * message, same id and same timestamp. Nothing here has to tell a duplicate
     * apart from a success, which is the point.
     *
     * Error mapping is still covered — on the statuses the API really returns —
     * by the 400 and 401 tests above.
     */
    public function test_send_replays_a_repeated_idempotency_key(): void
    {
        $accepted = json_encode([
            'id' => 'msg_original',
            'event_type' => 'user.created',
            'status' => 'accepted',
            'timestamp' => '2026-01-01T00:00:00Z',
        ]);

        $relay = $this->makeRelay([
            new Response(202, [], $accepted),
            new Response(202, [], $accepted),
        ]);

        $first = $relay->messages()->send(
            eventType: 'user.created',
            payload: ['n' => 1],
            idempotencyKey: 'dup_key',
        );
        $second = $relay->messages()->send(
            eventType: 'user.created',
            payload: ['n' => 2],
            idempotencyKey: 'dup_key',
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->timestamp, $second->timestamp);
        $this->assertSame('accepted', $second->status);
    }
}
