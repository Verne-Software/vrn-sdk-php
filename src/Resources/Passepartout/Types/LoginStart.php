<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Passepartout\Types;

final readonly class LoginStart
{
    public function __construct(
        public string $nonce,
        public string $deepLink,
        public string $expiresAt,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            nonce: $data['nonce'],
            deepLink: $data['deep_link'],
            expiresAt: $data['expires_at'],
        );
    }
}
