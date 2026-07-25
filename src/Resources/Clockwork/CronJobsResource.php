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

    public function create(
        string $name,
        string $schedule,
        string $url,
        ?string $method = null,
        ?array $headers = null,
        ?string $body = null,
    ): CronJob {
        $payload = [
            'name' => $name,
            'schedule' => $schedule,
            'url' => $url,
        ];

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
     * @param  array<string, mixed>  $fields  Snake_case fields to update (e.g. `is_active`, `schedule`).
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
