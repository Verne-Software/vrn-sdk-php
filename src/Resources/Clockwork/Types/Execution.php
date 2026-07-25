<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Clockwork\Types;

final readonly class Execution
{
    public function __construct(
        public string $id,
        public string $jobId,
        public string $status,
        public string $startedAt,
        public ?string $completedAt,
        public ?int $durationMs,
        public ?int $responseStatus,
        public ?string $responseBody,
        public ?string $errorMessage,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            jobId: $data['job_id'],
            status: $data['status'],
            startedAt: $data['started_at'],
            completedAt: $data['completed_at'] ?? null,
            durationMs: $data['duration_ms'] ?? null,
            responseStatus: $data['response_status'] ?? null,
            responseBody: $data['response_body'] ?? null,
            errorMessage: $data['error_message'] ?? null,
        );
    }
}
