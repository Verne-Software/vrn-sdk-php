<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Relay\Types;

final readonly class ListMessagesResponse
{
    /**
     * @param  Message[]  $data
     */
    public function __construct(
        public array $data,
        public bool $hasMore,
        public ?string $nextCursor,
    ) {}

    public static function fromArray(array $responseData): self
    {
        $messages = array_map(
            fn (array $item) => Message::fromArray($item),
            $responseData['data'] ?? [],
        );

        return new self(
            data: $messages,
            hasMore: (bool) ($responseData['has_more'] ?? false),
            nextCursor: $responseData['next_cursor'] ?? null,
        );
    }
}
