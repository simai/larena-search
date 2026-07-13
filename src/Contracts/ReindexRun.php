<?php

declare(strict_types=1);

namespace Larena\Search\Contracts;

final readonly class ReindexRun
{
    public function __construct(
        public string $runRef,
        public string $providerId,
        public string $generationRef,
        public string $state,
        public ?string $cursor,
        public int $processedCount,
        public int $batchCount,
        public string $requestedBy,
        public string $correlationId,
        public ?string $errorCode = null,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->state === 'completed';
    }
}
