<?php

declare(strict_types=1);

namespace Vernesoft\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Vernesoft\Core\Errors\VerneException;
use Vernesoft\Core\Errors\VerneTokenException;
use Vernesoft\Gate;
use Vernesoft\GateTokenVerifier;

/**
 * ES256 verification against keys generated here — nothing leaves the process.
 */
class GateTokenVerifierTest extends TestCase
{
    private const TENANT = '550e8400-e29b-41d4-a716-446655440000';

    private const ISSUER = 'https://api.vernesoft.com/auth/'.self::TENANT;

    private const JWKS_URL = self::ISSUER.'/.well-known/jwks.json';

    /** @var array<string, array{private: \OpenSSLAsymmetricKey, jwk: array<string, string>}> */
    private static array $keys = [];

    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    public static function setUpBeforeClass(): void
    {
        foreach (['k1', 'k2', 'k3'] as $kid) {
            self::$keys[$kid] = self::keyPair($kid);
        }
    }

    // --- helpers ---

    /**
     * @return array{private: \OpenSSLAsymmetricKey, jwk: array<string, string>}
     */
    private static function keyPair(string $kid): array
    {
        $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $ec = openssl_pkey_get_details($private)['ec'];

        return [
            'private' => $private,
            'jwk' => [
                'kty' => 'EC',
                'crv' => 'P-256',
                'x' => self::b64url(str_pad($ec['x'], 32, "\x00", STR_PAD_LEFT)),
                'y' => self::b64url(str_pad($ec['y'], 32, "\x00", STR_PAD_LEFT)),
                'kid' => $kid,
                'alg' => 'ES256',
                'use' => 'sig',
            ],
        ];
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Signs like Gate does: ES256 with the JWS raw `r‖s` signature.
     *
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>|null  $header
     */
    private static function sign(string $kid, array $claims, ?array $header = null): string
    {
        $header ??= ['alg' => 'ES256', 'typ' => 'JWT', 'kid' => $kid];
        $input = self::b64url(json_encode($header)).'.'.self::b64url(json_encode($claims));
        openssl_sign($input, $der, self::$keys[$kid]['private'], OPENSSL_ALGO_SHA256);

        return $input.'.'.self::b64url(self::derToRaw($der));
    }

    private static function derToRaw(string $der): string
    {
        $offset = 2; // SEQUENCE tag + length (short form for P-256)
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$offset + 1]);
            $int = substr($der, $offset + 2, $len);
            $parts[] = str_pad(ltrim($int, "\x00"), 32, "\x00", STR_PAD_LEFT);
            $offset += 2 + $len;
        }

        return $parts[0].$parts[1];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function claims(array $overrides = [], ?int $now = null): array
    {
        $now ??= time();

        return array_merge([
            'iss' => self::ISSUER,
            'sub' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
            'sid' => '8e4f0b2a-3c1d-4e5f-9a6b-7c8d9e0f1a2b',
            'tenant_id' => self::TENANT,
            'schema_id' => 'user',
            'email' => 'ada@example.com',
            'role' => 'authenticated',
            'orgs' => [['id' => 'o1', 'role' => 'admin', 'permissions' => ['invoices:write']]],
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 900,
            'jti' => 'j1',
        ], $overrides);
    }

    private static function jwks(string ...$kids): Response
    {
        return new Response(200, ['Content-Type' => 'application/json', 'Cache-Control' => 'public, max-age=300'], json_encode([
            'keys' => array_map(static fn (string $kid): array => self::$keys[$kid]['jwk'], $kids),
        ]));
    }

    /**
     * @param  Response[]  $responses
     */
    private function verifier(array $responses, ?callable $clock = null, string $tenant = self::TENANT): GateTokenVerifier
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new GateTokenVerifier(tenantId: $tenant, httpClient: new Client(['handler' => $stack]), clock: $clock);
    }

    private function reasonOf(GateTokenVerifier $verifier, string $token): string
    {
        try {
            $verifier->verify($token);
        } catch (VerneTokenException $e) {
            $this->assertInstanceOf(VerneException::class, $e);

            return $e->reason;
        }
        $this->fail('Expected a VerneTokenException.');
    }

    // --- tests ---

    public function test_accepts_a_token_signed_by_the_tenant_key_and_caches_the_key_set(): void
    {
        $verifier = $this->verifier([self::jwks('k1')]);

        $claims = $verifier->verify(self::sign('k1', self::claims()));
        $verifier->verify(self::sign('k1', self::claims(['jti' => 'j2'])));

        $this->assertSame('f47ac10b-58cc-4372-a567-0e02b2c3d479', $claims->sub);
        $this->assertSame(self::ISSUER, $claims->iss);
        $this->assertSame(self::TENANT, $claims->tenantId);
        $this->assertSame('authenticated', $claims->role);
        $this->assertSame('admin', $claims->orgs[0]->role);
        $this->assertSame(['invoices:write'], $claims->orgs[0]->permissions);
        $this->assertFalse($claims->orgsTruncated);
        $this->assertCount(1, $this->history);
        $this->assertSame(self::JWKS_URL, (string) $this->history[0]['request']->getUri());
        $this->assertFalse($this->history[0]['request']->hasHeader('Authorization'));
    }

    /**
     * @return array<string, array{0: callable(): string, 1: string}>
     */
    public static function refusals(): array
    {
        return [
            'another tenant\'s issuer' => [fn () => self::sign('k1', self::claims(['iss' => 'https://api.vernesoft.com/auth/other'])), 'wrong_issuer'],
            'an expired token' => [fn () => self::sign('k1', self::claims(['exp' => time() - 60])), 'expired'],
            'a token not valid yet' => [fn () => self::sign('k1', self::claims(['nbf' => time() + 600])), 'not_yet_valid'],
            'a token issued in the future' => [fn () => self::sign('k1', self::claims(['iat' => time() + 600])), 'not_yet_valid'],
            'a token without sub' => [fn () => self::sign('k1', self::claims(['sub' => ''])), 'missing_claim'],
            'a token without exp' => [fn () => self::sign('k1', array_diff_key(self::claims(), ['exp' => 0])), 'missing_claim'],
            'alg none' => [fn () => self::b64url(json_encode(['alg' => 'none', 'kid' => 'k1'])).'.'.self::b64url(json_encode(self::claims())).'.', 'unsupported_alg'],
            'alg HS256' => [fn () => self::sign('k1', self::claims(), ['alg' => 'HS256', 'kid' => 'k1']), 'unsupported_alg'],
            'no kid' => [fn () => self::sign('k1', self::claims(), ['alg' => 'ES256']), 'malformed'],
            'a signature by another key under our kid' => [fn () => self::sign('k2', self::claims(), ['alg' => 'ES256', 'kid' => 'k1']), 'bad_signature'],
            'a tampered payload' => [function () {
                [$h, , $s] = explode('.', self::sign('k1', self::claims()));

                return $h.'.'.self::b64url(json_encode(self::claims(['sub' => 'someone-else']))).'.'.$s;
            }, 'bad_signature'],
            'a DER signature instead of r||s' => [function () {
                $input = self::b64url(json_encode(['alg' => 'ES256', 'kid' => 'k1'])).'.'.self::b64url(json_encode(self::claims()));
                openssl_sign($input, $der, self::$keys['k1']['private'], OPENSSL_ALGO_SHA256);

                return $input.'.'.self::b64url($der);
            }, 'bad_signature'],
            'garbage' => [fn () => 'not-a-jwt', 'malformed'],
            'a header that is not JSON' => [fn () => 'bm90IGpzb24.e30.AAAA', 'malformed'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_refuses(callable $make, string $reason): void
    {
        $verifier = $this->verifier([self::jwks('k1')]);

        $this->assertSame($reason, $this->reasonOf($verifier, $make()));
    }

    public function test_tolerates_a_little_clock_skew(): void
    {
        $verifier = $this->verifier([self::jwks('k1')]);

        $claims = $verifier->verify(self::sign('k1', self::claims(['exp' => time() - 5])));

        $this->assertSame('j1', $claims->jti);
    }

    public function test_refetches_once_for_an_unknown_kid_and_not_again_within_30_seconds(): void
    {
        $now = 1_800_000_000;
        $clock = function () use (&$now): int {
            return $now;
        };
        $verifier = $this->verifier([self::jwks('k1'), self::jwks('k1', 'k2'), self::jwks('k1', 'k2')], $clock);

        $verifier->verify(self::sign('k1', self::claims(now: $now)));
        $this->assertCount(1, $this->history);

        // A new key starts signing; it has been published meanwhile.
        $now += 31;
        $verifier->verify(self::sign('k2', self::claims(now: $now)));
        $this->assertCount(2, $this->history);

        // A kid nobody publishes: refused, and not refetched again within 30 seconds.
        $this->assertSame('unknown_kid', $this->reasonOf($verifier, self::sign('k3', self::claims(now: $now))));
        $this->assertCount(2, $this->history);
    }

    public function test_an_unknown_kid_right_after_a_fetch_is_refused_without_a_second_fetch(): void
    {
        $verifier = $this->verifier([self::jwks('k1'), self::jwks('k1')]);

        $this->assertSame('unknown_kid', $this->reasonOf($verifier, self::sign('k2', self::claims())));
        $this->assertCount(1, $this->history);
    }

    public function test_refetches_after_max_age(): void
    {
        $now = 1_800_000_000;
        $clock = function () use (&$now): int {
            return $now;
        };
        $verifier = $this->verifier([self::jwks('k1'), self::jwks('k1')], $clock);

        $verifier->verify(self::sign('k1', self::claims(now: $now)));
        $now += 301;
        $verifier->verify(self::sign('k1', self::claims(now: $now)));

        $this->assertCount(2, $this->history);
    }

    public function test_an_unreachable_key_set_is_jwks_unavailable(): void
    {
        $verifier = $this->verifier([new Response(503, [], 'nope')]);

        $this->assertSame('jwks_unavailable', $this->reasonOf($verifier, self::sign('k1', self::claims())));
    }

    public function test_a_key_set_without_keys_is_jwks_unavailable(): void
    {
        $verifier = $this->verifier([new Response(200, [], '{"nope":1}')]);

        $this->assertSame('jwks_unavailable', $this->reasonOf($verifier, self::sign('k1', self::claims())));
    }

    public function test_an_empty_tenant_id_is_a_configuration_error(): void
    {
        $this->expectException(VerneException::class);

        new GateTokenVerifier(tenantId: '');
    }

    public function test_gate_verify_signed_builds_the_issuer_from_the_client_base_url(): void
    {
        $localIssuer = 'http://api.soft.com/auth/'.self::TENANT;
        $history = [];
        $stack = HandlerStack::create(new MockHandler([self::jwks('k1')]));
        $stack->push(Middleware::history($history));
        $gate = new Gate(apiKey: 'vrn_x', baseUrl: 'http://api.soft.com/', httpClient: new Client(['handler' => $stack]));

        $claims = $gate->tokens()->verifySigned(self::sign('k1', self::claims(['iss' => $localIssuer])), tenantId: self::TENANT);

        $this->assertSame($localIssuer, $claims->iss);
        $this->assertSame($localIssuer.'/.well-known/jwks.json', (string) $history[0]['request']->getUri());
        $this->assertFalse($history[0]['request']->hasHeader('Authorization'));

        // The verifier (and its cached key set) is reused.
        $gate->tokens()->verifySigned(self::sign('k1', self::claims(['iss' => $localIssuer, 'jti' => 'j2'])), tenantId: self::TENANT);
        $this->assertCount(1, $history);
    }
}
