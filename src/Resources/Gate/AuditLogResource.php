<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate;

use Vernesoft\Core\HttpClient;
use Vernesoft\Core\Types\Paginated;
use Vernesoft\Resources\Gate\Types\AuditLogEntry;

/**
 * The account's audit log, read-only: who did what, to what, when and from where. The
 * key reads its own project's entries and the account-wide ones.
 *
 * Any key with the `gate` scope reads the whole log, including the IP addresses and
 * user agents of your team members. Keep `gate` keys on your backend.
 */
class AuditLogResource
{
    public function __construct(private HttpClient $httpClient) {}

    /**
     * One page of entries, newest first. Filters combine; keep the same filters while
     * paging with `$cursor`. An unknown `$action`, a non-UUID `$actorId`, a bad time,
     * `$from` not before `$to` or a bad cursor is `400 invalid_payload`.
     *
     * @param  string|null  $action  one action, e.g. `organization.member_added`, `identity.login`
     * @param  string|null  $actorType  `team_member`, `api_key`, `end_user`, `system` or `staff`
     * @param  string|\DateTimeInterface|null  $from  entries at or after this instant (RFC 3339)
     * @param  string|\DateTimeInterface|null  $to  entries before this instant
     * @return Paginated<AuditLogEntry>
     */
    public function list(
        ?int $limit = null,
        ?string $cursor = null,
        ?string $action = null,
        ?string $actorId = null,
        ?string $actorType = null,
        ?string $targetId = null,
        string|\DateTimeInterface|null $from = null,
        string|\DateTimeInterface|null $to = null,
    ): Paginated {
        $data = $this->httpClient->get('/v1/gate/audit-log', [
            'limit' => $limit,
            'cursor' => $cursor,
            'action' => $action,
            'actor_id' => $actorId,
            'actor_type' => $actorType,
            'target_id' => $targetId,
            'from' => $from === null ? null : HttpClient::formatTime($from),
            'to' => $to === null ? null : HttpClient::formatTime($to),
        ]);

        return Paginated::fromArray($data, static fn (array $e): AuditLogEntry => AuditLogEntry::fromArray($e));
    }
}
