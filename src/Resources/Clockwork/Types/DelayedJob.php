<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Clockwork\Types;

final readonly class DelayedJob
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $name,
        public string $runAt,
        public string $url,
        public string $method,
        public ?array $headers,
        public ?string $body,
        public string $status,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            tenantId: $data['tenant_id'],
            name: $data['name'],
            runAt: $data['run_at'],
            url: $data['url'],
            method: $data['method'],
            headers: $data['headers'] ?? null,
            body: $data['body'] ?? null,
            status: $data['status'],
            createdAt: $data['created_at'],
            updatedAt: $data['updated_at'],
        );
    }
}
