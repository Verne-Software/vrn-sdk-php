<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate;

use Vernesoft\Core\HttpClient;
use Vernesoft\Resources\Gate\Types\OidcProvider;
use Vernesoft\Resources\Gate\Types\SecuritySettings;

class SettingsResource
{
    public function __construct(private HttpClient $httpClient) {}

    /**
     * Returns the tenant's security settings (passwordless / MFA).
     */
    public function getSecurity(): SecuritySettings
    {
        $data = $this->httpClient->get('/v1/gate/settings/security');

        return SecuritySettings::fromArray($data);
    }

    /**
     * Replaces the tenant's security settings. Both fields are required — the
     * update is a full replacement, not a merge.
     */
    public function updateSecurity(bool $passwordlessEnabled, bool $mfaEnabled): void
    {
        $this->httpClient->put('/v1/gate/settings/security', [
            'passwordless_enabled' => $passwordlessEnabled,
            'mfa_enabled' => $mfaEnabled,
        ]);
    }

    /**
     * Returns the tenant's social login (OIDC) providers and whether each is
     * enabled — covering every provider Gate supports, regardless of state.
     *
     * @return OidcProvider[]
     */
    public function getOidcProviders(): array
    {
        $data = $this->httpClient->get('/v1/gate/settings/oidc-providers');

        return array_map(
            static fn (array $p): OidcProvider => OidcProvider::fromArray($p),
            $data['providers'] ?? [],
        );
    }

    /**
     * Sets the `enabled` flag for one or more social login providers. Any
     * provider omitted from `$providers` is left unchanged. Returns the full,
     * updated provider list.
     *
     * @param  OidcProvider[]  $providers
     * @return OidcProvider[]
     */
    public function updateOidcProviders(array $providers): array
    {
        $data = $this->httpClient->put('/v1/gate/settings/oidc-providers', [
            'providers' => array_map(
                static fn (OidcProvider $p): array => $p->toArray(),
                $providers,
            ),
        ]);

        return array_map(
            static fn (array $p): OidcProvider => OidcProvider::fromArray($p),
            $data['providers'] ?? [],
        );
    }
}