<?php

declare(strict_types=1);

namespace Vernesoft;

use GuzzleHttp\ClientInterface;
use Vernesoft\Core\HttpClient;
use Vernesoft\Resources\Clockwork\CronJobsResource;
use Vernesoft\Resources\Clockwork\DelayedJobsResource;

class Clockwork
{
    private HttpClient $httpClient;

    private ?CronJobsResource $jobsResource = null;

    private ?DelayedJobsResource $delayedResource = null;

    public function __construct(
        string $apiKey,
        string $baseUrl = 'https://api.vernesoft.com',
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
}
