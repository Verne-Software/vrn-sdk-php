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

    public function test_send_throws_on409_duplicate(): void
    {
        $relay = $this->makeRelay([
            new Response(409, [], json_encode([
                'error' => [
                    'code' => 'duplicate_idempotency_key',
                    'message' => 'Duplicate idempotency key.',
                    'request_id' => 'req_dup',
                ],
            ])),
        ]);

        $this->expectException(VerneApiException::class);
        $this->expectExceptionCode(409);

        $relay->messages()->send(eventType: 'ping', payload: [], idempotencyKey: 'dup_key');
    }
}
