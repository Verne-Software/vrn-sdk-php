<?php

declare(strict_types=1);

namespace Vernesoft\Tests\Integration;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Vernesoft\Core\Errors\VerneApiException;
use Vernesoft\Verne;

/**
 * 1.5.0 against a real stack: Clockwork time zones, and importing a user with a password
 * hash, a confirmed address and metadata. Same environment as GateIntegrationTest; it
 * deletes everything it creates.
 */
class ImportAndTimezoneIntegrationTest extends TestCase
{
    // bcrypt (cost 10) of IMPORTED_PASSWORD — a test value, nothing else.
    private const IMPORTED_PASSWORD = 'Imported-Pw-104!';

    private const IMPORTED_HASH = '$2b$10$Ky/GcB0gC9sEpv84tO7.Wuxp7RsfSWrDYtz7M5iU4akOb3O5Y1Kq2';

    private ?Verne $verne = null;

    /** @var array<callable(): mixed> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        $key = IntegrationEnv::key();
        if ($key === null) {
            $this->markTestSkipped('VERNE_API_KEY is not set; the integration suite is opt-in.');
        }
        $this->verne = new Verne(baseUrl: IntegrationEnv::baseUrl(), apiKey: $key);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $step) {
            try {
                $step();
            } catch (\Throwable) {
                // already gone
            }
        }
        $this->cleanup = [];
    }

    public function test_a_cron_job_keeps_its_time_zone(): void
    {
        $jobs = $this->verne->clockwork()->jobs();
        $job = $jobs->create(
            name: IntegrationEnv::prefix().'-tz',
            schedule: '0 9 * * *',
            url: 'https://example.com/hooks/tz',
            method: 'GET',
            timezone: 'Europe/Paris',
        );
        $this->cleanup[] = fn () => $jobs->delete($job->id);

        $this->assertSame('Europe/Paris', $job->timezone);
        // 09:00 in Paris in October (CEST, UTC+2) is 07:00 UTC.
        $this->assertMatchesRegularExpression('/T07:00:00(\.0+)?(Z|\+00:00)$/', (string) $job->nextRunAt);

        $listed = array_values(array_filter($jobs->list(), fn ($j) => $j->id === $job->id));
        $this->assertSame('Europe/Paris', $listed[0]->timezone);

        $moved = $jobs->update($job->id, ['timezone' => 'America/New_York']);
        $this->assertSame('America/New_York', $moved->timezone);
        // 09:00 in New York in October (EDT, UTC-4) is 13:00 UTC.
        $this->assertMatchesRegularExpression('/T13:00:00(\.0+)?(Z|\+00:00)$/', (string) $moved->nextRunAt);

        $jobs->delete($job->id);
    }

    public function test_a_job_without_a_zone_is_utc_and_an_unknown_zone_is_400(): void
    {
        $jobs = $this->verne->clockwork()->jobs();
        $job = $jobs->create(name: IntegrationEnv::prefix().'-utc', schedule: '0 9 * * *', url: 'https://example.com/hooks/utc');
        $this->cleanup[] = fn () => $jobs->delete($job->id);
        $this->assertSame('UTC', $job->timezone);

        try {
            $jobs->update($job->id, ['timezone' => 'Mars/Olympus']);
            $this->fail('expected a 400');
        } catch (VerneApiException $e) {
            $this->assertSame(400, $e->getStatus());
            $this->assertSame('invalid_payload', $e->getErrorCode());
        }
    }

    public function test_an_imported_user_is_confirmed_keeps_metadata_and_signs_in(): void
    {
        $gate = $this->verne->gate();
        $email = IntegrationEnv::prefix().'-import@e2e.soft.com';
        $identity = $gate->identities()->create(
            schemaId: 'user',
            traits: ['email' => $email],
            credentials: ['password' => ['config' => ['hashed_password' => self::IMPORTED_HASH]]],
            emailVerified: true,
            metadataPublic: ['previous_user_id' => 'auth0|sdk-104'],
        );
        $this->cleanup[] = fn () => $gate->identities()->delete($identity->id);

        $this->assertTrue($identity->verifiableAddresses[0]['verified']);
        $this->assertSame('completed', $identity->verifiableAddresses[0]['status']);
        $this->assertSame(['previous_user_id' => 'auth0|sdk-104'], $identity->metadataPublic);
        $this->assertTrue($gate->identities()->get($identity->id)->verifiableAddresses[0]['verified']);

        $flow = $gate->createLoginFlow();
        $res = (new Client(['http_errors' => false]))->post($flow['ui']['action'], [
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body' => json_encode(['method' => 'password', 'identifier' => $email, 'password' => self::IMPORTED_PASSWORD]),
        ]);
        $this->assertSame(200, $res->getStatusCode(), 'login with the original password');
        $this->assertNotEmpty(json_decode((string) $res->getBody(), true)['session_token'] ?? null);

        $gate->identities()->delete($identity->id);
    }
}
