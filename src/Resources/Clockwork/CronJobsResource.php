<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Clockwork;

use Vernesoft\Core\HttpClient;
use Vernesoft\Resources\Clockwork\Types\CronJob;
use Vernesoft\Resources\Clockwork\Types\Execution;

class CronJobsResource
{
    public function __construct(private HttpClient $httpClient) {}

    /**
     * @return CronJob[]
     */
    public function list(): array
    {
        $data = $this->httpClient->get('/v1/clockwork/jobs');

        return array_map(fn ($job) => CronJob::fromArray($job), $data ?? []);
    }

    /**
     * Creates a recurring job.
     *
     * @param  string|null  $timezone  IANA time zone to read `$schedule` in, e.g. `Europe/Paris`:
     *                                 `0 9 * * *` is then 09:00 in Paris all year round, across
     *                                 daylight-saving changes. `UTC` when null. An unknown name (or an
     *                                 offset like `+02:00`) is `400 invalid_payload`.
     */
    public function create(
        string $name,
        string $schedule,
        string $url,
        ?string $method = null,
        ?array $headers = null,
        ?string $body = null,
        ?string $timezone = null,
    ): CronJob {
        $payload = [
            'name' => $name,
            'schedule' => $schedule,
            'url' => $url,
        ];

        if ($timezone !== null) {
            $payload['timezone'] = $timezone;
        }

        if ($method !== null) {
            $payload['method'] = $method;
        }

        if ($headers !== null) {
            $payload['headers'] = $headers;
        }

        if ($body !== null) {
            $payload['body'] = $body;
        }

        $data = $this->httpClient->post('/v1/clockwork/jobs', $payload);

        return CronJob::fromArray($data);
    }

    /**
     * Partially updates a job: only the fields given are changed. `$fields` are snake_case,
     * e.g. `['timezone' => 'Europe/Paris']`; a new `timezone` or `schedule` recomputes
     * `next_run_at` at once.
     *
     * @param  array{name?: string, schedule?: string, timezone?: string, url?: string, method?: string, headers?: array<string, string>, body?: string, is_active?: bool}  $fields
     */
    public function update(string $jobId, array $fields): CronJob
    {
        $data = $this->httpClient->patch('/v1/clockwork/jobs/'.$jobId, $fields);

        return CronJob::fromArray($data);
    }

    public function delete(string $jobId): void
    {
        $this->httpClient->delete('/v1/clockwork/jobs/'.$jobId);
    }

    /**
     * @return Execution[]
     */
    public function executions(string $jobId): array
    {
        $data = $this->httpClient->get('/v1/clockwork/jobs/'.$jobId.'/executions');

        return array_map(fn ($execution) => Execution::fromArray($execution), $data ?? []);
    }
}
