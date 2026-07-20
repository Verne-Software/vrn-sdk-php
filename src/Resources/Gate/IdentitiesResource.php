<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate;

use Vernesoft\Core\HttpClient;
use Vernesoft\Resources\Gate\Types\Identity;

class IdentitiesResource
{
    public function __construct(private HttpClient $httpClient) {}

    public function create(
        string $schemaId,
        array $traits,
        ?array $credentials = null,
        string $state = 'active',
    ): Identity {
        $body = [
            'schema_id' => $schemaId,
            'traits' => $traits,
            'state' => $state,
        ];

        if ($credentials !== null) {
            $body['credentials'] = $credentials;
        }

        $data = $this->httpClient->post('/v1/gate/identities', $body);

        return Identity::fromArray($data);
    }

    public function get(string $identityId): Identity
    {
        $data = $this->httpClient->get('/v1/gate/identities/'.$identityId);

        return Identity::fromArray($data);
    }

    /**
     * @param  array<array{op: string, path: string, value: mixed}>  $patch  JSON Patch array (RFC 6902)
     */
    public function patch(string $identityId, array $patch): Identity
    {
        $data = $this->httpClient->patch('/v1/gate/identities/'.$identityId, $patch);

        return Identity::fromArray($data);
    }

    public function delete(string $identityId): void
    {
        $this->httpClient->delete('/v1/gate/identities/'.$identityId);
    }

    /**
     * Activates or deactivates an identity. An `inactive` identity cannot log in —
     * Kratos rejects its credentials automatically — until it is reactivated.
     * The identity is not deleted. Fires the `identity.state_changed` webhook event.
     *
     * @param  'active'|'inactive'  $state
     */
    public function setState(string $identityId, string $state): Identity
    {
        $data = $this->httpClient->patch('/v1/gate/identities/'.$identityId.'/state', ['state' => $state]);

        return Identity::fromArray($data);
    }

    /**
     * Convenience wrapper for setState($identityId, 'active').
     */
    public function activate(string $identityId): Identity
    {
        return $this->setState($identityId, 'active');
    }

    /**
     * Convenience wrapper for setState($identityId, 'inactive').
     */
    public function deactivate(string $identityId): Identity
    {
        return $this->setState($identityId, 'inactive');
    }

    /**
     * Triggers a new email verification flow for an identity — useful when the
     * original verification email expired or was never received. The user
     * receives a fresh verification email.
     */
    public function resendVerification(string $identityId): void
    {
        $this->httpClient->post('/v1/gate/identities/'.$identityId.'/resend-verification');
    }
}
