<?php

declare(strict_types=1);

namespace Vernesoft\Tests;

use Vernesoft\Core\Errors\VerneApiException;
use Vernesoft\Resources\Gate\Types\LifecycleRuleKind;

class GateResourcesTest extends GateTestCase
{
    // --- audit log ---

    public function test_audit_log_list_sends_only_set_filters_and_parses_entries(): void
    {
        $gate = $this->gate([self::json(200, [
            'data' => [[
                'id' => 'a1',
                'occurred_at' => '2026-10-14T10:00:00Z',
                'action' => 'organization.member_added',
                'actor' => ['type' => 'api_key', 'id' => 'k1'],
                'target' => ['type' => 'identity', 'id' => 'u1'],
                'project_id' => 'p1',
                'details' => ['organization_id' => 'o1'],
                'ip' => '203.0.113.7',
                'user_agent' => 'curl/8',
            ], [
                'id' => 'a2',
                'occurred_at' => '2026-10-14T09:00:00Z',
                'action' => 'key.created',
                'actor' => ['type' => 'system', 'id' => null],
                'target' => null,
                'project_id' => null,
                'details' => [],
                'ip' => null,
                'user_agent' => null,
            ]],
            'has_more' => true,
            'next_cursor' => 'n1',
        ])]);

        $page = $gate->auditLog()->list(
            limit: 2,
            action: 'organization.member_added',
            actorType: 'api_key',
            from: new \DateTimeImmutable('2026-10-14T00:00:00+02:00'),
            to: '2026-10-15T00:00:00Z',
        );

        $this->assertRequest('GET', '/v1/gate/audit-log', [
            'limit' => '2',
            'action' => 'organization.member_added',
            'actor_type' => 'api_key',
            'from' => '2026-10-13T22:00:00Z',
            'to' => '2026-10-15T00:00:00Z',
        ]);
        $first = $page->data[0];
        $this->assertSame('api_key', $first->actor->type);
        $this->assertSame('k1', $first->actor->id);
        $this->assertSame('u1', $first->target->id);
        $this->assertSame(['organization_id' => 'o1'], $first->details);
        $this->assertSame('203.0.113.7', $first->ip);
        $this->assertSame('curl/8', $first->userAgent);
        $this->assertNull($page->data[1]->target);
        $this->assertNull($page->data[1]->actor->id);
        $this->assertSame('n1', $page->nextCursor);
    }

    public function test_audit_log_list_without_params_sends_no_query(): void
    {
        $gate = $this->gate([self::json(200, ['data' => [], 'has_more' => false, 'next_cursor' => null])]);

        $gate->auditLog()->list();

        $this->assertSame('', $this->request()->getUri()->getQuery());
    }

    // --- lifecycle ---

    public function test_lifecycle_list_rules(): void
    {
        $rule = static fn (string $kind) => [
            'kind' => $kind, 'configured' => false, 'enabled' => false, 'params' => null, 'available' => $kind === 'delete_unverified',
            'disabled_reason' => null, 'enabled_at' => null, 'updated_at' => null, 'pending' => 0,
        ];
        $gate = $this->gate([self::json(200, ['tier' => 'free', 'rules' => array_map($rule, LifecycleRuleKind::ALL)])]);

        $rules = $gate->lifecycle()->listRules();

        $this->assertRequest('GET', '/v1/gate/lifecycle/rules');
        $this->assertSame('free', $rules->tier);
        $this->assertCount(4, $rules->rules);
        $this->assertTrue($rules->rules[0]->available);
        $this->assertNull($rules->rules[0]->params);
    }

