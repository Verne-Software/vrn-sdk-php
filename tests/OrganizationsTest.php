<?php

declare(strict_types=1);

namespace Vernesoft\Tests;

use Vernesoft\Core\Errors\VerneApiException;
use Vernesoft\Resources\Gate\Types\AuthorizeReason;

class OrganizationsTest extends GateTestCase
{
    private const ORG = ['id' => 'o1', 'name' => 'Acme', 'created_at' => '2026-10-01T00:00:00Z', 'updated_at' => '2026-10-02T00:00:00Z'];

    private const ROLE = [
        'id' => 'r1', 'organization_id' => 'o1', 'name' => 'admin',
        'permissions' => ['invoices:read', 'invoices:write'],
        'created_at' => '2026-10-01T00:00:00Z', 'updated_at' => '2026-10-01T00:00:00Z',
    ];

    private const MEMBER = [
        'organization_id' => 'o1', 'identity_id' => 'u1', 'role_id' => 'r1', 'expires_at' => null,
        'created_at' => '2026-10-01T00:00:00Z', 'updated_at' => '2026-10-01T00:00:00Z',
    ];

    // --- organizations ---

    public function test_create_posts_the_name(): void
    {
        $gate = $this->gate([self::json(201, self::ORG)]);

        $org = $gate->organizations()->create(name: 'Acme');

        $this->assertRequest('POST', '/v1/gate/organizations');
        $this->assertSame(['name' => 'Acme'], $this->body());
        $this->assertSame('o1', $org->id);
        $this->assertSame('Acme', $org->name);
        $this->assertSame('2026-10-02T00:00:00Z', $org->updatedAt);
        $this->assertSame('Bearer '.self::KEY, $this->request()->getHeaderLine('Authorization'));
    }

    public function test_list_is_cursor_paged_and_drops_unset_params(): void
    {
        $gate = $this->gate([
            self::json(200, ['data' => [self::ORG], 'has_more' => true, 'next_cursor' => 'c2']),
            self::json(200, ['data' => [], 'has_more' => false, 'next_cursor' => null]),
        ]);

        $page = $gate->organizations()->list(limit: 1);
        $last = $gate->organizations()->list(cursor: 'c2');

        $this->assertRequest('GET', '/v1/gate/organizations', ['limit' => '1']);
        $this->assertRequest('GET', '/v1/gate/organizations', ['cursor' => 'c2'], 1);
        $this->assertSame('o1', $page->data[0]->id);
        $this->assertTrue($page->hasMore);
        $this->assertSame('c2', $page->nextCursor);
        $this->assertFalse($last->hasMore);
        $this->assertNull($last->nextCursor);
    }

    public function test_get_update_delete_encode_the_id(): void
    {
        $gate = $this->gate([self::json(200, self::ORG), self::json(200, self::ORG), self::json(204, null)]);

        $gate->organizations()->get('o/1');
        $gate->organizations()->update('o1', name: 'Acme Corp');
        $gate->organizations()->delete('o1');

        $this->assertSame('/v1/gate/organizations/o%2F1', $this->request()->getUri()->getPath());
        $this->assertRequest('PATCH', '/v1/gate/organizations/o1', [], 1);
        $this->assertSame(['name' => 'Acme Corp'], $this->body(1));
        $this->assertRequest('DELETE', '/v1/gate/organizations/o1', [], 2);
    }

    // --- authorize / can ---

    public function test_authorize_builds_the_organization_resource(): void
    {
        $gate = $this->gate([self::json(200, [
            'allowed' => true, 'decision_id' => 'dec_1', 'reason' => 'member_with_permission', 'role' => 'admin',
        ])]);

        $decision = $gate->organizations()->authorize('o1', 'u1', 'invoices:write');

        $this->assertRequest('POST', '/v1/gate/authorize');
        $this->assertSame(['subject' => 'u1', 'action' => 'invoices:write', 'resource' => 'organization:o1'], $this->body());
        $this->assertTrue($decision->allowed);
        $this->assertSame(AuthorizeReason::MEMBER_WITH_PERMISSION, $decision->reason);
        $this->assertSame('admin', $decision->role);
    }

    public function test_can_returns_the_boolean(): void
    {
        $gate = $this->gate([
            self::json(200, ['allowed' => false, 'decision_id' => 'dec_2', 'reason' => 'not_a_member']),
            self::json(200, ['allowed' => true, 'decision_id' => 'dec_3', 'reason' => 'member_with_permission', 'role' => 'admin']),
        ]);

        $this->assertFalse($gate->organizations()->can('o1', 'u2', 'invoices:read'));
        $this->assertTrue($gate->organizations()->can('o1', 'u1', 'invoices:read'));
        $this->assertSame('organization:o1', $this->body(1)['resource']);
    }

    public function test_can_propagates_an_invalid_permission_error(): void
    {
        $gate = $this->gate([self::json(400, ['error' => ['code' => 'invalid_payload', 'message' => 'bad permission', 'request_id' => 'r']])]);

        $this->expectException(VerneApiException::class);
        $this->expectExceptionCode(400);

        $gate->organizations()->can('o1', 'u1', 'Invoices:*');
    }

    public function test_reason_other_than_organization_stays_a_sentence_and_role_absent(): void
    {
        $gate = $this->gate([self::json(200, ['allowed' => true, 'decision_id' => 'dec_4', 'reason' => 'subject `u1` is an identity owned by tenant `t`'])]);

        $decision = $gate->authorize(subject: 'u1', action: 'read', resource: 'doc:1');

        $this->assertStringStartsWith('subject', $decision->reason);
        $this->assertNull($decision->role);
    }

    // --- roles ---

