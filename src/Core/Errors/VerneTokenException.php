<?php

declare(strict_types=1);

namespace Vernesoft\Core\Errors;

/**
 * Thrown by signed-token verification (`GateTokenVerifier::verify()`,
 * `tokens()->verifySigned()`) when a token must not be trusted. `$reason` says why; it
 * is one of the class constants.
 */
class VerneTokenException extends VerneException
{
    public const MALFORMED = 'malformed';

    public const UNSUPPORTED_ALG = 'unsupported_alg';

    public const UNKNOWN_KID = 'unknown_kid';

    public const BAD_SIGNATURE = 'bad_signature';

    public const WRONG_ISSUER = 'wrong_issuer';

    public const EXPIRED = 'expired';

    public const NOT_YET_VALID = 'not_yet_valid';

    public const MISSING_CLAIM = 'missing_claim';

    public const JWKS_UNAVAILABLE = 'jwks_unavailable';

    public function __construct(
        public readonly string $reason,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
