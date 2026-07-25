<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Clockwork;

use Vernesoft\Core\HttpClient;
use Vernesoft\Resources\Clockwork\Types\DelayedJob;
use Vernesoft\Resources\Clockwork\Types\Execution;

class DelayedJobsResource
{
    public function __construct(private HttpClient $httpClient) {}

    /**
     * @return DelayedJob[]
     */
    public function list(): array
    {
        $data = $this->httpClient->get('/v1/clockwork/delayed');

        return array_map(fn ($job) => DelayedJob::fromArray($job), $data ?? []);
    }

    public function create(
        string $name,
        string $runAt,
        string $url,
        ?string $method = null,
        ?array $headers = null,
        ?string $body = null,
    ): DelayedJob {
        $payload = [
            'name' => $name,
            'run_at' => $runAt,
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

        $data = $this->httpClient->post('/v1/clockwork/delayed', $payload);

        return DelayedJob::fromArray($data);
    }

    public function cancel(string $jobId): void
    {
        $this->httpClient->delete('/v1/clockwork/delayed/'.$jobId);
    }

    /**
     * @return Execution[]
     */
    public function executions(string $jobId): array
    {
        $data = $this->httpClient->get('/v1/clockwork/delayed/'.$jobId.'/executions');

        return array_map(fn ($execution) => Execution::fromArray($execution), $data ?? []);
    }
}
