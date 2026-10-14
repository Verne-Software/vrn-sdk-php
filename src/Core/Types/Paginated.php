<?php

declare(strict_types=1);

namespace Vernesoft\Core\Types;

/**
 * One page of a cursor-paged list. Pass `$nextCursor` back as `cursor:` while
 * `$hasMore` is true; `$nextCursor` is `null` on the last page.
 *
 * @template T
 */
final readonly class Paginated
{
    /**
     * @param  array<T>  $data
     */
    public function __construct(
        public array $data,
        public bool $hasMore,
        public ?string $nextCursor,
    ) {}

    /**
     * @template U
     *
     * @param  array<string, mixed>|null  $page  the `{data, has_more, next_cursor}` answer
     * @param  callable(array<string, mixed>): U  $map
     * @return self<U>
     */
    public static function fromArray(?array $page, callable $map): self
    {
        $page ??= [];

        return new self(
            data: array_map($map, $page['data'] ?? []),
            hasMore: (bool) ($page['has_more'] ?? false),
            nextCursor: isset($page['next_cursor']) && is_string($page['next_cursor']) ? $page['next_cursor'] : null,
        );
    }
}
