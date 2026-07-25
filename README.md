# Verne Software PHP SDK

[![Packagist Version](https://img.shields.io/packagist/v/vernesoft/sdk.svg)](https://packagist.org/packages/vernesoft/sdk)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-blue.svg)](#)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

The official PHP library for the [Verne Nautilus](https://vernesoft.com) platform.

> **Server-side only.** API keys carry full service access and must never be used in browser or client-side code.

## Requirements

PHP 8.1 or later.

## Installation

```bash
composer require vernesoft/sdk
```

## Quick Start

```php
use Vernesoft\Verne;

$verne = new Verne(
    relay: $_ENV['VERNE_RELAY_KEY'],
    gate: $_ENV['VERNE_GATE_KEY'],
    clockwork: $_ENV['VERNE_CLOCKWORK_KEY'],
);
```

You can also instantiate services independently if you only need one:

```php
use Vernesoft\Relay;
use Vernesoft\Gate;

$relay = new Relay(apiKey: $_ENV['VERNE_RELAY_KEY']);
$gate  = new Gate(apiKey: $_ENV['VERNE_GATE_KEY']);
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
    idempotencyKey: 'evt_abc', // prevent duplicate delivery within 24h
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

## Gate — Auth-as-a-Service

### Identity Management

Manage your end-users. The `tenant_id` is automatically scoped to your API key.

```php
// Create a user
$identity = $verne->gate()->identities()->create(
    schemaId: 'user',
    traits: [
        'email' => 'user@example.com',
        'custom_data' => ['role' => 'editor'],
    ],
    credentials: [
        'password' => ['config' => ['password' => 'StrongPassword123!']],
    ],
    state: 'active',
);

// Get a user
$verne->gate()->identities()->get($identity->id);

// Update a user (JSON Patch — RFC 6902)
$verne->gate()->identities()->patch($identity->id, [
    ['op' => 'replace', 'path' => '/traits/custom_data/role', 'value' => 'admin'],
]);

// Delete a user
$verne->gate()->identities()->delete($identity->id);

// Activate / deactivate a user (an inactive user cannot log in)
$verne->gate()->identities()->deactivate($identity->id);
$verne->gate()->identities()->activate($identity->id);
// …or set the state explicitly:
$verne->gate()->identities()->setState($identity->id, 'inactive');

// Resend the email verification link
$verne->gate()->identities()->resendVerification($identity->id);
```

### Security Settings

Read or replace the tenant's security settings (passwordless login, TOTP MFA):

```php
$security = $verne->gate()->settings()->getSecurity();
// $security->passwordlessEnabled, $security->mfaEnabled

// Both fields are required — the update is a full replacement, not a merge.
$verne->gate()->settings()->updateSecurity(
    passwordlessEnabled: true,
    mfaEnabled: false,
);
```

### Access Tokens

Exchange your long-lived API key for a short-lived access token:

```php
$token = $verne->gate()->tokens()->create(
    subject: 'usr_123',
    scopes: ['gate.tokens.read'], // optional
    ttlSeconds: 3600,             // optional, default 3600, max 86400
);

// $token->accessToken — attach to downstream requests
// $token->expiresAt  — ISO 8601 expiry
```

Validate a token:

```php
$info = $verne->gate()->tokens()->introspect($token->accessToken);

if (! $info->active) {
    // token is expired or invalid
}
```

### Authorization

Check whether a subject is allowed to perform an action:

```php
$decision = $verne->gate()->authorize(
    subject: 'usr_123',
    action: 'relay.messages.read',
    resource: 'tenant:ten_001',
);

if (! $decision->allowed) {
    throw new \RuntimeException('Forbidden');
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

// List all cron jobs
$jobs = $verne->clockwork()->jobs()->list(); // CronJob[]

// Update a job (snake_case fields — e.g. pause it)
$verne->clockwork()->jobs()->update($job->id, ['is_active' => false]);

// Inspect run history
$executions = $verne->clockwork()->jobs()->executions($job->id); // Execution[]

// Delete a job
$verne->clockwork()->jobs()->delete($job->id);
```

### Delayed Jobs

One-off jobs that fire once at a specific time:

```php
// Schedule a delayed job
$delayed = $verne->clockwork()->delayed()->create(
    name: 'send-reminder',
    runAt: '2026-08-01T09:00:00Z',
    url: 'https://example.com/hooks/reminder',
    method: 'POST',
);

// List scheduled delayed jobs
$verne->clockwork()->delayed()->list(); // DelayedJob[]

// Inspect run history
$verne->clockwork()->delayed()->executions($delayed->id); // Execution[]

// Cancel before it runs
$verne->clockwork()->delayed()->cancel($delayed->id);
```

## Error Handling

All API errors throw a `VerneApiException` with structured fields:

```php
use Vernesoft\Core\Errors\VerneApiException;
use Vernesoft\Core\Errors\VerneException;

try {
    $verne->relay()->messages()->send(eventType: 'ping', payload: []);
} catch (VerneApiException $e) {
    $e->getErrorCode(); // e.g. 'invalid_payload', 'unauthorized'
    $e->getCode();      // HTTP status code
    $e->getRequestId(); // include in support requests
    $e->getMessage();   // human-readable message
} catch (VerneException $e) {
    // network error or timeout
}
```

## Configuration

Both `Verne` and the per-service clients accept an optional `timeoutSeconds` (default `30`):

```php
$verne = new Verne(
    relay: $_ENV['VERNE_RELAY_KEY'],
    timeoutSeconds: 10,
);
```

## License

MIT
