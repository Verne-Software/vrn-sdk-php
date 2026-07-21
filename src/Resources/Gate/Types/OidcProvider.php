<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

/**
 * A social login (OAuth 2.0 / OIDC) provider and whether it is enabled for the
 * tenant's end-users.
 */
final readonly class OidcProvider
{
    public function __construct(
        public string $provider,
        public bool $enabled,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            provider: (string) ($data['provider'] ?? ''),
            enabled: (bool) ($data['enabled'] ?? false),
        );
    }

    /**
     * @return array{provider: string, enabled: bool}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'enabled' => $this->enabled,
        ];
    }
}