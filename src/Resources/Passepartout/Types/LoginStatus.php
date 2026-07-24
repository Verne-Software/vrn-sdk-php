<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Passepartout\Types;

final readonly class LoginStatus
{
    public function __construct(
        public string $status,
        public ?string $accessToken = null,
        public ?string $expiresAt = null,
        public ?string $identityId = null,
        public ?TelegramUser $user = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            status: $data['status'],
            accessToken: $data['access_token'] ?? null,
            expiresAt: $data['expires_at'] ?? null,
            identityId: $data['identity_id'] ?? null,
            user: isset($data['user']) ? TelegramUser::fromArray($data['user']) : null,
        );
    }
}
