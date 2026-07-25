<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Clockwork\Types;

final readonly class CronJob
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $name,
        public string $schedule,
        public string $url,
        public string $method,
        public ?array $headers,
        public ?string $body,
        public bool $isActive,
        public ?string $lastRunAt,
        public ?string $nextRunAt,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            tenantId: $data['tenant_id'],
            name: $data['name'],
            schedule: $data['schedule'],
            url: $data['url'],
            method: $data['method'],
            headers: $data['headers'] ?? null,
            body: $data['body'] ?? null,
            isActive: $data['is_active'],
            lastRunAt: $data['last_run_at'] ?? null,
            nextRunAt: $data['next_run_at'] ?? null,
            createdAt: $data['created_at'],
            updatedAt: $data['updated_at'],
        );
    }
}
