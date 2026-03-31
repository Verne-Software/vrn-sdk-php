<?php

declare(strict_types=1);

namespace Vernesoft\Core\Types;

/**
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
}
