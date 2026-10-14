<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

/**
 * The `reason` values of `authorize()`. An organization permission check
 * (`resource: "organization:<id>"`) answers with the first five; any other resource asks
 * only *is `subject` one of your active end users?* and answers with `OWNED_BY_TENANT`
 * (the one `allowed: true`), `SUBJECT_INACTIVE`, `NOT_OWNED_BY_TENANT` or
 * `NOT_A_KNOWN_IDENTITY`. The set is open: treat a value you do not know as a denial.
 */
final class AuthorizeReason
{
    public const MEMBER_WITH_PERMISSION = 'member_with_permission';

    public const MEMBER_WITHOUT_PERMISSION = 'member_without_permission';

    public const NOT_A_MEMBER = 'not_a_member';

    public const SUBJECT_INACTIVE = 'subject_inactive';

    public const NO_SUCH_ORGANIZATION = 'no_such_organization';

    /** Any other resource: the subject is an active end user of your account. */
    public const OWNED_BY_TENANT = 'owned_by_tenant';

    /** Any other resource: the subject is an identity, and it is another account's. */
    public const NOT_OWNED_BY_TENANT = 'not_owned_by_tenant';

    /** Any other resource: nothing has that id (missing, deleted, or not an id at all). */
    public const NOT_A_KNOWN_IDENTITY = 'not_a_known_identity';

    private function __construct() {}
}