    public function test_lifecycle_set_rule_sends_exactly_enabled_and_params(): void
    {
        $saved = [
            'kind' => 'delete_unverified', 'configured' => true, 'enabled' => true,
            'params' => ['delete_after_days' => 14, 'warn_before_days' => 3], 'available' => true,
            'disabled_reason' => null, 'enabled_at' => '2026-10-14T00:00:00Z', 'updated_at' => '2026-10-14T00:00:00Z', 'pending' => 2,
        ];
        $gate = $this->gate([self::json(200, $saved), self::json(200, $saved), self::json(200, $saved)]);

        $rule = $gate->lifecycle()->setRule(LifecycleRuleKind::DELETE_UNVERIFIED, enabled: true, params: ['delete_after_days' => 14, 'warn_before_days' => 3]);
        $gate->lifecycle()->setRule('delete_unverified', enabled: false);
        $gate->lifecycle()->setRule('expire_roles', enabled: true, params: []);

        $this->assertRequest('PUT', '/v1/gate/lifecycle/rules/delete_unverified');
        $this->assertSame('{"enabled":true,"params":{"delete_after_days":14,"warn_before_days":3}}', $this->rawBody(0));
        $this->assertSame('{"enabled":false}', $this->rawBody(1));
        $this->assertSame('{"enabled":true,"params":{}}', $this->rawBody(2));
        $this->assertSame(2, $rule->pending);
        $this->assertSame(14, $rule->params['delete_after_days']);
    }

    public function test_lifecycle_preview_wraps_params_and_sends_an_object_for_expire_roles(): void
    {
        $preview = ['kind' => 'expire_roles', 'available' => false, 'matching_now' => 3, 'capped' => false];
        $gate = $this->gate([self::json(200, $preview), self::json(200, $preview)]);

        $result = $gate->lifecycle()->previewRule('expire_roles', []);
        $gate->lifecycle()->previewRule('remind_unverified', ['remind_after_hours' => 24]);

        $this->assertRequest('POST', '/v1/gate/lifecycle/rules/expire_roles/preview');
        $this->assertSame('{"params":{}}', $this->rawBody(0));
        $this->assertSame('{"params":{"remind_after_hours":24}}', $this->rawBody(1));
        $this->assertSame(3, $result->matchingNow);
        $this->assertFalse($result->available);
    }

    public function test_lifecycle_delete_rule_and_list_actions(): void
    {
        $gate = $this->gate([
            self::json(204, null),
            self::json(200, ['data' => [[
                'id' => 'la1', 'kind' => 'delete_unverified', 'step' => 'warn', 'identity_id' => 'u1', 'status' => 'pending',
                'basis_at' => '2026-10-01T00:00:00Z', 'due_at' => '2026-10-12T00:00:00Z', 'reason' => null,
                'created_at' => '2026-10-01T00:00:00Z', 'finished_at' => null,
            ]], 'has_more' => false, 'next_cursor' => null]),
        ]);

        $gate->lifecycle()->deleteRule('delete_unverified');
        $page = $gate->lifecycle()->listActions(status: 'pending', identityId: 'u1', limit: 5);

        $this->assertRequest('DELETE', '/v1/gate/lifecycle/rules/delete_unverified');
        $this->assertRequest('GET', '/v1/gate/lifecycle/actions', ['status' => 'pending', 'identity_id' => 'u1', 'limit' => '5'], 1);
        $this->assertSame('warn', $page->data[0]->step);
        $this->assertSame('u1', $page->data[0]->identityId);
        $this->assertNull($page->data[0]->finishedAt);
    }

    // --- sessions ---

    public function test_sessions_list_revoke_revoke_all(): void
    {
        $gate = $this->gate([
            self::json(200, [[
                'id' => 's1', 'active' => true, 'authenticated_at' => '2026-10-14T00:00:00Z', 'expires_at' => '2026-10-15T00:00:00Z',
                'issued_at' => '2026-10-14T00:00:00Z', 'authenticator_assurance_level' => 'aal1',
                'authentication_methods' => [['method' => 'password', 'completed_at' => '2026-10-14T00:00:00Z']],
                'devices' => [['id' => 'd1', 'ip_address' => '203.0.113.7', 'user_agent' => 'ua', 'location' => '']],
                'tokenized' => null,
            ]]),
            self::json(204, null),
            self::json(204, null),
        ]);

        $sessions = $gate->sessions()->list('u1');
        $gate->sessions()->revoke('s 1');
        $gate->sessions()->revokeAll('u1');

        $this->assertRequest('GET', '/v1/gate/identities/u1/sessions');
        $this->assertSame('s1', $sessions[0]->id);
        $this->assertTrue($sessions[0]->active);
        $this->assertSame('aal1', $sessions[0]->authenticatorAssuranceLevel);
        $this->assertSame('password', $sessions[0]->authenticationMethods[0]['method']);
        $this->assertSame('203.0.113.7', $sessions[0]->devices[0]['ip_address']);
        $this->assertArrayHasKey('tokenized', $sessions[0]->raw);
        $this->assertRequest('DELETE', '/v1/gate/sessions/s%201', [], 1);
        $this->assertRequest('DELETE', '/v1/gate/identities/u1/sessions', [], 2);
    }

