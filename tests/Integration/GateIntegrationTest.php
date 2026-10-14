<?php

declare(strict_types=1);

namespace Vernesoft\Tests\Integration;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Vernesoft\Core\Errors\VerneApiException;
use Vernesoft\Core\Errors\VerneTokenException;
use Vernesoft\Gate;
use Vernesoft\GateTokenVerifier;
use Vernesoft\Resources\Gate\Types\AuthorizeReason;
use Vernesoft\Resources\Gate\Types\Identity;
use Vernesoft\Resources\Gate\Types\Organization;
use Vernesoft\Resources\Gate\Types\Role;
use Vernesoft\Verne;

/**
 * Integration suite: every 1.4.0 Gate resource against a real Nautilus stack. Opt-in:
 * `./vendor/bin/phpunit --testsuite Integration`, skipped without `VERNE_API_KEY`.
 *
 *   VERNE_API_KEY=vrn_…                 key with scopes gate, clockwork, passepartout (required)
 *   VERNE_BASE_URL=http://api.soft.com  default; any other host needs VERNE_ALLOW_REMOTE=1
 *   VERNE_EMAIL_PREFIX=e2e-sdkphp-<run> every end user created is <prefix>-<n>@e2e.soft.com
 *   VERNE_NARROW_KEY=vrn_…              optional: a key WITHOUT the gate scope (403 check)
 *
 * It creates end users, an organization with roles and members, changes the tenant's
 * security settings and return URLs for a moment, and removes all of it again. The tests
 * run in order and share state. Run it against a local stack, never production.
 */
class GateIntegrationTest extends TestCase
{
    private static ?string $key = null;

    private static string $baseUrl;

    private static string $prefix;

    private static Gate $gate;

    /** @var array<callable(): mixed> */
    private static array $cleanup = [];

    private static string $tenantId;

    private static Identity $member;

    private static Identity $outsider;

    private static string $memberPassword;

    private static Organization $org;

    private static Role $admin;

    private static Role $viewer;

    public static function setUpBeforeClass(): void
    {
        self::$key = IntegrationEnv::key();
        if (self::$key === null) {
            return;
        }
        self::$baseUrl = IntegrationEnv::baseUrl();
        self::$prefix = IntegrationEnv::prefix();
        self::$gate = (new Verne(baseUrl: self::$baseUrl, apiKey: self::$key))->gate();

        self::$memberPassword = 'Pw-'.bin2hex(random_bytes(6)).'-Zq9!x';
        self::$member = self::$gate->identities()->create(
            schemaId: 'user',
            traits: ['email' => self::email('member')],
            credentials: ['password' => ['config' => ['password' => self::$memberPassword]]],
        );
        $memberId = self::$member->id;
        self::$cleanup[] = fn () => self::$gate->identities()->delete($memberId);
        self::$outsider = self::$gate->identities()->create(schemaId: 'user', traits: ['email' => self::email('outsider')]);
        $outsiderId = self::$outsider->id;
        self::$cleanup[] = fn () => self::$gate->identities()->delete($outsiderId);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (array_reverse(self::$cleanup) as $step) {
            try {
                $step();
            } catch (\Throwable) {
                // already gone
            }
        }
        self::$cleanup = [];
    }

    protected function setUp(): void
    {
        if (self::$key === null) {
            $this->markTestSkipped('VERNE_API_KEY is not set; the integration suite is opt-in.');
        }
    }

    private static function email(string $name): string
    {
        return self::$prefix.'-'.$name.'@e2e.soft.com';
    }

    private function apiError(callable $call, int $status, ?string $code = null): VerneApiException
    {
        try {
            $call();
        } catch (VerneApiException $e) {
            $this->assertSame($status, $e->getCode(), 'status; message: '.$e->getMessage());
            if ($code !== null) {
                $this->assertSame($code, $e->getErrorCode());
            }

            return $e;
        }
        $this->fail("expected a {$status}");
    }

    /** A password login through the flow's `ui.action` — the part an SDK leaves to the browser. */
    private function passwordLogin(string $identifier, string $secret): string
    {
        $flow = self::$gate->createLoginFlow();
        $res = (new Client(['http_errors' => false]))->post($flow['ui']['action'], [
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body' => json_encode(['method' => 'password', 'identifier' => $identifier, 'password' => $secret]),
        ]);
        $body = json_decode((string) $res->getBody(), true);
        $this->assertSame(200, $res->getStatusCode(), 'password login');

        return $body['session_token'];
    }

