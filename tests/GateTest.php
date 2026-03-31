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
use Vernesoft\Gate;

class GateTest extends TestCase
{
    private function makeGate(array $responses, array &$history = []): Gate
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $guzzle = new Client(['handler' => $stack]);

        return new Gate(apiKey: 'vrn_gate_test_sk_abc', httpClient: $guzzle);
    }

    // --- Identities ---

    public function test_create_identity_returns_identity(): void
    {
        $gate = $this->makeGate([
            new Response(201, [], json_encode([
                'id' => 'identity_123',
                'schema_id' => 'user',
                'state' => 'active',
                'traits' => [
                    'email' => 'user@example.com',
                    'tenant_id' => 'ten_001',
                    'custom_data' => ['role' => 'editor'],
                ],
            ])),
        ]);

        $identity = $gate->identities()->create(
            schemaId: 'user',
            traits: ['email' => 'user@example.com', 'custom_data' => ['role' => 'editor']],
            credentials: ['password' => ['config' => ['password' => 'Secret123!']]],
            state: 'active',
        );

        $this->assertSame('identity_123', $identity->id);
        $this->assertSame('user', $identity->schemaId);
        $this->assertSame('active', $identity->state);
        $this->assertSame('user@example.com', $identity->traits->email);
        $this->assertSame('ten_001', $identity->traits->tenantId);
        $this->assertSame(['role' => 'editor'], $identity->traits->customData);
    }

    public function test_get_identity(): void
    {
        $gate = $this->makeGate([
            new Response(200, [], json_encode([
                'id' => 'identity_123',
                'schema_id' => 'user',
                'state' => 'active',
                'traits' => [
                    'email' => 'user@example.com',
                    'tenant_id' => 'ten_001',
                ],
            ])),
        ]);

        $identity = $gate->identities()->get('identity_123');

        $this->assertSame('identity_123', $identity->id);
    }

    public function test_patch_identity_returns_updated(): void
    {
        $gate = $this->makeGate([
            new Response(200, [], json_encode([
                'id' => 'identity_123',
                'schema_id' => 'user',
                'state' => 'active',
                'traits' => [
                    'email' => 'user@example.com',
                    'tenant_id' => 'ten_001',
                    'custom_data' => ['role' => 'admin'],
                ],
            ])),
        ]);

        $identity = $gate->identities()->patch('identity_123', [
            ['op' => 'replace', 'path' => '/traits/custom_data/role', 'value' => 'admin'],
        ]);

        $this->assertSame(['role' => 'admin'], $identity->traits->customData);
    }

    public function test_delete_identity_returns_void(): void
    {
        $gate = $this->makeGate([
            new Response(204),
        ]);

        // Should not throw
        $gate->identities()->delete('identity_123');
        $this->assertTrue(true);
    }

    public function test_identities_throw_verne_api_exception_on_error(): void
    {
        $gate = $this->makeGate([
            new Response(404, [], json_encode([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Identity not found.',
                    'request_id' => 'req_404',
                ],
            ])),
        ]);

        $this->expectException(VerneApiException::class);
        $this->expectExceptionCode(404);

        $gate->identities()->get('nonexistent');
    }

    // --- Tokens ---

    public function test_tokens_create_does_not_send_authorization_header(): void
    {
        $history = [];
        $gate = $this->makeGate([
            new Response(200, [], json_encode([
                'access_token' => 'gat_test_at_abc123',
                'expires_at' => '2026-03-17T12:00:00Z',
                'subject' => 'usr_123',
                'tenant_id' => 'ten_001',
            ])),
        ], $history);

        $gate->tokens()->create(subject: 'usr_123');

        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertFalse($request->hasHeader('Authorization'), 'tokens()->create() must NOT send Authorization header');
    }

    public function test_tokens_create_returns_access_token(): void
    {
        $gate = $this->makeGate([
            new Response(200, [], json_encode([
                'access_token' => 'gat_test_at_abc123',
                'expires_at' => '2026-03-17T12:00:00Z',
                'subject' => 'usr_123',
                'tenant_id' => 'ten_001',
            ])),
        ]);

        $token = $gate->tokens()->create(
            subject: 'usr_123',
            scopes: ['gate.tokens.read'],
            ttlSeconds: 3600,
        );

        $this->assertSame('gat_test_at_abc123', $token->accessToken);
        $this->assertSame('2026-03-17T12:00:00Z', $token->expiresAt);
        $this->assertSame('usr_123', $token->subject);
        $this->assertSame('ten_001', $token->tenantId);
    }

    public function test_tokens_introspect(): void
    {
        $gate = $this->makeGate([
            new Response(200, [], json_encode([
                'active' => true,
                'subject' => 'usr_123',
                'tenant_id' => 'ten_001',
                'scopes' => ['gate.tokens.read'],
                'expires_at' => '2026-03-17T12:00:00Z',
            ])),
        ]);

        $result = $gate->tokens()->introspect('gat_test_at_abc123');

        $this->assertTrue($result->active);
        $this->assertSame('usr_123', $result->subject);
        $this->assertSame('ten_001', $result->tenantId);
        $this->assertSame(['gate.tokens.read'], $result->scopes);
        $this->assertSame('2026-03-17T12:00:00Z', $result->expiresAt);
    }

    // --- Authorize ---

    public function test_authorize_returns_result(): void
    {
        $gate = $this->makeGate([
            new Response(200, [], json_encode([
                'allowed' => true,
                'decision_id' => 'dec_9f8a7c',
                'reason' => 'subject has role=admin on tenant:ten_001',
            ])),
        ]);

        $result = $gate->authorize(
            subject: 'usr_123',
            action: 'relay.messages.read',
            resource: 'tenant:ten_001',
        );

        $this->assertTrue($result->allowed);
        $this->assertSame('dec_9f8a7c', $result->decisionId);
        $this->assertSame('subject has role=admin on tenant:ten_001', $result->reason);
    }

    public function test_authorize_denied(): void
    {
        $gate = $this->makeGate([
            new Response(200, [], json_encode([
                'allowed' => false,
                'decision_id' => 'dec_denied',
                'reason' => null,
            ])),
        ]);

        $result = $gate->authorize(
            subject: 'usr_456',
            action: 'relay.messages.write',
            resource: 'tenant:ten_001',
            context: ['ip' => '1.2.3.4'],
        );

        $this->assertFalse($result->allowed);
        $this->assertNull($result->reason);
    }
}
