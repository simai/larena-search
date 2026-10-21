<?php

declare(strict_types=1);

namespace Larena\Search\Contracts;

final readonly class SearchPage
{
    /**
     * @param list<SearchHit> $hits
     * @param string|null $nextCursor When there is a next page: pass it as `SearchQuery::$after` to read it without an offset.
     */
    public function __construct(
        public array $hits,
        public int $page,
        public int $perPage,
        public bool $hasNext,
        public ?string $nextCursor = null,
    ) {
    }
}
