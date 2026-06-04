<?php

declare(strict_types=1);

namespace Larena\Search\Contracts;

final readonly class QueryContext
{
    public function __construct(
        public string $query,
        public string $surface,
        public string $actorReference,
        public string $accessScope,
        public bool $protectedExistenceCanBeRevealed = false,
    ) {
    }

    public function isValid(): bool
    {
        return trim($this->query) !== ''
            && $this->surface !== ''
            && $this->actorReference !== ''
            && $this->accessScope !== '';
    }
}
