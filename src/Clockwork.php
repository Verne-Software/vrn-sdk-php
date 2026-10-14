<?php

declare(strict_types=1);

namespace Vernesoft;

use GuzzleHttp\ClientInterface;
use Vernesoft\Core\HttpClient;
use Vernesoft\Resources\Clockwork\CronJobsResource;
use Vernesoft\Resources\Clockwork\DelayedJobsResource;
use Vernesoft\Resources\Clockwork\Types\ClockworkUsage;

class Clockwork
{
    private HttpClient $httpClient;

    private ?CronJobsResource $jobsResource = null;

    private ?DelayedJobsResource $delayedResource = null;

    public function __construct(
        string $apiKey,
        string $baseUrl = HttpClient::DEFAULT_BASE_URL,
        int $timeoutSeconds = 30,
        ?ClientInterface $httpClient = null,
    ) {
        $this->httpClient = new HttpClient(
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            timeoutSeconds: $timeoutSeconds,
            httpClient: $httpClient,
        );
    }

    public function jobs(): CronJobsResource
    {
        return $this->jobsResource ??= new CronJobsResource($this->httpClient);
    }

    public function delayed(): DelayedJobsResource
    {
        return $this->delayedResource ??= new DelayedJobsResource($this->httpClient);
    }

    /**
     * How many jobs exist against the plan's limits. Creating a job over a limit, or
     * resuming a paused one, is refused with `402` (`UPGRADE_REQUIRED` or
     * `JOB_LIMIT_REACHED`) — never retried.
     */
    public function usage(): ClockworkUsage
    {
        $data = $this->httpClient->get('/v1/clockwork/usage');

        return ClockworkUsage::fromArray($data ?? []);
    }
}
