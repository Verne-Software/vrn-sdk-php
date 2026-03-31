<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

final readonly class AccessToken
{
    public function __construct(
        public string $accessToken,
        public string $expiresAt,
        public string $subject,
        public string $tenantId,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            accessToken: $data['access_token'],
            expiresAt: $data['expires_at'],
            subject: $data['subject'],
            tenantId: $data['tenant_id'],
        );
    }
}
