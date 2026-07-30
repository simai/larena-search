<?php

declare(strict_types=1);

namespace Larena\Search\Contracts;

final readonly class SearchPage
{
    /** @param list<SearchHit> $hits */
    public function __construct(
        public array $hits,
        public int $page,
        public int $perPage,
        public bool $hasNext,
    ) {
    }
}
