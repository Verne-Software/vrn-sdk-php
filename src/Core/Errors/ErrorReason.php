<?php

declare(strict_types=1);

namespace Vernesoft\Core\Errors;

/**
 * The reasons of a `404 not_found` and a `409 conflict` on the organization routes, in
 * `VerneApiException::getReason()`. The error code is unchanged; the reason says which one
 * of its kind the error is, so code need not read the message. The set is open: keep a
 * branch for a reason you do not know. A role limit hit is `409 limit_reached`, with no
 * reason.
 */
final class ErrorReason
{
    /** `404`: no such organization in the key's project (another project's looks the same). */
    public const ORGANIZATION = 'organization';

    /** `404`: no such role in that organization. */
    public const ROLE = 'role';

    /** `404`: the person is not a member of the organization. */
    public const MEMBER = 'member';

    /** `404`: `identity_id` is not one of your end users. */
    public const END_USER = 'end_user';

    /** `404`: no such project. */
    public const PROJECT = 'project';

    /** `409`: the organization already has a role with that name. */
    public const ROLE_NAME_TAKEN = 'role_name_taken';

    /** `409`: someone holds the role, so it cannot be deleted. */
    public const ROLE_HELD = 'role_held';

    /** `409`: the person is already a member of the organization. */
    public const ALREADY_MEMBER = 'already_member';

    private function __construct() {}
}
