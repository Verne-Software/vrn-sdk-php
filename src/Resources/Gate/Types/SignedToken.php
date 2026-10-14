<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

/** What `tokens()->createSigned()` returns. */
final readonly class SignedToken
{
    public function __construct(
        /** The compact ES256 JWT. Valid for 15 minutes; it cannot be revoked. */
        public string $token,
        /** The session the token was made from, as `whoami` returns it. */
        public Session $session,
    ) {}

    public static function fromArray(array $data): self
    {
        $token = (string) ($data['tokenized'] ?? '');
        unset($data['tokenized']);

        return new self(token: $token, session: Session::fromArray($data));
    }
}