    public function test_sessions_check_sends_the_session_token_header(): void
    {
        $gate = $this->gate([self::json(200, [
            'active' => true, 'aal' => 'aal1', 'mfa_required' => false, 'mfa_satisfied' => true, 'identity_id' => 'u1',
            'orgs' => [['id' => 'o1', 'role' => 'admin', 'permissions' => ['invoices:write']]],
        ])]);

        $check = $gate->sessions()->check('ory_st_abc');

        $this->assertRequest('GET', '/v1/gate/auth/session');
        $this->assertSame('ory_st_abc', $this->request()->getHeaderLine('X-Session-Token'));
        $this->assertSame('Bearer '.self::KEY, $this->request()->getHeaderLine('Authorization'));
        $this->assertTrue($check->mfaSatisfied);
        $this->assertSame('u1', $check->identityId);
        $this->assertSame('admin', $check->orgs[0]->role);
        $this->assertFalse($check->orgsTruncated);
    }

    public function test_sessions_check_dead_session_is_401(): void
    {
        $gate = $this->gate([self::json(401, ['error' => ['code' => 'unauthorized', 'message' => 'That session is not active.', 'request_id' => 'r']])]);

        try {
            $gate->sessions()->check('dead');
            $this->fail('expected 401');
        } catch (VerneApiException $e) {
            $this->assertSame(401, $e->getCode());
            $this->assertSame('unauthorized', $e->getErrorCode());
        }
    }

    // --- metadata ---

    public function test_metadata_get_and_update_unwrap_metadata_public(): void
    {
        $gate = $this->gate([
            self::json(200, ['metadata_public' => null]),
            self::json(200, ['metadata_public' => ['plan' => 'pro']]),
            self::json(200, ['metadata_public' => ['plan' => 'pro']]),
        ]);

        $this->assertSame([], $gate->identities()->getMetadata('u1'));
        $merged = $gate->identities()->updateMetadata('u1', ['plan' => 'pro', 'seats' => null]);
        $gate->identities()->updateMetadata('u1', []);

        $this->assertRequest('GET', '/v1/gate/identities/u1/metadata');
        $this->assertRequest('PATCH', '/v1/gate/identities/u1/metadata', [], 1);
        $this->assertSame('{"plan":"pro","seats":null}', $this->rawBody(1));
        $this->assertSame(['plan' => 'pro'], $merged);
        $this->assertSame('{}', $this->rawBody(2));
    }

    // --- signed tokens: mint ---

    public function test_create_signed_calls_whoami_with_the_session_and_no_api_key(): void
    {
        $gate = $this->gate([self::json(200, [
            'id' => 's1', 'active' => true, 'identity' => ['id' => 'u1'], 'tokenized' => 'eyJ.a.b',
        ])]);

        $signed = $gate->tokens()->createSigned('ory_st_abc');

        $this->assertRequest('GET', '/auth/sessions/whoami', ['tokenize_as' => 'gate_jwt']);
        $this->assertFalse($this->request()->hasHeader('Authorization'));
        $this->assertSame('ory_st_abc', $this->request()->getHeaderLine('X-Session-Token'));
        $this->assertSame('application/json', $this->request()->getHeaderLine('Accept'));
        $this->assertSame('eyJ.a.b', $signed->token);
        $this->assertSame('s1', $signed->session->id);
        $this->assertArrayNotHasKey('tokenized', $signed->session->raw);
    }