    public function test_tokens_create_authenticates_with_the_header(): void
    {
        $token = self::$gate->tokens()->create(subject: 'sdk-php-integration');

        $this->assertStringStartsWith('gat_', $token->accessToken);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $token->tenantId);
        self::$tenantId = $token->tenantId;
    }

    public function test_a_key_without_the_gate_scope_gets_the_standard_403(): void
    {
        $narrow = getenv('VERNE_NARROW_KEY');
        if (! $narrow) {
            $this->markTestSkipped('VERNE_NARROW_KEY is not set.');
        }
        $gate = new Gate(apiKey: $narrow, baseUrl: self::$baseUrl);

        $e = $this->apiError(fn () => $gate->organizations()->list(), 403, 'forbidden');

        $this->assertSame('This API key is not authorized for Gate.', $e->getMessage());
        $this->assertStringStartsWith('req_', (string) $e->getRequestId());
    }

    public function test_409_is_you_already_have_this_end_user(): void
    {
        $this->apiError(
            fn () => self::$gate->identities()->create(schemaId: 'user', traits: ['email' => self::email('member')]),
            409,
            'conflict',
        );
    }

    public function test_custom_data_trait_is_refused(): void
    {
        $this->apiError(
            fn () => self::$gate->identities()->create(schemaId: 'user', traits: ['email' => self::email('cd'), 'custom_data' => ['a' => 1]]),
            400,
            'invalid_payload',
        );
    }

    public function test_custom_metadata_read_merge_delete_a_key(): void
    {
        $id = self::$member->id;

        $this->assertSame([], self::$gate->identities()->getMetadata($id));
        $this->assertEquals(['plan' => 'pro', 'seats' => 5], self::$gate->identities()->updateMetadata($id, ['plan' => 'pro', 'seats' => 5]));
        $this->assertSame(['plan' => 'pro'], self::$gate->identities()->updateMetadata($id, ['seats' => null]));
    }

    public function test_organizations_create_list_get_rename(): void
    {
        $org = self::$gate->organizations()->create(name: 'Acme');
        $orgId = $org->id;
        self::$cleanup[] = fn () => self::$gate->organizations()->delete($orgId);
        $this->assertSame('Acme', $org->name);

        $listed = self::$gate->organizations()->list(limit: 100);
        $this->assertContains($org->id, array_map(fn ($o) => $o->id, $listed->data));

        $this->assertSame($org->id, self::$gate->organizations()->get($org->id)->id);
        self::$org = self::$gate->organizations()->update($org->id, name: 'Acme Corp');
        $this->assertSame('Acme Corp', self::$org->name);

        $this->apiError(fn () => self::$gate->organizations()->create(name: ''), 400, 'invalid_payload');
    }

    public function test_roles_create_list_get_update_and_names_are_unique(): void
    {
        $roles = self::$gate->organizations()->roles();
        $orgId = self::$org->id;

        self::$admin = $roles->create($orgId, name: 'admin', permissions: ['invoices:write', 'invoices:read', 'invoices:read']);
        $this->assertSame(['invoices:read', 'invoices:write'], self::$admin->permissions);
        $viewer = $roles->create($orgId, name: 'viewer');
        self::$viewer = $roles->update($orgId, $viewer->id, permissions: ['invoices:read']);
        $this->assertSame(['invoices:read'], self::$viewer->permissions);

        $page = $roles->list($orgId, limit: 1);
        $this->assertCount(1, $page->data);
        $this->assertTrue($page->hasMore);
        $next = $roles->list($orgId, limit: 1, cursor: $page->nextCursor);
        $this->assertNotSame($page->data[0]->id, $next->data[0]->id);

        $this->assertSame('admin', $roles->get($orgId, self::$admin->id)->name);
        $this->apiError(fn () => $roles->create($orgId, name: 'admin'), 409, 'conflict');
        $this->apiError(fn () => $roles->create($orgId, name: 'Admin'), 400, 'invalid_payload');
    }

    public function test_members_add_list_deadline_and_no_second_membership(): void
    {
        $members = self::$gate->organizations()->members();
        $orgId = self::$org->id;
        $memberId = self::$member->id;

        $added = $members->add($orgId, identityId: $memberId, roleId: self::$admin->id);
        $this->assertNull($added->expiresAt);
        $this->apiError(fn () => $members->add($orgId, identityId: $memberId, roleId: self::$viewer->id), 409, 'conflict');

        $deadline = (new \DateTimeImmutable('+30 days'))->setTime(12, 0);
        $dated = $members->update($orgId, $memberId, expiresAt: $deadline);
        $this->assertSame($deadline->getTimestamp(), (new \DateTimeImmutable($dated->expiresAt))->getTimestamp());
        $kept = $members->update($orgId, $memberId, roleId: self::$admin->id);
        $this->assertNotNull($kept->expiresAt);
        $cleared = $members->update($orgId, $memberId, clearExpiresAt: true);
        $this->assertNull($cleared->expiresAt);

        $page = $members->list($orgId);
        $this->assertSame([$memberId], array_map(fn ($m) => $m->identityId, $page->data));

        $held = self::$gate->identities()->listOrganizations($memberId);
        $this->assertCount(1, $held->data);
        $this->assertSame($orgId, $held->data[0]->id);
        $this->assertSame('admin', $held->data[0]->role);
        $this->assertSame(['invoices:read', 'invoices:write'], $held->data[0]->permissions);
        $this->assertSame([], self::$gate->identities()->listOrganizations(self::$outsider->id)->data);
    }

    public function test_a_role_somebody_holds_cannot_be_deleted(): void
    {
        $e = $this->apiError(fn () => self::$gate->organizations()->roles()->delete(self::$org->id, self::$admin->id), 409, 'conflict');

        $this->assertMatchesRegularExpression('/held by 1 member/', $e->getMessage());
    }

    public function test_authorize_and_can_every_reason(): void
    {
        $orgs = self::$gate->organizations();
        $orgId = self::$org->id;
        $memberId = self::$member->id;

        $yes = $orgs->authorize($orgId, $memberId, 'invoices:write');
        $this->assertTrue($yes->allowed);
        $this->assertSame('member_with_permission', $yes->reason);
        $this->assertSame('admin', $yes->role);
        $this->assertStringStartsWith('dec_', $yes->decisionId);

        $no = $orgs->authorize($orgId, $memberId, 'members:invite');
        $this->assertFalse($no->allowed);
        $this->assertSame('member_without_permission', $no->reason);
        $this->assertSame('admin', $no->role);

        $stranger = $orgs->authorize($orgId, self::$outsider->id, 'invoices:read');
        $this->assertSame('not_a_member', $stranger->reason);
        $this->assertNull($stranger->role);

        $missing = $orgs->authorize(self::uuid(), $memberId, 'invoices:read');
        $this->assertSame('no_such_organization', $missing->reason);

        self::$gate->identities()->deactivate($memberId);
        try {
            $inactive = $orgs->authorize($orgId, $memberId, 'invoices:write');
            $this->assertFalse($inactive->allowed);
            $this->assertSame('subject_inactive', $inactive->reason);
            $this->assertSame('admin', $inactive->role);
        } finally {
            self::$gate->identities()->activate($memberId);
        }

        $this->assertTrue($orgs->can($orgId, $memberId, 'invoices:write'));
        $this->assertFalse($orgs->can($orgId, $memberId, 'members:invite'));
        $this->apiError(fn () => $orgs->can($orgId, $memberId, 'Invoices:*'), 400, 'invalid_payload');

        // The plain ownership check: a code in `reason`, the sentence in `message`.
        $owned = self::$gate->authorize(subject: $memberId, action: 'read', resource: 'doc:1');
        $this->assertTrue($owned->allowed);
        $this->assertSame(AuthorizeReason::OWNED_BY_TENANT, $owned->reason);
        $this->assertMatchesRegularExpression('/is an identity owned by tenant/', (string) $owned->message);
        $nobody = self::$gate->authorize(subject: self::uuid(), action: 'read', resource: 'doc:1');
        $this->assertFalse($nobody->allowed);
        $this->assertSame(AuthorizeReason::NOT_A_KNOWN_IDENTITY, $nobody->reason);
        $notAnId = self::$gate->authorize(subject: 'usr_123', action: 'read', resource: 'doc:1');
        $this->assertSame(AuthorizeReason::NOT_A_KNOWN_IDENTITY, $notAnId->reason);
        $outsiderId = self::$outsider->id;
        self::$gate->identities()->deactivate($outsiderId);
        try {
            $off = self::$gate->authorize(subject: $outsiderId, action: 'read', resource: 'doc:1');
            $this->assertFalse($off->allowed);
            $this->assertSame(AuthorizeReason::SUBJECT_INACTIVE, $off->reason);
        } finally {
            self::$gate->identities()->activate($outsiderId);
        }
    }

    public function test_a_malformed_token_request_is_invalid_payload_with_a_request_id(): void
    {
        $e = $this->apiError(fn () => self::$gate->tokens()->create(subject: ''), 400, 'invalid_payload');
        $this->assertStringStartsWith('req_', (string) $e->getRequestId());
    }

    public function test_identities_no_longer_carry_the_owner_stamp(): void
    {
        $read = self::$gate->identities()->get(self::$member->id);
        $this->assertStringNotContainsString('verne_tenant_id', (string) json_encode($read->raw));
        $this->assertNull($read->traits->tenantId);
    }

    public function test_introspection_lists_the_end_users_organizations(): void
    {
        $token = self::$gate->tokens()->create(subject: self::$member->id);
        $info = self::$gate->tokens()->introspect($token->accessToken);

        $this->assertTrue($info->active);
        $this->assertCount(1, $info->orgs);
        $this->assertSame(self::$org->id, $info->orgs[0]->id);
        $this->assertSame(['invoices:read', 'invoices:write'], $info->orgs[0]->permissions);

        $inactive = self::$gate->tokens()->introspect('gat_live_not_a_token');
        $this->assertFalse($inactive->active);
        $this->assertNull($inactive->subject);
        $this->assertNull($inactive->orgs);
    }

    public function test_sessions_check_mfa_signed_token_verify_revoke(): void
    {
        $sessionToken = $this->passwordLogin(self::email('member'), self::$memberPassword);

        $check = self::$gate->sessions()->check($sessionToken);
        $this->assertTrue($check->active);
        $this->assertSame(self::$member->id, $check->identityId);
        $this->assertTrue($check->mfaSatisfied);
        $this->assertSame(self::$org->id, $check->orgs[0]->id);
        $this->assertSame('admin', $check->orgs[0]->role);

        $security = self::$gate->settings()->getSecurity();
        self::$gate->settings()->updateSecurity(passwordlessEnabled: $security->passwordlessEnabled, mfaEnabled: true);
        try {
            $e = $this->apiError(fn () => self::$gate->sessions()->check($sessionToken), 403, 'mfa_required');
            $this->assertSame('aal2', $e->details['required_aal']);
            $this->assertSame('aal1', $e->details['current_aal']);
        } finally {
            self::$gate->settings()->updateSecurity(passwordlessEnabled: $security->passwordlessEnabled, mfaEnabled: $security->mfaEnabled);
        }

        $signed = self::$gate->tokens()->createSigned($sessionToken);
        $this->assertNotSame('', $signed->session->id);
        $claims = self::$gate->tokens()->verifySigned($signed->token, tenantId: self::$tenantId);
        $this->assertSame(self::$member->id, $claims->sub);
        $this->assertSame(self::$tenantId, $claims->tenantId);
        $this->assertSame('authenticated', $claims->role);
        $this->assertSame($signed->session->id, $claims->sid);
        $this->assertSame(self::$org->id, $claims->orgs[0]->id);
        $this->assertSame(['invoices:read', 'invoices:write'], $claims->orgs[0]->permissions);
        $this->assertSame(self::$baseUrl.'/auth/'.self::$tenantId, $claims->iss);

        // The standalone verifier agrees; another tenant id means another issuer.
        $standalone = new GateTokenVerifier(tenantId: self::$tenantId, baseUrl: self::$baseUrl);
        $this->assertSame(self::$member->id, $standalone->verify($signed->token)->sub);
        $foreign = new GateTokenVerifier(tenantId: self::uuid(), baseUrl: self::$baseUrl);
        try {
            $foreign->verify($signed->token);
            $this->fail('expected wrong_issuer');
        } catch (VerneTokenException $e) {
            $this->assertSame('wrong_issuer', $e->reason);
        }

        $sessions = self::$gate->sessions()->list(self::$member->id);
        $this->assertContains($signed->session->id, array_map(fn ($s) => $s->id, $sessions));

        self::$gate->sessions()->revoke($signed->session->id);
        self::$gate->sessions()->revoke($signed->session->id); // idempotent
        $this->apiError(fn () => self::$gate->tokens()->createSigned($sessionToken), 401);
        $this->apiError(fn () => self::$gate->sessions()->check($sessionToken), 401, 'unauthorized');
        $this->apiError(fn () => self::$gate->sessions()->revoke(self::uuid()), 404, 'not_found');

        // "Log out everywhere" is idempotent: fine for a user who has had a session, for
        // one who never signed in, and twice over.
        self::$gate->sessions()->revokeAll(self::$member->id);
        self::$gate->sessions()->revokeAll(self::$outsider->id);
        self::$gate->sessions()->revokeAll(self::$outsider->id);
        // An id that is not one of your users — unknown, or deleted through the API — is 403.
        $this->apiError(fn () => self::$gate->sessions()->revokeAll(self::uuid()), 403, 'forbidden');
        $gone = self::$gate->identities()->create(schemaId: 'user', traits: ['email' => self::email('gone')]);
        self::$gate->identities()->delete($gone->id);
        $this->apiError(fn () => self::$gate->sessions()->revokeAll($gone->id), 403, 'forbidden');
    }

    public function test_audit_log_records_membership_changes_filtered_and_paged(): void
    {
        $added = self::$gate->auditLog()->list(action: 'organization.member_added', targetId: self::$member->id);
        $this->assertCount(1, $added->data);
        $this->assertSame('organization.member_added', $added->data[0]->action);
        $this->assertSame('api_key', $added->data[0]->actor->type);
        $this->assertSame('identity', $added->data[0]->target->type);
        $this->assertSame(self::$member->id, $added->data[0]->target->id);

        $since = new \DateTimeImmutable('-1 hour');
        $first = self::$gate->auditLog()->list(limit: 1, from: $since);
        $this->assertCount(1, $first->data);
        $this->assertTrue($first->hasMore);
        $second = self::$gate->auditLog()->list(limit: 1, cursor: $first->nextCursor, from: $since);
        $this->assertNotSame($first->data[0]->id, $second->data[0]->id);

        $this->apiError(fn () => self::$gate->auditLog()->list(action: 'nope'), 400, 'invalid_payload');
    }

    public function test_lifecycle_list_set_preview_delete_actions_and_plan_required(): void
    {
        $lifecycle = self::$gate->lifecycle();

        $rules = $lifecycle->listRules();
        $kinds = array_map(fn ($r) => $r->kind, $rules->rules);
        sort($kinds);
        $this->assertSame(['delete_inactive', 'delete_unverified', 'expire_roles', 'remind_unverified'], $kinds);

        $saved = $lifecycle->setRule('delete_unverified', enabled: false, params: ['delete_after_days' => 14, 'warn_before_days' => 3]);
        self::$cleanup[] = fn () => self::$gate->lifecycle()->deleteRule('delete_unverified');
        $this->assertSame('delete_unverified', $saved->kind);
        $this->assertTrue($saved->configured);
        $this->assertFalse($saved->enabled);
        $this->assertSame(['delete_after_days' => 14, 'warn_before_days' => 3], $saved->params);

        $preview = $lifecycle->previewRule('delete_unverified', ['delete_after_days' => 14, 'warn_before_days' => 3]);
        $this->assertSame('delete_unverified', $preview->kind);
        $this->assertFalse($preview->capped);
        $this->assertIsInt($preview->matchingNow);
        $this->assertSame('expire_roles', $lifecycle->previewRule('expire_roles', [])->kind);

        $lifecycle->deleteRule('delete_unverified');
        $this->assertIsArray($lifecycle->listActions(limit: 5)->data);

        if ($rules->tier === 'free') {
            $this->apiError(fn () => $lifecycle->setRule('remind_unverified', enabled: true, params: ['remind_after_hours' => 24]), 403, 'plan_required');
        }
        $this->apiError(
            fn () => $lifecycle->setRule('delete_unverified', enabled: true, params: ['delete_after_days' => 1, 'warn_before_days' => 1]),
            400,
            'invalid_payload',
        );
    }

    public function test_return_urls_round_trip_and_drive_the_login_flow(): void
    {
        $settings = self::$gate->settings();
        $before = $settings->getReturnUrls();
        try {
            $urls = $settings->updateReturnUrls(['http://localhost:3000/auth/callback']);
            $this->assertSame(['http://localhost:3000/auth/callback'], $urls);
            $flow = self::$gate->createLoginFlow(returnTo: 'http://localhost:3000/auth/callback', state: 'sdk-php-integration-1');
            $this->assertNotEmpty($flow['id']);
            $this->apiError(fn () => self::$gate->createLoginFlow(returnTo: 'https://elsewhere.example/cb'), 400);
            $this->assertNotEmpty(self::$gate->createRegistrationFlow(returnTo: 'http://localhost:3000/auth/callback', state: 's1')['id']);
        } finally {
            $settings->updateReturnUrls($before);
        }
        $this->apiError(fn () => self::$gate->exchangeOidcCode('gxc_not_a_code'), 404);
    }

    public function test_removing_a_member_a_role_and_the_organization(): void
    {
        $orgs = self::$gate->organizations();
        $orgId = self::$org->id;

        $orgs->members()->remove($orgId, self::$member->id);
        $this->assertFalse($orgs->can($orgId, self::$member->id, 'invoices:read'));
        $orgs->roles()->delete($orgId, self::$admin->id);
        $orgs->roles()->delete($orgId, self::$viewer->id);
        $orgs->delete($orgId);
        $this->apiError(fn () => $orgs->get($orgId), 404, 'not_found');
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
