# Verne Software PHP SDK

[![Packagist Version](https://img.shields.io/packagist/v/vernesoft/sdk.svg)](https://packagist.org/packages/vernesoft/sdk)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](#)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

The official PHP library for [Nautilus by Verne Software](https://vernesoft.com): sign-in and users, webhook delivery, cron jobs and Telegram sign-in, behind one API key. Nautilus runs on servers in France. Documentation: [docs.vernesoft.com](https://docs.vernesoft.com).

> **Server-side only.** API keys carry full access to the APIs they were created for and must never be used in browser or client-side code.

## Requirements

PHP 8.2 or later (the response types are readonly classes) and Guzzle 7 or 8. `ext-openssl` to verify signed tokens.

## Installation

```bash
composer require vernesoft/sdk
```

## Quick Start

```php
use Vernesoft\Verne;

$verne = new Verne(apiKey: getenv('VERNE_API_KEY'));

$verne->relay()->messages()->send(eventType: 'user.created', payload: ['id' => 'usr_123']);
$verne->gate()->organizations()->create(name: 'Acme');
```

### API keys

Create a key on **Dashboard → Keys** and tick the APIs it may call — its **scopes**: `relay`, `gate`, `clockwork`, `passepartout`. A key looks like `vrn_9f8a7…` and does not name an API. Pass it once as `apiKey:`; the client sends it as `Authorization: Bearer …` to every service.

- A call to an API the key has no scope for is refused by the API with `403`, code `forbidden`, message `This API key is not authorized for <API>.` The SDK does not check scopes itself.
- To use a different key for one service, pass it as `relay:`, `gate:`, `clockwork:` or `passepartout:`; it overrides `apiKey` for that service only.
- There is **no test or live mode and no sandbox**: every key acts on your real data. Use a separate key per environment.
- Keys made before scopes existed (`vrn_gate_…`, `vrn_relay_…`, …) keep working, for the one API they name.

Always pass `apiKey:` by name: it is the constructor's last parameter so that 1.3.0 calls with positional per-service keys keep working.

You can also instantiate one service on its own:

```php
use Vernesoft\Clockwork;
use Vernesoft\Gate;

$gate = new Gate(apiKey: getenv('VERNE_API_KEY'));
$clockwork = new Clockwork(apiKey: getenv('VERNE_API_KEY'));
```

## Relay — Webhooks-as-a-Service

Send events to all subscribed endpoints:

```php
$message = $verne->relay()->messages()->send(
    eventType: 'user.created',
    payload: ['id' => 'usr_123'],
);
```

Optional parameters:

```php
$message = $verne->relay()->messages()->send(
    eventType: 'order.placed',
    payload: ['order_id' => '999'],
    idempotencyKey: 'evt_abc', // a repeat within 12 hours returns the original message
    channels: ['team-a'],      // restrict to specific endpoint channels
);
```

List previously sent events:

```php
$page = $verne->relay()->messages()->list(limit: 20, eventType: 'user.created');

$page->data;       // Message[]
$page->hasMore;    // bool
$page->nextCursor; // pass to the next call to paginate
```

## Gate — sign-in and users

### Identities

Manage your end users. They are kept apart per tenant: the same address at another tenant is a different person.

```php
$gate = $verne->gate();

// Create a user (traits are `email` and, optionally, `locale`)
$identity = $gate->identities()->create(
    schemaId: 'user',
    traits: ['email' => 'user@example.com'],
    credentials: ['password' => ['config' => ['password' => 'StrongPassword123!']]],
);
// A second user with the same address in your account is a 409 `conflict`.

// Get, update (JSON Patch — RFC 6902), delete
$gate->identities()->get($identity->id);
$gate->identities()->patch($identity->id, [
    ['op' => 'replace', 'path' => '/traits/email', 'value' => 'new@example.com'],
]);
$gate->identities()->delete($identity->id);

// Activate / deactivate (an inactive user cannot sign in; nothing is deleted)
$gate->identities()->deactivate($identity->id);
$gate->identities()->activate($identity->id);

// Resend the email verification link
$gate->identities()->resendVerification($identity->id);
```

The typed fields are on the result (`$identity->traits->email`, `->createdAt`, `->metadataPublic`, …); the whole object as the API sent it is in `$identity->raw`.

**Importing users** from another system: send the bcrypt hash of their password instead of the password, the social accounts they signed in with, `emailVerified: true` if that system had already confirmed the address, and your metadata — all in one call:

```php
$imported = $gate->identities()->create(
    schemaId: 'user',
    traits: ['email' => 'ada@example.com'],
    credentials: [
        'password' => ['config' => ['hashed_password' => '$2b$10$pl0VVKxZkNvtC9ttGwsT8.vPL8qWeEevw4a6FraeqQR8GQ6mT63NG']],
        'oidc' => ['config' => ['providers' => [['provider' => 'google', 'subject' => '109876543210987654321']]]],
    ],
    emailVerified: true, // the address starts confirmed; Gate sends no mail to check it
    metadataPublic: ['previous_user_id' => 'auth0|64f1c0ffee'],
);
$imported->verifiableAddresses[0]['verified']; // true
```

The user then signs in with their original password. Without `emailVerified: true` a new user's address starts unconfirmed.

Your own fields about a user go in **custom metadata**, not in traits (any trait other than `email` and `locale` is a `400`):

```php
$gate->identities()->updateMetadata($identity->id, ['plan' => 'pro', 'seats' => 5]); // merged
$gate->identities()->updateMetadata($identity->id, ['seats' => null]);               // removes `seats`
$metadata = $gate->identities()->getMetadata($identity->id);                        // ['plan' => 'pro']
```

### Organizations, roles and members

Group your end users into organizations (their teams, workspaces, companies). A **role** is a named set of permissions inside one organization; a **member** holds one role. Everything acts on the project the key belongs to. Gate keeps this data and reports it; it does not enforce it — ask it.

```php
$org = $gate->organizations()->create(name: 'Acme');

$admin = $gate->organizations()->roles()->create(
    $org->id,
    name: 'admin',                                     // lowercase, digits, _ and -
    permissions: ['invoices:read', 'invoices:write'], // lowercase segments joined by `:`
);

$gate->organizations()->members()->add(
    $org->id,
    identityId: $identity->id,
    roleId: $admin->id,
    expiresAt: new DateTimeImmutable('2027-01-31T23:59:59Z'), // optional: the membership ends then
);

// Change the role or the deadline. Leave expiresAt out to keep the deadline,
// clearExpiresAt: true to remove it, a time to set it.
$gate->organizations()->members()->update($org->id, $identity->id, clearExpiresAt: true);

// Lists are cursor-paged: loop while hasMore, passing nextCursor as cursor
$cursor = null;
do {
    $page = $gate->organizations()->members()->list($org->id, limit: 50, cursor: $cursor);
    foreach ($page->data as $member) { /* $member->identityId, $member->roleId */ }
    $cursor = $page->nextCursor;
} while ($page->hasMore);

// A user's organizations, with role and permissions in each
$held = $gate->identities()->listOrganizations($identity->id); // Paginated<OrgClaim>
```

A role somebody holds cannot be deleted (`409 conflict`); a second role of the same name in one organization is `409 conflict`; past 100 roles, `409 limit_reached`. Deleting an organization removes its roles and members.

#### Checking a permission

```php
if (! $gate->organizations()->can($org->id, $identity->id, 'invoices:write')) {
    throw new RuntimeException('Forbidden');
}

// Or the full decision:
$decision = $gate->organizations()->authorize($org->id, $identity->id, 'invoices:write');
// $decision->allowed === true, $decision->reason === 'member_with_permission', $decision->role === 'admin'
```

`reason` is one of the `AuthorizeReason` constants — `member_with_permission`, `member_without_permission`, `not_a_member`, `subject_inactive`, `no_such_organization`; `role` is set when the user is a member. The match is exact (no wildcards) and live. A permission no role could hold (`Invoices:*`, the `nautilus:` prefix) is a `400 invalid_payload` exception, never `false`.

`$gate->authorize(subject:, action:, resource:)` is the same endpoint; with any resource that is not `organization:<id>` it only says whether `subject` is one of your active end users. Its `reason` is a code too: `AuthorizeReason::OWNED_BY_TENANT` (the one `allowed: true`), `SUBJECT_INACTIVE`, `NOT_OWNED_BY_TENANT`, `NOT_A_KNOWN_IDENTITY`. The sentence a person would read is in `$decision->message`, for your logs — its wording can change, `reason` is the contract.

```php
$decision = $gate->authorize(subject: $identity->id, action: 'read', resource: 'doc:1');
// $decision->reason === AuthorizeReason::OWNED_BY_TENANT, $decision->message === 'subject `…` is an identity owned by tenant `…`'
```

### Sessions

```php
// Is this session good enough to act on? (the one call to gate your routes on)
$check = $gate->sessions()->check($sessionToken);
// $check->active, ->aal, ->mfaRequired, ->mfaSatisfied, ->identityId, ->orgs (OrgClaim[])

$sessions = $gate->sessions()->list($identity->id); // Session[]
$gate->sessions()->revoke($sessions[0]->id);       // one device
$gate->sessions()->revokeAll($identity->id);       // everywhere
```

With MFA required (`settings()->updateSecurity(passwordlessEnabled: …, mfaEnabled: true)`), `check()` throws a `VerneApiException` with code `mfa_required` — branch on it and start a step-up flow:

```php
use Vernesoft\Core\Errors\VerneApiException;

try {
    $check = $gate->sessions()->check($sessionToken);
} catch (VerneApiException $e) {
    if ($e->getErrorCode() === 'mfa_required') {
        // $e->details['required_aal'] === 'aal2', $e->details['current_aal'] === 'aal1'
        $flow = $gate->createLoginFlow(aal: 'aal2', sessionToken: $sessionToken);
    } elseif ($e->getCode() === 401) {
        // dead, foreign or unknown session: sign in again
    } else {
        throw $e;
    }
}
```

`revokeAll()` succeeds also when the user has no session, so a "log out everywhere" button can be pressed twice. An id that is not one of your users (another account's, unknown, or one you deleted) is `403 forbidden`.

### Signed tokens (JWT)

For a platform that verifies signatures instead of calling Gate (Supabase, an API gateway):

```php
$signed = $gate->tokens()->createSigned($sessionToken); // the session is the credential; no API key is sent

$claims = $gate->tokens()->verifySigned($signed->token, tenantId: getenv('VERNE_TENANT_ID'));
$claims->sub;  // the end user's id
$claims->orgs; // OrgClaim[], as of when the token was made
```

Verification is offline, against your tenant's public keys (fetched once, cached, refetched when a new key appears), with `ext-openssl`. It refuses anything but ES256, another tenant's issuer, a bad signature, an expired token — with a `VerneTokenException` whose `$reason` says which (`malformed`, `unsupported_alg`, `unknown_kid`, `bad_signature`, `wrong_issuer`, `expired`, `not_yet_valid`, `missing_claim`, `jwks_unavailable`). It always checks `iss`. To verify where you have no Gate client:

```php
use Vernesoft\GateTokenVerifier;

$verifier = new GateTokenVerifier(tenantId: getenv('VERNE_TENANT_ID'));
$claims = $verifier->verify($token);
```

A signed token lives **15 minutes** and **cannot be revoked**; its `orgs` are as old as the token. For anything that must follow a revocation or a role change at once, use `sessions()->check()`, introspection or `organizations()->can()`.

### Audit log

```php
$page = $gate->auditLog()->list(
    action: 'organization.member_added',
    from: new DateTimeImmutable('-7 days'),
    limit: 50,
);
// newest first; each entry: id, occurredAt, action, actor (type, id), target (type, id) or null,
// projectId, details, ip, userAgent
```

Any key with the `gate` scope reads the **whole** log, including your team members' IP addresses and user agents. Keep `gate` keys on your backend.

### Automatic deletion of inactive accounts

```php
use Vernesoft\Resources\Gate\Types\LifecycleRuleKind;

$rules = $gate->lifecycle()->listRules(); // ->tier, ->rules (all four kinds)

$preview = $gate->lifecycle()->previewRule(LifecycleRuleKind::DELETE_UNVERIFIED, [
    'delete_after_days' => 14,
    'warn_before_days' => 3,
]); // ->matchingNow, ->capped — look before you switch it on

$gate->lifecycle()->setRule(
    LifecycleRuleKind::DELETE_UNVERIFIED,
    enabled: true,
    params: ['delete_after_days' => 14, 'warn_before_days' => 3],
);

$actions = $gate->lifecycle()->listActions(status: 'pending');
$gate->lifecycle()->deleteRule(LifecycleRuleKind::DELETE_UNVERIFIED);
```

Only `delete_unverified` is in the Free plan; switching on another rule is `403 plan_required`. `expire_roles` takes no settings (`[]`).

### Settings and social login

```php
$security = $gate->settings()->getSecurity(); // ->passwordlessEnabled, ->mfaEnabled
// Both fields are required — the update is a full replacement, not a merge.
$gate->settings()->updateSecurity(passwordlessEnabled: true, mfaEnabled: false);

// Social login: where your backend receives the user (matched exactly)
$gate->settings()->updateReturnUrls(['https://app.example.com/auth/callback']);
$flow = $gate->createLoginFlow(state: $csrfBoundValue); // then, at your callback:
$result = $gate->exchangeOidcCode($_GET['code']);       // ->sessionToken, ->session
```

The code works once, for about 60 seconds, and only with a key of the project that started the login. Keep the session token on your server. `createRegistrationFlow(returnTo:, state:)` starts a registration flow the same way.

### Access tokens

Short-lived opaque tokens for a user or a service account:

```php
$token = $gate->tokens()->create(
    subject: 'usr_123',
    scopes: ['gate.tokens.read'], // optional
    ttlSeconds: 3600,             // optional, default 3600, max 86400
);

$info = $gate->tokens()->introspect($token->accessToken);
if (! $info->active) {
    // expired, unknown, or its user is deactivated or deleted — every other field is null
}
// For an end user, $info->orgs lists their organizations, read now.
```

## Passepartout — Telegram sign-in

```php
$start = $verne->passepartout()->loginStart();
// send the user to $start->deepLink, then poll:
$status = $verne->passepartout()->loginStatus($start->nonce);
if ($status->status === 'completed') {
    $info = $verne->passepartout()->introspect($status->accessToken);
    $info->orgs; // the user's organizations in your project
}
```

## Clockwork — Cron-as-a-Service

Schedule recurring cron jobs and one-off delayed jobs that call your HTTP endpoints.

### Cron Jobs

```php
// Create a recurring job (standard 5-field cron expression)
$job = $verne->clockwork()->jobs()->create(
    name: 'nightly-report',
    schedule: '0 2 * * *',
    url: 'https://example.com/hooks/report',
    method: 'POST',                     // optional (defaults server-side)
    headers: ['X-Token' => 'secret'],   // optional
    body: '{"scope":"daily"}',          // optional
);

// Read the schedule in a time zone: 09:00 in Paris all year round —
// 07:00 UTC in summer, 08:00 UTC in winter. Without `timezone:` a job is UTC.
$morning = $verne->clockwork()->jobs()->create(
    name: 'morning-digest',
    schedule: '0 9 * * *',
    url: 'https://example.com/hooks/digest',
    timezone: 'Europe/Paris', // IANA name; an unknown one is 400 invalid_payload
);
$morning->timezone;  // 'Europe/Paris'
$morning->nextRunAt; // in UTC, e.g. '2026-10-16T07:00:00Z'
$verne->clockwork()->jobs()->update($morning->id, ['timezone' => 'America/New_York']);

// List all cron jobs
$jobs = $verne->clockwork()->jobs()->list(); // CronJob[]

// Update a job (snake_case fields — e.g. pause it)
$verne->clockwork()->jobs()->update($job->id, ['is_active' => false]);

// Inspect run history (one row per attempt; $execution->attempt counts from 1)
$executions = $verne->clockwork()->jobs()->executions($job->id); // Execution[]

// Delete a job
$verne->clockwork()->jobs()->delete($job->id);
```

### Delayed Jobs

One-off jobs that fire once at a specific time:

```php
$delayed = $verne->clockwork()->delayed()->create(
    name: 'send-reminder',
    runAt: '2027-01-01T09:00:00Z',
    url: 'https://example.com/hooks/reminder',
    method: 'POST',
);

$verne->clockwork()->delayed()->list();                   // DelayedJob[]
$verne->clockwork()->delayed()->executions($delayed->id); // Execution[]
$verne->clockwork()->delayed()->cancel($delayed->id);
```

### Usage

```php
$usage = $verne->clockwork()->usage();
// $usage->plan, $usage->cron->active, $usage->cron->limit, $usage->delayed->pending, $usage->delayed->limit
// (a null limit means no limit)
```

## Error Handling

API errors throw a `VerneApiException`:

```php
use Vernesoft\Core\Errors\VerneApiException;
use Vernesoft\Core\Errors\VerneException;

try {
    $verne->gate()->organizations()->members()->add($orgId, identityId: $userId, roleId: $roleId);
} catch (VerneApiException $e) {
    $e->getCode();      // HTTP status — branch on status + code
    $e->getErrorCode(); // e.g. 'conflict'
    $e->getMessage();   // a sentence to show a person
    $e->details;        // extra members of the error, e.g. ['plan' => …, 'limit' => …, 'current' => …]
    $e->retryAfter;     // seconds, when the response had Retry-After
    $e->getRequestId(); // include in support requests (null when the response had none)
} catch (VerneException $e) {
    // network error or timeout
}
```

| Status | Code | Meaning |
|:--|:--|:--|
| 400 | `invalid_payload` | Something in the request is malformed; the message says what. |
| 401 | `unauthorized` | No key, or a revoked or invalid one; for a session, a dead session. |
| 402 | `ACTIVE_USER_LIMIT_REACHED`, `UPGRADE_REQUIRED`, `JOB_LIMIT_REACHED`, `PROJECT_LIMIT_REACHED`, `BILLING_HARD_BLOCKED`, `SUBSCRIPTION_PAST_DUE`, `payment_required` | A plan or billing limit. Does not lift by waiting — the SDK never retries it. |
| 403 | `forbidden` | The key lacks the scope for this API (the message names it), or the resource is not yours. |
| 403 | `plan_required`, `mfa_required`, `account_disabled` | A rule not in your plan; a second factor owed; a deactivated Telegram user. |
| 404 | `not_found` | No such thing in your project. |
| 409 | `conflict`, `limit_reached` | It already exists **in your account** (an end user with that address, a role name, a membership), a role still held, or a cap reached. |
| 429 | `rate_limited`, `RELAY_MESSAGE_LIMIT_REACHED` | Too fast, or Relay's monthly limit. |
| 5xx | `upstream_unavailable`, `internal_error` | Retry; quote the request id to support. |

Codes are an open set; treat unknown ones generically. Every API route, Clockwork's included, answers in this envelope with a string code and a request id. A body in another shape (one that did not come from the API — a proxy in between, an identity-service answer passed through on `/auth/*`, a body that is not JSON) still becomes a `VerneApiException`, with code `unknown` and its sentence (or `HTTP <status>`) as the message; the parsed body is in `$e->body`.

**Retries.** A `POST` that gets a short `429` (a `Retry-After` of 60 seconds or less) is retried once after waiting. Relay's monthly limit (`429 RELAY_MESSAGE_LIMIT_REACHED`, `Retry-After` until the 1st of next month) and every `402` are thrown at once; read `$e->retryAfter`.

A refused signed token throws `VerneTokenException` (a `VerneException`).

## Configuration

`Verne` and the per-service clients accept `baseUrl` and `timeoutSeconds` (default `30`):

```php
$verne = new Verne(apiKey: getenv('VERNE_API_KEY'), timeoutSeconds: 10);
```

## Development

```bash
composer install
composer test      # unit tests (no network)
composer lint      # Laravel Pint, check only (./vendor/bin/pint to fix)
```

### Integration tests

`composer test:integration` runs every 1.4.0 resource against a real Nautilus stack. It is opt-in and skipped without a key; it creates end users, an organization and settings changes, and removes them again.

| Variable | |
|:--|:--|
| `VERNE_API_KEY` | A key with the scopes `gate`, `clockwork`, `passepartout`. Required. |
| `VERNE_BASE_URL` | Default `http://api.soft.com` (the local stack). Any other host needs `VERNE_ALLOW_REMOTE=1`. |
| `VERNE_EMAIL_PREFIX` | Every end user created is `<prefix>-<name>@e2e.soft.com`. |
| `VERNE_NARROW_KEY` | Optional: a key **without** the `gate` scope, to check the `403`. |

Against a local stack, `scripts/local-stack-key.php` registers a throwaway `e2e-sdkphp-` tenant and mints the keys:

```bash
php scripts/local-stack-key.php create /tmp/sdk-it.json
eval "$(php scripts/local-stack-key.php env /tmp/sdk-it.json)"
composer test:integration
php scripts/local-stack-key.php revoke /tmp/sdk-it.json   # then purge the tenant it names
```

## License

MIT
