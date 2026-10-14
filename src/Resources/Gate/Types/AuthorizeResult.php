<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

/** An authorization decision. Your service enforces it. */
final readonly class AuthorizeResult
{
    public function __construct(
        public bool $allowed,
        /** Identifies the decision in Gate's logs, for support. */
        public string $decisionId,
        /**
         * For an organization resource, one of the `AuthorizeReason` constants. For any
         * other resource, a sentence for people, not a value to branch on.
         */
        public ?string $reason,
        /**
         * The role the user holds in the organization — present for
         * `member_with_permission`, `member_without_permission` and `subject_inactive`.
         */
        public ?string $role = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            allowed: (bool) $data['allowed'],
            decisionId: $data['decision_id'],
            reason: $data['reason'] ?? null,
            role: $data['role'] ?? null,
        );
    }
}
