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
         * A code to branch on: one of the `AuthorizeReason` constants, for an organization
         * resource and for any other.
         */
        public ?string $reason,
        /**
         * The role the user holds in the organization — present for
         * `member_with_permission`, `member_without_permission` and `subject_inactive`.
         */
        public ?string $role = null,
        /**
         * For a resource that is not an organization: the decision as a sentence, for your
         * logs. Its wording can change; only `reason` is a contract. `null` for an
         * organization decision.
         */
        public ?string $message = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            allowed: (bool) $data['allowed'],
            decisionId: $data['decision_id'],
            reason: $data['reason'] ?? null,
            role: $data['role'] ?? null,
            message: $data['message'] ?? null,
        );
    }
}
