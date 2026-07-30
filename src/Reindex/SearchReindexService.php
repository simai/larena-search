<?php

declare(strict_types=1);

namespace Larena\Search\Reindex;

use Illuminate\Database\ConnectionInterface;
use Larena\Search\Contracts\ReindexRun;

/**
 * Public Search reindex API. Only the four canonical operator actions are exposed.
 */
final readonly class SearchReindexService
{
    public function __construct(private SearchReindexExecutionEngine $engine)
    {
    }

    public function connection(): ConnectionInterface
    {
        return $this->engine->connection();
    }

    public function schedule(
        string $providerId,
        string $actor,
        ?string $runRef = null,
        ?string $correlationId = null,
    ): ReindexRun {
        return $this->engine->schedule($providerId, $actor, $runRef, $correlationId);
    }

    public function run(string $runRef, string $actor, int $batchSize = 100, int $maxBatches = 0): ReindexRun
    {
        return $this->engine->run($runRef, $actor, $batchSize, $maxBatches);
    }

    public function resume(
        string $runRef,
        string $actor,
        int $batchSize = 100,
        int $maxBatches = 0,
        ?string $expectedProviderId = null,
    ): ReindexRun {
        return $this->engine->resume($runRef, $actor, $batchSize, $maxBatches, $expectedProviderId);
    }

    public function retry(
        string $runRef,
        string $actor,
        int $batchSize = 100,
        int $maxBatches = 0,
        ?string $expectedProviderId = null,
    ): ReindexRun {
        return $this->engine->retry($runRef, $actor, $batchSize, $maxBatches, $expectedProviderId);
    }

    public function find(string $runRef): ?ReindexRun
    {
        return $this->engine->find($runRef);
    }
}
