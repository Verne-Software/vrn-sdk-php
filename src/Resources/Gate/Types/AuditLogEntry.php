<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

/** One entry of the audit log. */
final readonly class AuditLogEntry
{
    /**
     * @param  array<string, mixed>  $details  free-form facts, never personal data
     */
    public function __construct(
        public string $id,
        public string $occurredAt,
        /** Open set: new actions are added over time. */
        public string $action,
        public AuditLogActor $actor,
        public ?AuditLogTarget $target,
        public ?string $projectId,
        public array $details,
        public ?string $ip,
        public ?string $userAgent,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            occurredAt: $data['occurred_at'] ?? '',
            action: $data['action'] ?? '',
            actor: AuditLogActor::fromArray($data['actor'] ?? []),
            target: isset($data['target']) && is_array($data['target']) ? AuditLogTarget::fromArray($data['target']) : null,
            projectId: $data['project_id'] ?? null,
            details: is_array($data['details'] ?? null) ? $data['details'] : [],
            ip: $data['ip'] ?? null,
            userAgent: $data['user_agent'] ?? null,
        );
    }
}
