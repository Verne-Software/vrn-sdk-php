<?php

declare(strict_types=1);

namespace Vernesoft\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Vernesoft\Core\Errors\VerneApiException;
use Vernesoft\Passepartout;

class PassepartoutTest extends TestCase
{
    private function makePassepartout(array $responses, array &$history = []): Passepartout
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $guzzle = new Client(['handler' => $stack]);

        return new Passepartout(apiKey: 'vrn_passepartout_test_sk_abc', httpClient: $guzzle);
    }

    // --- login/start ---

    public function test_login_start_returns_login_start(): void
    {
        $history = [];
        $passepartout = $this->makePassepartout([
            new Response(200, [], json_encode([
                'nonce' => 'nonce_abc123',
                'deep_link' => 'https://t.me/verne_bot?start=nonce_abc123',
                'expires_at' => '2026-03-17T12:00:00Z',
            ])),
        ], $history);

        $result = $passepartout->loginStart();

        $this->assertSame('nonce_abc123', $result->nonce);
        $this->assertSame('https://t.me/verne_bot?start=nonce_abc123', $result->deepLink);
        $this->assertSame('2026-03-17T12:00:00Z', $result->expiresAt);

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/v1/passepartout/login/start', $request->getUri()->getPath());
        $this->assertTrue($request->hasHeader('Authorization'));
        $this->assertSame('Bearer vrn_passepartout_test_sk_abc', $request->getHeaderLine('Authorization'));
    }

    // --- login/status ---

    public function test_login_status_pending(): void
    {
        $history = [];
        $passepartout = $this->makePassepartout([
            new Response(200, [], json_encode([
                'status' => 'pending',
            ])),
        ], $history);

        $result = $passepartout->loginStatus('nonce_abc123');

        $this->assertSame('pending', $result->status);
        $this->assertNull($result->accessToken);
        $this->assertNull($result->user);

        $request = $history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/v1/passepartout/login/status', $request->getUri()->getPath());
        $this->assertSame('nonce=nonce_abc123', $request->getUri()->getQuery());
        $this->assertTrue($request->hasHeader('Authorization'));
    }

    public function test_login_status_completed_maps_user(): void
    {
        $passepartout = $this->makePassepartout([
            new Response(200, [], json_encode([
                'status' => 'completed',
                'access_token' => 'pat_test_at_abc123',
                'expires_at' => '2026-03-17T12:00:00Z',
                'identity_id' => 'identity_123',
                'user' => [
                    'id' => '123456789',
                    'username' => 'jules',
                    'first_name' => 'Jules',
                    'photo_url' => 'https://t.me/i/userpic/jules.jpg',
                ],
            ])),
        ]);

        $result = $passepartout->loginStatus('nonce_abc123');

        $this->assertSame('completed', $result->status);
        $this->assertSame('pat_test_at_abc123', $result->accessToken);
        $this->assertSame('2026-03-17T12:00:00Z', $result->expiresAt);
        $this->assertSame('identity_123', $result->identityId);
        $this->assertNotNull($result->user);
        $this->assertSame('123456789', $result->user->id);
        $this->assertSame('jules', $result->user->username);
        $this->assertSame('Jules', $result->user->firstName);
        $this->assertSame('https://t.me/i/userpic/jules.jpg', $result->user->photoUrl);
    }

    public function test_login_status_urlencodes_nonce(): void
    {
        $history = [];
        $passepartout = $this->makePassepartout([
            new Response(200, [], json_encode(['status' => 'pending'])),
        ], $history);

        $passepartout->loginStatus('a b+c/d');

        $request = $history[0]['request'];
        $this->assertSame('nonce=a%20b%2Bc%2Fd', $request->getUri()->getQuery());
    }

    // --- tokens/introspect ---

    public function test_introspect_active(): void
    {
        $history = [];
        $passepartout = $this->makePassepartout([
            new Response(200, [], json_encode([
                'active' => true,
                'subject' => 'usr_123',
                'tenant_id' => 'ten_001',
                'scopes' => ['passepartout.tokens.read'],
                'expires_at' => '2026-03-17T12:00:00Z',
            ])),
        ], $history);

        $result = $passepartout->introspect('pat_test_at_abc123');

        $this->assertTrue($result->active);
        $this->assertSame('usr_123', $result->subject);
        $this->assertSame('ten_001', $result->tenantId);
        $this->assertSame(['passepartout.tokens.read'], $result->scopes);
        $this->assertSame('2026-03-17T12:00:00Z', $result->expiresAt);

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/v1/passepartout/tokens/introspect', $request->getUri()->getPath());
        $this->assertTrue($request->hasHeader('Authorization'));
        $this->assertSame(
            ['access_token' => 'pat_test_at_abc123'],
            json_decode((string) $request->getBody(), true),
        );
    }

    public function test_introspect_inactive(): void
    {
        $passepartout = $this->makePassepartout([
            new Response(200, [], json_encode([
                'active' => false,
            ])),
        ]);

        $result = $passepartout->introspect('pat_expired');

        $this->assertFalse($result->active);
        $this->assertNull($result->subject);
        $this->assertNull($result->tenantId);
        $this->assertNull($result->scopes);
        $this->assertNull($result->expiresAt);
    }

    // --- errors ---

    public function test_throws_verne_api_exception_on_error(): void
    {
        $passepartout = $this->makePassepartout([
            new Response(404, [], json_encode([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Login session not found.',
                    'request_id' => 'req_404',
                ],
            ])),
        ]);

        $this->expectException(VerneApiException::class);
        $this->expectExceptionCode(404);

        $passepartout->loginStatus('nonexistent');
    }
}
