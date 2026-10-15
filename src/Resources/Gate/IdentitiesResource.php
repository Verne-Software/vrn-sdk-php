<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate;

use Vernesoft\Core\HttpClient;
use Vernesoft\Core\Types\Paginated;
use Vernesoft\Resources\Gate\Types\Identity;
use Vernesoft\Resources\Gate\Types\OrgClaim;

class IdentitiesResource
{
    public function __construct(private HttpClient $httpClient) {}

    /**
     * Creates a new identity (end user) bound to your tenant. Answers `201`.
     *
     * Traits are `email` (required) and `locale` only — anything else is refused with
     * `400 invalid_payload`. Keep your own fields in custom metadata (`$metadataPublic`, or
     * `updateMetadata()` later). A `409 conflict` means you already have an end user with
     * this address; the same address at another tenant is a different person and does not
     * conflict.
     *
     * To import a user from another system, send the bcrypt hash of their password
     * (`['password' => ['config' => ['hashed_password' => '$2b$10$…']]]`), the social
     * accounts they used (`['oidc' => ['config' => ['providers' => [['provider' => 'google',
     * 'subject' => '…']]]]]`) and `emailVerified: true` if that system had already confirmed
     * the address. `$credentials` holds either the initial password or that hash, and the
     * linked social accounts.
     *
     * @param  array{email: string, locale?: string}  $traits
     * @param  array{password?: array{config: array{password?: string, hashed_password?: string}}, oidc?: array{config: array{providers: list<array{provider: string, subject: string}>}}}|null  $credentials
     * @param  bool|null  $emailVerified  `true` creates the user with the address already confirmed; null or
     *                                    `false` leaves it unconfirmed. It is your word: Gate sends no mail to check it.
     * @param  array<string, mixed>|null  $metadataPublic  Your custom metadata for the user. The user can read it.
     */
    public function create(
        string $schemaId,
        array $traits,
        ?array $credentials = null,
        string $state = 'active',
        ?bool $emailVerified = null,
        ?array $metadataPublic = null,
    ): Identity {
        $body = [
            'schema_id' => $schemaId,
            'traits' => $traits,
            'state' => $state,
        ];

        if ($credentials !== null) {
            $body['credentials'] = $credentials;
        }

        if ($emailVerified !== null) {
            $body['email_verified'] = $emailVerified;
        }

        if ($metadataPublic !== null) {
            // An empty array would go out as `[]`, which is not an object.
            $body['metadata_public'] = $metadataPublic === [] ? new \stdClass : $metadataPublic;
        }

        $data = $this->httpClient->post('/v1/gate/identities', $body);

        return Identity::fromArray($data);
    }

    /**
     * Returns an identity by id. An identity of another tenant is refused.
     */
    public function get(string $identityId): Identity
    {
        $data = $this->httpClient->get('/v1/gate/identities/'.HttpClient::seg($identityId));

        return Identity::fromArray($data);
    }

    /**
     * Partially updates an identity with a JSON Patch document (RFC 6902), e.g.
     * `[['op' => 'replace', 'path' => '/traits/email', 'value' => 'new@example.com']]`.
     *
     * @param  array<array{op: string, path: string, value?: mixed, from?: string}>  $patch
     */
    public function patch(string $identityId, array $patch): Identity
    {
        $data = $this->httpClient->patch('/v1/gate/identities/'.HttpClient::seg($identityId), array_values($patch));

        return Identity::fromArray($data);
    }

    /**
     * Deletes an identity, its sessions and its memberships. Fires `identity.deleted`.
     */
    public function delete(string $identityId): void
    {
        $this->httpClient->delete('/v1/gate/identities/'.HttpClient::seg($identityId));
    }

    /**
     * Activates or deactivates an identity. An `inactive` identity cannot sign in, its
     * sessions are suspended (not revoked: they work again once it is active), its access
     * tokens introspect as `active: false`, and no signed token is issued for it. Nothing
     * is deleted. Fires the `identity.state_changed` webhook event.
     *
     * @param  'active'|'inactive'  $state
     */
    public function setState(string $identityId, string $state): Identity
    {
        $data = $this->httpClient->patch('/v1/gate/identities/'.HttpClient::seg($identityId).'/state', ['state' => $state]);

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
        $this->httpClient->post('/v1/gate/identities/'.HttpClient::seg($identityId).'/resend-verification');
    }

    /**
     * Reads an identity's custom metadata (`metadata_public`) — the place for your own
     * fields about a user. `[]` when nothing is set. An identity that is not yours is
     * `403 forbidden`.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(string $identityId): array
    {
        $data = $this->httpClient->get('/v1/gate/identities/'.HttpClient::seg($identityId).'/metadata');

        return is_array($data['metadata_public'] ?? null) ? $data['metadata_public'] : [];
    }

    /**
     * Merges `$patch` into an identity's custom metadata and returns the result. Keys you
     * leave out are kept; a key set to `null` is removed.
     *
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public function updateMetadata(string $identityId, array $patch): array
    {
        $data = $this->httpClient->patch(
            '/v1/gate/identities/'.HttpClient::seg($identityId).'/metadata',
            $patch === [] ? new \stdClass : $patch,
        );

        return is_array($data['metadata_public'] ?? null) ? $data['metadata_public'] : [];
    }

    /**
     * The organizations of the key's project that an end user belongs to, with the role
     * held in each and its permissions — live, oldest membership first, one page at a
     * time. This is the complete list that `orgsTruncated` (in a signed token, an
     * introspection or a session check) points to. Another tenant's user is `403`.
     *
     * @return Paginated<OrgClaim>
     */
    public function listOrganizations(string $identityId, ?int $limit = null, ?string $cursor = null): Paginated
    {
        $data = $this->httpClient->get(
            '/v1/gate/identities/'.HttpClient::seg($identityId).'/organizations',
            ['limit' => $limit, 'cursor' => $cursor],
        );

        return Paginated::fromArray($data, static fn (array $o): OrgClaim => OrgClaim::fromArray($o));
    }
}
