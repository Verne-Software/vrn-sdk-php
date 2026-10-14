<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate;

use Vernesoft\Core\HttpClient;
use Vernesoft\Resources\Gate\Types\Session;
use Vernesoft\Resources\Gate\Types\SessionCheck;

/** End users' sessions: list, revoke, and check one is good enough to act on. */
class SessionsResource
{
    public function __construct(private HttpClient $httpClient) {}

    /**
     * The active sessions of one of your end users (all of them, not paged). An identity
     * of another tenant is `403 forbidden`.
     *
     * @return Session[]
     */
    public function list(string $identityId): array
    {
        $data = $this->httpClient->get('/v1/gate/identities/'.HttpClient::seg($identityId).'/sessions');

        return array_map(static fn (array $s): Session => Session::fromArray($s), $data ?? []);
    }

    /**
     * Revokes every session of an end user — "log out everywhere". Signed tokens already
     * issued keep verifying until their own `exp` (at most 15 minutes).
     *
     * Idempotent: a user of yours with no session at all is a success too. An id that is
     * not one of your users — another account's, unknown, or one you deleted — is
     * `403 forbidden`. `404 not_found` is rare: a user removed from the identity service
     * other than through `identities()->delete()`, which Gate still remembered as yours.
     */
    public function revokeAll(string $identityId): void
    {
        $this->httpClient->delete('/v1/gate/identities/'.HttpClient::seg($identityId).'/sessions');
    }

    /**
     * Revokes one session by its id. Revoking an already revoked session of your user is a
     * success too; an id that is not one of your users' sessions is `404 not_found`.
     */
    public function revoke(string $sessionId): void
    {
        $this->httpClient->delete('/v1/gate/sessions/'.HttpClient::seg($sessionId));
    }

    /**
     * Whether an end user's session meets what your tenant requires — the one call to
     * gate your own routes on. Gate on `mfaSatisfied`. Also lists the user's
     * organizations in the key's project, read now.
     *
     * Throws `VerneApiException` with status `401` (`unauthorized`) for a dead, foreign or
     * unknown session, and `403 mfa_required` when a second factor is owed —
     * `$e->details['required_aal']` is `aal2`; start a step-up flow with
     * `createLoginFlow(aal: 'aal2', sessionToken: $sessionToken)`. A `502` is worth a retry.
     */
    public function check(string $sessionToken): SessionCheck
    {
        $data = $this->httpClient->get('/v1/gate/auth/session', [], ['X-Session-Token' => $sessionToken]);

        return SessionCheck::fromArray($data ?? []);
    }
}
