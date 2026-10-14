# Changelog

All notable changes to `vernesoft/sdk`. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/).

## [1.4.0] — unreleased

### Added

- **One key for every service**: `new Verne(apiKey: …)`. Keys are `vrn_…` with scopes;
  the per-service keys (`relay:`, `gate:`, …) still work and override `apiKey`.
  `apiKey` is the constructor's last parameter, so positional 1.3.0 calls keep working;
  pass it by name.
- **Organizations, roles and members**: `gate()->organizations()` (`create`, `list`,
  `get`, `update`, `delete`), `->roles()` (`create`, `list`, `get`, `update`, `delete`),
  `->members()` (`add`, `list`, `update`, `remove`), and
  `gate()->identities()->listOrganizations()`. `members()->update()` keeps, removes
  (`clearExpiresAt: true`) or sets (`expiresAt:`) the deadline.
- **Permission checks in an organization**: `organizations()->authorize($orgId,
  $identityId, $permission)` and `->can(…)` → `bool`. `AuthorizeResult` gains `role`;
  `AuthorizeReason` holds the organization `reason` values.
- **Audit log**: `gate()->auditLog()->list()` with filters and cursor paging.
- **Automatic deletion rules**: `gate()->lifecycle()` (`listRules`, `setRule`,
  `deleteRule`, `previewRule`, `listActions`); `LifecycleRuleKind` constants.
- **Signed tokens (JWT)**: `tokens()->createSigned($sessionToken)`,
  `tokens()->verifySigned($token, tenantId: …)` and the standalone `GateTokenVerifier` —
  ES256 against the tenant's JWKS with `ext-openssl`, issuer checked, no new dependency.
  Failures throw `VerneTokenException` with a `reason`.
- **Sessions**: `gate()->sessions()` (`list`, `revoke`, `revokeAll`, `check`). `check()`
  is `GET /v1/gate/auth/session`: `mfaSatisfied`, `orgs`, and `403 mfa_required`.
- **Custom metadata**: `identities()->getMetadata()` / `updateMetadata()`.
- **Social-login return**: `settings()->getReturnUrls()` / `updateReturnUrls()`,
  `gate()->exchangeOidcCode()`, `gate()->createRegistrationFlow()`, and `returnTo:`,
  `state:`, `aal:`, `sessionToken:` on `gate()->createLoginFlow()`.
- `orgs` / `orgsTruncated` on Gate and Passepartout introspection results.
- `clockwork()->usage()`; `Execution::$attempt`.
- `Identity` gains `createdAt`, `updatedAt`, `stateChangedAt`, `metadataPublic`,
  `verifiableAddresses`, `recoveryAddresses` and `raw`; `IdentityTraits` gains `locale`
  and `extra`.
- `VerneApiException::$details` (the extra members of the error: `plan`, `limit`,
  `required_aal`, …), `::$retryAfter`, `::$body`, `getStatus()`.
- `composer test:integration`: an opt-in suite against a running stack, and
  `scripts/local-stack-key.php` to get a throwaway key on a local one.

### Fixed

- `tokens()->create()` sent the key in the request body without an `Authorization`
  header, which the API answers with `401`. It now sends the header like every other
  call, and no longer puts the key in the body.
- A `429` on a `POST` was retried after sleeping out any `Retry-After` — for Relay's
  monthly limit, up to a month. Only a `Retry-After` of 60 seconds or less is waited out
  now; the monthly limit (`RELAY_MESSAGE_LIMIT_REACHED`) and every `402` are thrown at
  once with `retryAfter`.
- An error body whose `error` is a string (Clockwork) became `Unknown error`; the
  sentence is now the message. A numeric `code` (identity-service bodies) is reported as
  `unknown`. A body that is not JSON is now a `VerneApiException` with the status
  (message `HTTP <status>`) instead of a bare `VerneException`.
- `VerneApiException::getRequestId()` returns `null`, not `''`, when the response had no
  request id.
- Types: identity traits are `email` and `locale` — the `custom_data` and `tenant_id`
  traits never existed in the API (creating with `custom_data` is a `400`); both
  properties stay on `IdentityTraits`, deprecated and always `null`. Use custom metadata.
  Introspection fields other than `active` are nullable (an inactive token is just
  `active: false`).
- Docs: keys are `vrn_…` with no test/live environment; the Relay idempotency window is
  12 hours, not 24; `409` means "already exists in your account".
- Ids in paths are URL-encoded; `getEnabledProviders()` no longer sends the key to the
  public endpoint.

## [1.3.0] — 2026-07-25

### Added

- Clockwork (Cron-as-a-Service): `clockwork()->jobs()` and `clockwork()->delayed()`.

## [1.2.0] — 2026-07-24

### Added

- Passepartout (Telegram sign-in): `loginStart`, `loginStatus`, `introspect`.

## [1.1.0] — 2026-07-21

### Added

- Gate: identity state (`setState`, `activate`, `deactivate`), `resendVerification`,
  security settings, social login providers (`getOidcProviders`, `updateOidcProviders`,
  `getEnabledProviders`, `createLoginFlow`).
