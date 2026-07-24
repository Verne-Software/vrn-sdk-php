<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Passepartout\Types;

final readonly class TelegramUser
{
    public function __construct(
        public string $id,
        public ?string $username = null,
        public ?string $firstName = null,
        public ?string $photoUrl = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            username: $data['username'] ?? null,
            firstName: $data['first_name'] ?? null,
            photoUrl: $data['photo_url'] ?? null,
        );
    }
}
