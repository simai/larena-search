<?php

declare(strict_types=1);

namespace Larena\Search\Persistence;

final readonly class LockedProviderState
{
    public function __construct(
        public string $providerId,
        public ?string $activeRunRef,
        public ?string $activeGenerationRef,
    ) {
    }

    public function isActive(): bool
    {
        return $this->activeRunRef !== null;
    }
}
