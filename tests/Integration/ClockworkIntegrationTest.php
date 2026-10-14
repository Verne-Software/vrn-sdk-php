<?php

declare(strict_types=1);

namespace Vernesoft\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Vernesoft\Clockwork;
use Vernesoft\Core\Errors\VerneApiException;

/**
 * Clockwork 1.4.0 against a real stack; see GateIntegrationTest for the environment.
 */
class ClockworkIntegrationTest extends TestCase
{
    private ?Clockwork $clockwork = null;

    protected function setUp(): void
    {
        $key = IntegrationEnv::key();
        if ($key === null) {
            $this->markTestSkipped('VERNE_API_KEY is not set; the integration suite is opt-in.');
        }
        $this->clockwork = new Clockwork(apiKey: $key, baseUrl: IntegrationEnv::baseUrl());
    }

    public function test_usage_reports_the_plan_and_its_limits(): void
    {
        $usage = $this->clockwork->usage();

        $this->assertNotSame('', $usage->plan);
        $this->assertIsInt($usage->cron->active);
        $this->assertIsInt($usage->delayed->pending);
    }

    public function test_an_engine_error_is_the_standard_envelope(): void
    {
        try {
            $this->clockwork->jobs()->create(name: 'sdk-php-bad', schedule: 'not a schedule', url: 'https://example.com');
            $this->fail('expected a 400');
        } catch (VerneApiException $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertSame('invalid_payload', $e->getErrorCode());
            $this->assertMatchesRegularExpression('/cron/i', $e->getMessage());
            $this->assertStringStartsWith('req_', (string) $e->getRequestId());
        }
    }
}
