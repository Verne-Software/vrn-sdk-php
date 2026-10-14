<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Gate;

use Vernesoft\Core\HttpClient;
use Vernesoft\Core\Types\Paginated;
use Vernesoft\Resources\Gate\Types\LifecycleAction;
use Vernesoft\Resources\Gate\Types\LifecycleRule;
use Vernesoft\Resources\Gate\Types\LifecycleRulePreview;
use Vernesoft\Resources\Gate\Types\LifecycleRules;

/**
 * Automatic deletion of unconfirmed and inactive accounts, and removal of expired roles —
 * four rules per project (see `LifecycleRuleKind` for each one's settings), all off until
 * you switch them on. Only `delete_unverified` is in the Free plan; switching on another
 * is `403 plan_required`. Settings out of bounds are `400 invalid_payload`.
 */
class LifecycleResource
{
    public function __construct(private HttpClient $httpClient) {}

    /**
     * All four rules of the key's project, set up or not, and the plan.
     */
    public function listRules(): LifecycleRules
    {
        $data = $this->httpClient->get('/v1/gate/lifecycle/rules');

        return LifecycleRules::fromArray($data ?? []);
    }

    /**
     * Creates, changes, or switches a rule on or off. Leave `$params` null to keep the saved
     * settings; a first write needs them (except `expire_roles`, which takes `[]`).
     * Switching off or changing settings cancels what the rule had scheduled.
     *
     * @param  string  $kind  one of the `LifecycleRuleKind` constants
     * @param  array<string, mixed>|null  $params
     */
    public function setRule(string $kind, bool $enabled, ?array $params = null): LifecycleRule
    {
        $body = ['enabled' => $enabled];
        if ($params !== null) {
            $body['params'] = self::object($params);
        }

        $data = $this->httpClient->put($this->rulePath($kind), $body);

        return LifecycleRule::fromArray($data);
    }

    /**
     * Removes a rule and cancels what it scheduled. Succeeds also when there was none.
     */
    public function deleteRule(string $kind): void
    {
        $this->httpClient->delete($this->rulePath($kind));
    }

    /**
     * How many people a rule with these settings would affect right now. Writes nothing;
     * works whether or not the rule is on or in your plan. `expire_roles` takes `[]`.
     *
     * @param  array<string, mixed>  $params
     */
    public function previewRule(string $kind, array $params): LifecycleRulePreview
    {
        $data = $this->httpClient->post($this->rulePath($kind).'/preview', ['params' => self::object($params)]);

        return LifecycleRulePreview::fromArray($data);
    }

    /**
     * What the rules have scheduled and done, oldest first, one page at a time.
     *
     * @param  string|null  $status  `pending`, `done`, `cancelled` or `failed`
     * @param  string|null  $kind  one of the `LifecycleRuleKind` constants
     * @return Paginated<LifecycleAction>
     */
    public function listActions(
        ?string $status = null,
        ?string $kind = null,
        ?string $identityId = null,
        ?int $limit = null,
        ?string $cursor = null,
    ): Paginated {
        $data = $this->httpClient->get('/v1/gate/lifecycle/actions', [
            'status' => $status,
            'kind' => $kind,
            'identity_id' => $identityId,
            'limit' => $limit,
            'cursor' => $cursor,
        ]);

        return Paginated::fromArray($data, static fn (array $a): LifecycleAction => LifecycleAction::fromArray($a));
    }

    private function rulePath(string $kind): string
    {
        return '/v1/gate/lifecycle/rules/'.HttpClient::seg($kind);
    }

    /**
     * An empty PHP array would be sent as `[]`; the API wants an object.
     *
     * @param  array<string, mixed>  $params
     */
    private static function object(array $params): array|\stdClass
    {
        return $params === [] ? new \stdClass : $params;
    }
}
