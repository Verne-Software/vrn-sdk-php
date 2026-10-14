<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

/**
 * The `reason` values of an organization permission check
 * (`resource: "organization:<id>"`). For any other resource `reason` is a sentence for
 * people, not one of these.
 */
final class AuthorizeReason
{
    public const MEMBER_WITH_PERMISSION = 'member_with_permission';

    public const MEMBER_WITHOUT_PERMISSION = 'member_without_permission';

    public const NOT_A_MEMBER = 'not_a_member';

    public const SUBJECT_INACTIVE = 'subject_inactive';

    public const NO_SUCH_ORGANIZATION = 'no_such_organization';

    private function __construct() {}
}
