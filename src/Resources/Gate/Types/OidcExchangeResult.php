<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

/** What `exchangeOidcCode()` returns: the same shape a password login returns. */
final readonly class OidcExchangeResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        /** The end user's session token. Keep it on your server. */
        public string $sessionToken,
        public Session $session,
        public array $raw = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            sessionToken: (string) ($data['session_token'] ?? ''),
            session: Session::fromArray($data['session'] ?? []),
            raw: $data,
        );
    }
}
