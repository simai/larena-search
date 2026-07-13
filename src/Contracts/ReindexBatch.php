<?php

declare(strict_types=1);

namespace Larena\Search\Contracts;

use InvalidArgumentException;

final readonly class ReindexBatch
{
    /** @var list<SearchProjection> */
    public array $projections;

    /** @param array<mixed> $projections */
    public function __construct(
        array $projections,
        public ?string $nextCursor,
        public bool $hasMore,
    ) {
        if ($this->hasMore && ($this->nextCursor === null || trim($this->nextCursor) === '')) {
            throw new InvalidArgumentException('search_reindex_cursor_required');
        }

        if (!array_is_list($projections)) {
            throw new InvalidArgumentException('search_reindex_projection_list_invalid');
        }
        foreach ($projections as $projection) {
            if (!$projection instanceof SearchProjection) {
                throw new InvalidArgumentException('search_reindex_projection_invalid');
            }
        }

        $this->projections = $projections;
    }
}
