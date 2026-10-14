<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate\Types;

/**
 * The four automatic-deletion rules, and the settings (`params`) each takes:
 *
 * - `delete_unverified`: `['delete_after_days' => 2..365, 'warn_before_days' => 1..delete_after_days-1]`
 * - `remind_unverified`: `['remind_after_hours' => 1..720]`
 * - `expire_roles`: `[]` (no settings)
 * - `delete_inactive`: `['inactive_after_months' => 1..120, 'warn_before_days' => 7..90, 'include_without_address' => bool (optional)]`
 */
final class LifecycleRuleKind
{
    public const DELETE_UNVERIFIED = 'delete_unverified';

    public const REMIND_UNVERIFIED = 'remind_unverified';

    public const EXPIRE_ROLES = 'expire_roles';

    public const DELETE_INACTIVE = 'delete_inactive';

    public const ALL = [
        self::DELETE_UNVERIFIED,
        self::REMIND_UNVERIFIED,
        self::EXPIRE_ROLES,
        self::DELETE_INACTIVE,
    ];

    private function __construct() {}
}
