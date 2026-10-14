<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

/** Something a rule scheduled or did to one end user. */
final readonly class LifecycleAction
{
    public function __construct(
        public string $id,
        public string $kind,
        /** `remind`, `warn`, `delete` or `revoke`. */
        public string $step,
        public string $identityId,
        /** `pending`, `done`, `cancelled` or `failed`. */
        public string $status,
        public string $basisAt,
        public string $dueAt,
        /** Why it was cancelled or failed (`rule_disabled`, `verified`, `signed_in`, …); open set. */
        public ?string $reason,
        public string $createdAt,
        public ?string $finishedAt,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            kind: (string) ($data['kind'] ?? ''),
            step: (string) ($data['step'] ?? ''),
            identityId: (string) ($data['identity_id'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            basisAt: (string) ($data['basis_at'] ?? ''),
            dueAt: (string) ($data['due_at'] ?? ''),
            reason: $data['reason'] ?? null,
            createdAt: (string) ($data['created_at'] ?? ''),
            finishedAt: $data['finished_at'] ?? null,
        );
    }
}
