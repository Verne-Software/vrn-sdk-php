<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate;

use Vernesoft\Core\HttpClient;
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
}