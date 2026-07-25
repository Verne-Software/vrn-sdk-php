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

class ClockworkTest extends TestCase
{
    private function makeClockwork(array $responses, array &$history = []): Clockwork
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $guzzle = new Client(['handler' => $stack]);

        return new Clockwork(apiKey: 'vrn_clockwork_test_sk_abc', httpClient: $guzzle);
    }

    private function cronJobPayload(array $overrides = []): array
    {
        return array_merge([
            'id' => 'job_123',
            'tenant_id' => 'ten_001',
            'name' => 'nightly-report',
            'schedule' => '0 2 * * *',
            'url' => 'https://example.com/hook',
            'method' => 'POST',
            'headers' => ['X-Token' => 'abc'],
            'body' => '{"foo":"bar"}',
            'is_active' => true,
            'last_run_at' => null,
            'next_run_at' => '2026-07-26T02:00:00Z',
            'created_at' => '2026-07-25T10:00:00Z',
            'updated_at' => '2026-07-25T10:00:00Z',
        ], $overrides);
    }

    private function delayedJobPayload(array $overrides = []): array
    {
        return array_merge([
            'id' => 'delayed_123',
            'tenant_id' => 'ten_001',
            'name' => 'send-reminder',
            'run_at' => '2026-08-01T09:00:00Z',
            'url' => 'https://example.com/hook',
            'method' => 'POST',
            'headers' => null,
            'body' => null,
            'status' => 'scheduled',
            'created_at' => '2026-07-25T10:00:00Z',
            'updated_at' => '2026-07-25T10:00:00Z',
        ], $overrides);
    }

    private function executionPayload(array $overrides = []): array
    {
        return array_merge([
            'id' => 'exec_123',
            'job_id' => 'job_123',
            'status' => 'success',
            'started_at' => '2026-07-25T02:00:00Z',
            'completed_at' => '2026-07-25T02:00:01Z',
            'duration_ms' => 1200,
            'response_status' => 200,
            'response_body' => 'OK',
            'error_message' => null,
        ], $overrides);
    }

    // --- Cron Jobs ---

    public function test_jobs_list_returns_bare_array_of_cron_jobs(): void
    {
        $history = [];
        $clockwork = $this->makeClockwork([
            new Response(200, [], json_encode([
                $this->cronJobPayload(),
                $this->cronJobPayload(['id' => 'job_456', 'name' => 'hourly-sync']),
            ])),
        ], $history);

        $jobs = $clockwork->jobs()->list();

        $this->assertCount(2, $jobs);
        $this->assertSame('job_123', $jobs[0]->id);
        $this->assertSame('ten_001', $jobs[0]->tenantId);
        $this->assertSame('nightly-report', $jobs[0]->name);
        $this->assertSame('0 2 * * *', $jobs[0]->schedule);
        $this->assertSame('POST', $jobs[0]->method);
        $this->assertSame(['X-Token' => 'abc'], $jobs[0]->headers);
        $this->assertTrue($jobs[0]->isActive);
        $this->assertNull($jobs[0]->lastRunAt);
        $this->assertSame('2026-07-26T02:00:00Z', $jobs[0]->nextRunAt);
        $this->assertSame('hourly-sync', $jobs[1]->name);

        $request = $history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/v1/clockwork/jobs', $request->getUri()->getPath());
    }

    public function test_jobs_create_sends_snake_case_body_and_returns_cron_job(): void
    {
        $history = [];
        $clockwork = $this->makeClockwork([
            new Response(201, [], json_encode($this->cronJobPayload())),
        ], $history);

        $job = $clockwork->jobs()->create(
            name: 'nightly-report',
            schedule: '0 2 * * *',
            url: 'https://example.com/hook',
            method: 'POST',
            headers: ['X-Token' => 'abc'],
            body: '{"foo":"bar"}',
        );

        $this->assertSame('job_123', $job->id);
        $this->assertSame('{"foo":"bar"}', $job->body);

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/v1/clockwork/jobs', $request->getUri()->getPath());
        $this->assertSame(
            [
                'name' => 'nightly-report',
                'schedule' => '0 2 * * *',
                'url' => 'https://example.com/hook',
                'method' => 'POST',
                'headers' => ['X-Token' => 'abc'],
                'body' => '{"foo":"bar"}',
            ],
            json_decode((string) $request->getBody(), true),
        );
    }

    public function test_jobs_create_omits_optional_fields_when_null(): void
    {
        $history = [];
        $clockwork = $this->makeClockwork([
            new Response(201, [], json_encode($this->cronJobPayload())),
        ], $history);

        $clockwork->jobs()->create(
            name: 'nightly-report',
            schedule: '0 2 * * *',
            url: 'https://example.com/hook',
        );

        $this->assertSame(
            [
                'name' => 'nightly-report',
                'schedule' => '0 2 * * *',
                'url' => 'https://example.com/hook',
            ],
            json_decode((string) $history[0]['request']->getBody(), true),
        );
    }

    public function test_jobs_update_passes_fields_straight_as_patch_body(): void
    {
        $history = [];
        $clockwork = $this->makeClockwork([
            new Response(200, [], json_encode($this->cronJobPayload(['is_active' => false]))),
        ], $history);

        $job = $clockwork->jobs()->update('job_123', ['is_active' => false, 'schedule' => '0 3 * * *']);

        $this->assertFalse($job->isActive);

        $request = $history[0]['request'];
        $this->assertSame('PATCH', $request->getMethod());
        $this->assertSame('/v1/clockwork/jobs/job_123', $request->getUri()->getPath());
        $this->assertSame(
            ['is_active' => false, 'schedule' => '0 3 * * *'],
            json_decode((string) $request->getBody(), true),
        );
    }

    public function test_jobs_delete_returns_void(): void
    {
        $history = [];
        $clockwork = $this->makeClockwork([
            new Response(204),
        ], $history);

        $clockwork->jobs()->delete('job_123');

        $request = $history[0]['request'];
        $this->assertSame('DELETE', $request->getMethod());
        $this->assertSame('/v1/clockwork/jobs/job_123', $request->getUri()->getPath());
    }

    public function test_jobs_executions_returns_bare_array_of_executions(): void
    {
        $history = [];
        $clockwork = $this->makeClockwork([
            new Response(200, [], json_encode([
                $this->executionPayload(),
                $this->executionPayload(['id' => 'exec_456', 'status' => 'failed', 'completed_at' => null, 'duration_ms' => null, 'response_status' => null, 'response_body' => null, 'error_message' => 'timeout']),
            ])),
        ], $history);

        $executions = $clockwork->jobs()->executions('job_123');

        $this->assertCount(2, $executions);
        $this->assertSame('exec_123', $executions[0]->id);
        $this->assertSame('job_123', $executions[0]->jobId);
        $this->assertSame('success', $executions[0]->status);
        $this->assertSame(1200, $executions[0]->durationMs);
        $this->assertSame(200, $executions[0]->responseStatus);
        $this->assertNull($executions[1]->completedAt);
        $this->assertNull($executions[1]->durationMs);
        $this->assertSame('timeout', $executions[1]->errorMessage);

        $request = $history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/v1/clockwork/jobs/job_123/executions', $request->getUri()->getPath());
    }

    // --- Delayed Jobs ---

    public function test_delayed_list_returns_bare_array_of_delayed_jobs(): void
    {
        $history = [];
        $clockwork = $this->makeClockwork([
            new Response(200, [], json_encode([
                $this->delayedJobPayload(),
            ])),
        ], $history);

        $jobs = $clockwork->delayed()->list();

        $this->assertCount(1, $jobs);
        $this->assertSame('delayed_123', $jobs[0]->id);
        $this->assertSame('ten_001', $jobs[0]->tenantId);
        $this->assertSame('send-reminder', $jobs[0]->name);
        $this->assertSame('2026-08-01T09:00:00Z', $jobs[0]->runAt);
        $this->assertSame('scheduled', $jobs[0]->status);
        $this->assertNull($jobs[0]->headers);

        $request = $history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/v1/clockwork/delayed', $request->getUri()->getPath());
    }

    public function test_delayed_create_sends_run_at_and_returns_delayed_job(): void
    {
        $history = [];
        $clockwork = $this->makeClockwork([
            new Response(201, [], json_encode($this->delayedJobPayload())),
        ], $history);

        $job = $clockwork->delayed()->create(
            name: 'send-reminder',
            runAt: '2026-08-01T09:00:00Z',
            url: 'https://example.com/hook',
            method: 'POST',
        );

        $this->assertSame('delayed_123', $job->id);
        $this->assertSame('scheduled', $job->status);

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/v1/clockwork/delayed', $request->getUri()->getPath());
        $this->assertSame(
            [
                'name' => 'send-reminder',
                'run_at' => '2026-08-01T09:00:00Z',
                'url' => 'https://example.com/hook',
                'method' => 'POST',
            ],
            json_decode((string) $request->getBody(), true),
        );
    }

    public function test_delayed_cancel_returns_void(): void
    {
        $history = [];
        $clockwork = $this->makeClockwork([
            new Response(204),
        ], $history);

        $clockwork->delayed()->cancel('delayed_123');

        $request = $history[0]['request'];
        $this->assertSame('DELETE', $request->getMethod());
        $this->assertSame('/v1/clockwork/delayed/delayed_123', $request->getUri()->getPath());
    }

    public function test_delayed_executions_returns_bare_array_of_executions(): void
    {
        $history = [];
        $clockwork = $this->makeClockwork([
            new Response(200, [], json_encode([
                $this->executionPayload(['job_id' => 'delayed_123']),
            ])),
        ], $history);

        $executions = $clockwork->delayed()->executions('delayed_123');

        $this->assertCount(1, $executions);
        $this->assertSame('delayed_123', $executions[0]->jobId);

        $request = $history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/v1/clockwork/delayed/delayed_123/executions', $request->getUri()->getPath());
    }

    // --- Error handling ---

    public function test_throws_verne_api_exception_on_error(): void
    {
        $clockwork = $this->makeClockwork([
            new Response(404, [], json_encode([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Cron job not found.',
                    'request_id' => 'req_404',
                ],
            ])),
        ]);

        $this->expectException(VerneApiException::class);
        $this->expectExceptionCode(404);

        $clockwork->jobs()->update('nonexistent', ['is_active' => false]);
    }
}
