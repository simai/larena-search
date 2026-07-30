<?php

declare(strict_types=1);

namespace Larena\Search\Queue;

/** @internal Immutable, authenticated Queue execution claim. */
final readonly class SearchReindexWorkerAttempt
{
    public function __construct(
        public string $attemptRef,
        public string $operation,
        public string $runRef,
        public string $providerId,
        public string $generationRef,
        public string $expectedState,
        public ?string $expectedCursorHash,
        public int $expectedProcessedCount,
        public int $expectedBatchCount,
        public string $actorRef,
        public int $batchSize,
    ) {
    }
}
