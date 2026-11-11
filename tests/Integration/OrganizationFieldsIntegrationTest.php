<?php

declare(strict_types=1);

namespace Vernesoft\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Vernesoft\Core\Errors\ErrorReason;
use Vernesoft\Core\Errors\VerneApiException;
use Vernesoft\Gate;
use Vernesoft\Resources\Gate\Types\Identity;
use Vernesoft\Resources\Gate\Types\Organization;
use Vernesoft\Resources\Gate\Types\Role;
use Vernesoft\Verne;

/**
 * 1.6.0 against a real stack: what the organization routes say since Nautilus v1.10.44 —
 * `memberCount` on organizations and roles, `email` and `displayName` on members, `total`
 * on the three lists, the reason of their 404s and 409s, and the audit entries for
 * creating and renaming an organization. Same environment as GateIntegrationTest; it
 * deletes everything it creates. The tests run in order and share state.
 */
class OrganizationFieldsIntegrationTest extends TestCase
{
    private static ?string $key = null;

    private static Gate $gate;

    /** @var array<callable(): mixed> */
    private static array $cleanup = [];

    private static Identity $alice;

    private static Identity $bob;

    private static Organization $org;

    private static Role $admin;

    private static Role $viewer;

    public static function setUpBeforeClass(): void
    {
        self::$key = IntegrationEnv::key();
        if (self::$key === null) {
            return;
        }
        self::$gate = (new Verne(baseUrl: IntegrationEnv::baseUrl(), apiKey: self::$key))->gate();

        self::$alice = self::$gate->identities()->create(schemaId: 'user', traits: ['email' => self::email('alice')]);
        $aliceId = self::$alice->id;
        self::$cleanup[] = fn () => self::$gate->identities()->delete($aliceId);
        self::$bob = self::$gate->identities()->create(schemaId: 'user', traits: ['email' => self::email('bob')]);
        $bobId = self::$bob->id;
        self::$cleanup[] = fn () => self::$gate->identities()->delete($bobId);
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
        return IntegrationEnv::prefix().'-'.$name.'@e2e.soft.com';
    }

    private static function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));

        return sprintf('%s-%s-4%s-a%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3), substr($hex, 17, 3), substr($hex, 20, 12));
    }

    /** @return array{int, string, ?string} status, code, reason */
    private function refusal(callable $call): array
    {
        try {
            $call();
        } catch (VerneApiException $e) {
            return [$e->getStatus(), $e->getErrorCode(), $e->getReason()];
        }
        $this->fail('expected the API to refuse');
    }

    public function test_a_new_organization_and_its_roles_count_nobody_and_the_lists_carry_total(): void
    {
        $orgs = self::$gate->organizations();
        $before = $orgs->list(limit: 1)->total;
        self::$org = $orgs->create(name: IntegrationEnv::prefix().' Acme');
        $orgId = self::$org->id;
        self::$cleanup[] = fn () => self::$gate->organizations()->delete($orgId);
        $this->assertSame(0, self::$org->memberCount);

        $after = $orgs->list(limit: 1);
        $this->assertSame($before + 1, $after->total);
        $this->assertCount(1, $after->data);

        self::$admin = $orgs->roles()->create($orgId, name: 'admin', permissions: ['invoices:write']);
        self::$viewer = $orgs->roles()->create($orgId, name: 'viewer');
        $this->assertSame(0, self::$admin->memberCount);

        $roles = $orgs->roles()->list($orgId, limit: 1);
        $this->assertCount(1, $roles->data);
        $this->assertSame(2, $roles->total);
    }

    public function test_a_member_answer_carries_the_persons_email_and_display_name(): void
    {
        $members = self::$gate->organizations()->members();
        $orgId = self::$org->id;

        $added = $members->add($orgId, identityId: self::$alice->id, roleId: self::$admin->id);
        $this->assertSame(self::email('alice'), $added->email);
        // The `user` schema has no name trait: an API-created user has none.
        $this->assertNull($added->displayName);

        $moved = $members->update($orgId, self::$alice->id, roleId: self::$viewer->id);
        $this->assertSame(self::email('alice'), $moved->email);
        $members->update($orgId, self::$alice->id, roleId: self::$admin->id);

        $page = $members->list($orgId);
        $this->assertSame(1, $page->total);
        $this->assertSame([[self::$alice->id, self::email('alice')]], array_map(fn ($m) => [$m->identityId, $m->email], $page->data));
    }

    public function test_member_count_follows_the_memberships(): void
    {
        $orgs = self::$gate->organizations();
        $orgId = self::$org->id;

        $this->assertSame(1, $orgs->get($orgId)->memberCount);
        $this->assertSame(1, $orgs->roles()->get($orgId, self::$admin->id)->memberCount);
        $this->assertSame(0, $orgs->roles()->get($orgId, self::$viewer->id)->memberCount);
        $listed = array_values(array_filter($orgs->list(limit: 100)->data, fn ($o) => $o->id === $orgId));
        $this->assertSame(1, $listed[0]->memberCount);
    }

    public function test_every_409_says_why(): void
    {
        $orgs = self::$gate->organizations();
        $orgId = self::$org->id;

        $this->assertSame([409, 'conflict', ErrorReason::ROLE_NAME_TAKEN], $this->refusal(fn () => $orgs->roles()->create($orgId, name: 'admin')));
        $this->assertSame([409, 'conflict', ErrorReason::ALREADY_MEMBER], $this->refusal(fn () => $orgs->members()->add($orgId, identityId: self::$alice->id, roleId: self::$viewer->id)));
        $this->assertSame([409, 'conflict', ErrorReason::ROLE_HELD], $this->refusal(fn () => $orgs->roles()->delete($orgId, self::$admin->id)));
    }

    public function test_every_404_says_what(): void
    {
        $orgs = self::$gate->organizations();
        $orgId = self::$org->id;

        $this->assertSame([404, 'not_found', ErrorReason::ORGANIZATION], $this->refusal(fn () => $orgs->get(self::uuid())));
        $this->assertSame([404, 'not_found', ErrorReason::ROLE], $this->refusal(fn () => $orgs->roles()->get($orgId, self::uuid())));
        $this->assertSame([404, 'not_found', ErrorReason::MEMBER], $this->refusal(fn () => $orgs->members()->update($orgId, self::$bob->id, roleId: self::$viewer->id)));
        $this->assertSame([404, 'not_found', ErrorReason::END_USER], $this->refusal(fn () => $orgs->members()->add($orgId, identityId: self::uuid(), roleId: self::$viewer->id)));
    }

    public function test_the_audit_log_records_creating_and_renaming_the_organization(): void
    {
        $orgId = self::$org->id;
        $name = IntegrationEnv::prefix().' Acme';

        $renamed = self::$gate->organizations()->update($orgId, name: $name.' Corp');
        $this->assertSame(1, $renamed->memberCount);

        $created = self::$gate->auditLog()->list(action: 'organization.created', targetId: $orgId);
        $this->assertCount(1, $created->data);
        $this->assertSame($name, $created->data[0]->details['name']);

        $updated = self::$gate->auditLog()->list(action: 'organization.updated', targetId: $orgId);
        $this->assertCount(1, $updated->data);
        $this->assertSame($name, $updated->data[0]->details['previous_name']);
        $this->assertSame($name.' Corp', $updated->data[0]->details['name']);
    }
}