    public function test_roles_create_list_get_update_delete(): void
    {
        $gate = $this->gate([
            self::json(201, self::ROLE),
            self::json(200, ['data' => [self::ROLE], 'has_more' => false, 'next_cursor' => null]),
            self::json(200, self::ROLE),
            self::json(200, self::ROLE),
            self::json(204, null),
        ]);
        $roles = $gate->organizations()->roles();

        $role = $roles->create('o1', name: 'admin', permissions: ['invoices:write', 'invoices:read']);
        $page = $roles->list('o1', limit: 50, cursor: 'c');
        $roles->get('o1', 'r1');
        $roles->update('o1', 'r1', permissions: ['invoices:read']);
        $roles->delete('o1', 'r1');

        $this->assertRequest('POST', '/v1/gate/organizations/o1/roles');
        $this->assertSame(['name' => 'admin', 'permissions' => ['invoices:write', 'invoices:read']], $this->body());
        $this->assertSame(['invoices:read', 'invoices:write'], $role->permissions);
        $this->assertSame('o1', $role->organizationId);
        $this->assertRequest('GET', '/v1/gate/organizations/o1/roles', ['limit' => '50', 'cursor' => 'c'], 1);
        $this->assertSame('r1', $page->data[0]->id);
        $this->assertRequest('GET', '/v1/gate/organizations/o1/roles/r1', [], 2);
        $this->assertRequest('PATCH', '/v1/gate/organizations/o1/roles/r1', [], 3);
        $this->assertSame(['permissions' => ['invoices:read']], $this->body(3));
        $this->assertRequest('DELETE', '/v1/gate/organizations/o1/roles/r1', [], 4);
    }

    public function test_role_create_without_permissions_sends_only_the_name(): void
    {
        $gate = $this->gate([self::json(201, self::ROLE)]);

        $gate->organizations()->roles()->create('o1', name: 'viewer');

        $this->assertSame(['name' => 'viewer'], $this->body());
    }

    // --- members ---

    public function test_members_add_serialises_a_datetime_as_rfc3339_utc(): void
    {
        $gate = $this->gate([self::json(201, ['expires_at' => '2027-01-31T22:59:59Z'] + self::MEMBER)]);

        $member = $gate->organizations()->members()->add(
            'o1',
            identityId: 'u1',
            roleId: 'r1',
            expiresAt: new \DateTimeImmutable('2027-01-31T23:59:59+01:00'),
        );

        $this->assertRequest('POST', '/v1/gate/organizations/o1/members');
        $this->assertSame(['identity_id' => 'u1', 'role_id' => 'r1', 'expires_at' => '2027-01-31T22:59:59Z'], $this->body());
        $this->assertSame('2027-01-31T22:59:59Z', $member->expiresAt);
    }

    public function test_members_add_without_deadline_omits_expires_at(): void
    {
        $gate = $this->gate([self::json(201, self::MEMBER)]);

        $member = $gate->organizations()->members()->add('o1', identityId: 'u1', roleId: 'r1');

        $this->assertSame(['identity_id' => 'u1', 'role_id' => 'r1'], $this->body());
        $this->assertNull($member->expiresAt);
    }

    public function test_members_update_tells_absent_null_and_a_time_apart(): void
    {
        $gate = $this->gate([self::json(200, self::MEMBER), self::json(200, self::MEMBER), self::json(200, self::MEMBER)]);
        $members = $gate->organizations()->members();

        $members->update('o1', 'u1', roleId: 'r2');
        $members->update('o1', 'u1', clearExpiresAt: true);
        $members->update('o1', 'u1', expiresAt: '2027-01-01T00:00:00Z');

        $this->assertRequest('PATCH', '/v1/gate/organizations/o1/members/u1');
        $this->assertSame('{"role_id":"r2"}', $this->rawBody(0));
        $this->assertSame('{"expires_at":null}', $this->rawBody(1));
        $this->assertSame('{"expires_at":"2027-01-01T00:00:00Z"}', $this->rawBody(2));
    }

    public function test_members_update_with_both_deadline_arguments_is_refused_locally(): void
    {
        $gate = $this->gate([]);

        $this->expectException(\InvalidArgumentException::class);

        $gate->organizations()->members()->update('o1', 'u1', expiresAt: '2027-01-01T00:00:00Z', clearExpiresAt: true);
    }

    public function test_members_list_and_remove(): void
    {
        $gate = $this->gate([
            self::json(200, ['data' => [self::MEMBER], 'has_more' => false, 'next_cursor' => null]),
            self::json(204, null),
        ]);

        $page = $gate->organizations()->members()->list('o1');
        $gate->organizations()->members()->remove('o1', 'u1');

        $this->assertRequest('GET', '/v1/gate/organizations/o1/members');
        $this->assertSame('u1', $page->data[0]->identityId);
        $this->assertSame('r1', $page->data[0]->roleId);
        $this->assertRequest('DELETE', '/v1/gate/organizations/o1/members/u1', [], 1);
    }

    // --- a user's organizations ---

    public function test_identities_list_organizations_returns_org_claims(): void
    {
        $gate = $this->gate([self::json(200, [
            'data' => [['id' => 'o1', 'role' => 'admin', 'permissions' => ['invoices:read']]],
            'has_more' => false,
            'next_cursor' => null,
        ])]);

        $page = $gate->identities()->listOrganizations('u1', limit: 100);

        $this->assertRequest('GET', '/v1/gate/identities/u1/organizations', ['limit' => '100']);
        $this->assertSame('o1', $page->data[0]->id);
        $this->assertSame('admin', $page->data[0]->role);
        $this->assertSame(['invoices:read'], $page->data[0]->permissions);
    }
}