    public function test_create_signed_dead_session_is_a_401_with_code_unknown(): void
    {
        $gate = $this->gate([self::json(401, ['error' => ['code' => 401, 'status' => 'Unauthorized', 'message' => 'No valid session credentials found.']])]);

        try {
            $gate->tokens()->createSigned('dead');
            $this->fail('expected 401');
        } catch (VerneApiException $e) {
            $this->assertSame(401, $e->getCode());
            $this->assertSame('unknown', $e->getErrorCode());
        }
    }

    // --- introspection ---

    public function test_introspect_inactive_has_only_active(): void
    {
        $gate = $this->gate([self::json(200, ['active' => false])]);

        $info = $gate->tokens()->introspect('gat_live_nope');

        $this->assertFalse($info->active);
        $this->assertNull($info->subject);
        $this->assertNull($info->tenantId);
        $this->assertNull($info->scopes);
        $this->assertNull($info->expiresAt);
        $this->assertNull($info->orgs);
    }

    public function test_introspect_parses_orgs_and_truncation(): void
    {
        $gate = $this->gate([self::json(200, [
            'active' => true, 'subject' => 'u1', 'tenant_id' => 't1', 'scopes' => [], 'expires_at' => '2026-10-14T01:00:00Z',
            'orgs' => [['id' => 'o1', 'role' => 'admin', 'permissions' => ['a:b']]], 'orgs_truncated' => true,
        ])]);

        $info = $gate->tokens()->introspect('gat_live_x');

        $this->assertSame('o1', $info->orgs[0]->id);
        $this->assertTrue($info->orgsTruncated);
    }

    // --- flows, return URLs, OIDC exchange ---

    public function test_create_login_flow_with_return_to_state_and_step_up(): void
    {
        $gate = $this->gate([self::json(200, ['id' => 'f1']), self::json(200, ['id' => 'f2'])]);

        $gate->createLoginFlow(returnTo: 'https://app.example.com/cb', state: 'xyz~1');
        $gate->createLoginFlow(aal: 'aal2', sessionToken: 'ory_st_abc');

        $this->assertRequest('GET', '/v1/gate/auth/login', ['return_to' => 'https://app.example.com/cb', 'state' => 'xyz~1']);
        $this->assertFalse($this->request()->hasHeader('X-Session-Token'));
        $this->assertRequest('GET', '/v1/gate/auth/login', ['aal' => 'aal2'], 1);
        $this->assertSame('ory_st_abc', $this->request(1)->getHeaderLine('X-Session-Token'));
        $this->assertStringNotContainsString('ory_st_abc', (string) $this->request(1)->getUri());
    }

    public function test_create_registration_flow(): void
    {
        $gate = $this->gate([self::json(200, ['id' => 'f1'])]);

        $flow = $gate->createRegistrationFlow(state: 's1');

        $this->assertRequest('GET', '/v1/gate/auth/registration', ['state' => 's1']);
        $this->assertSame('f1', $flow['id']);
    }

    public function test_exchange_oidc_code(): void
    {
        $gate = $this->gate([self::json(200, ['session_token' => 'ory_st_new', 'session' => ['id' => 's9', 'active' => true]])]);

        $result = $gate->exchangeOidcCode('gxc_abc');

        $this->assertRequest('POST', '/v1/gate/auth/oidc/exchange');
        $this->assertSame(['code' => 'gxc_abc'], $this->body());
        $this->assertSame('ory_st_new', $result->sessionToken);
        $this->assertSame('s9', $result->session->id);
    }

    public function test_return_urls_get_and_update(): void
    {
        $gate = $this->gate([
            self::json(200, ['return_urls' => []]),
            self::json(200, ['return_urls' => ['https://app.example.com/cb']]),
        ]);

        $this->assertSame([], $gate->settings()->getReturnUrls());
        $urls = $gate->settings()->updateReturnUrls(['https://app.example.com/cb']);

        $this->assertRequest('GET', '/v1/gate/settings/return-urls');
        $this->assertRequest('PUT', '/v1/gate/settings/return-urls', [], 1);
        $this->assertSame(['return_urls' => ['https://app.example.com/cb']], $this->body(1));
        $this->assertSame(['https://app.example.com/cb'], $urls);
    }
}
