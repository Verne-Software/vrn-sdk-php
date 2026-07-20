<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

final readonly class SecuritySettings
{
    public function __construct(
        public bool $passwordlessEnabled,
        public bool $mfaEnabled,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            passwordlessEnabled: (bool) ($data['passwordless_enabled'] ?? false),
            mfaEnabled: (bool) ($data['mfa_enabled'] ?? false),
        );
    }
}