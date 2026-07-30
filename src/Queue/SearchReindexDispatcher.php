<?php

declare(strict_types=1);

namespace Larena\Search\Queue;

use Larena\Queue\Data\DispatchRequest;
use Larena\Queue\Data\DispatchResult;
use Larena\Queue\Runtime\DurableQueueDispatcher;
use Larena\Search\Contracts\ReindexRun;
use Larena\Search\Exceptions\SearchReindexRejected;
use Larena\Search\Operations\SearchIndexOperationsQuery;
use Larena\Search\Reindex\SearchReindexService;

final readonly class SearchReindexDispatcher
{
    public function __construct(
        private SearchReindexService $reindex,
        private DurableQueueDispatcher $queue,
        private SearchIndexOperationsQuery $operations,
        private int $batchSize = 100,
    ) {
    }

    public function schedule(string $providerId, string $actor, string $expectedState, ?string $correlationId = null): ReindexRun
    {
        $current = $this->operations->provider($providerId);
        if ($current === null || $expectedState !== (string) $current['state']) {
            throw new SearchReindexRejected('search_reindex_expected_state_mismatch');
        }
        return $this->reindex->schedule($providerId, $actor, null, $correlationId);
    }

    public function run(string $providerId, string $runRef, string $actor, string $expectedState): DispatchResult
    {
        $run = $this->assertCurrentRun($providerId, $runRef, $expectedState, ['scheduled']);

        return $this->dispatchRun($run, 'run', $actor);
    }

    public function dispatchRun(ReindexRun $run, string $operation = 'continue', ?string $actor = null): DispatchResult
    {
        return $this->queue->dispatch(new DispatchRequest(
            jobType: SearchReindexJobHandler::JOB_TYPE,
            payload: [
                'run_ref' => $run->runRef,
                'provider_id' => $run->providerId,
                'actor_ref' => $actor ?? $run->requestedBy,
                'batch_size' => max(1, min(1000, $this->batchSize)),
                'operation' => $operation,
            ],
            idempotencyKey: 'search-reindex:' . $run->runRef . ':' . $operation . ':' . $run->batchCount,
            correlationId: $this->safeCorrelation($run->correlationId),
        ));
    }

    public function resume(string $providerId, string $runRef, string $actor, string $expectedState): DispatchResult
    {
        $run = $this->assertCurrentRun($providerId, $runRef, $expectedState, ['running']);

        return $this->dispatchRun($run, 'resume', $actor);
    }

    public function retry(string $providerId, string $runRef, string $actor, string $expectedState): DispatchResult
    {
        $run = $this->assertCurrentRun($providerId, $runRef, $expectedState, ['failed']);

        return $this->dispatchRun($run, 'retry', $actor);
    }

    /** @param list<string> $allowedStates */
    private function assertCurrentRun(string $providerId, string $runRef, string $expectedState, array $allowedStates): ReindexRun
    {
        $current = $this->operations->provider($providerId);
        if ($current === null || $current['run_ref'] !== $runRef || $current['state'] !== $expectedState || !in_array($expectedState, $allowedStates, true)) {
            throw new SearchReindexRejected('search_reindex_expected_state_mismatch');
        }
        $run = $this->reindex->find($runRef);
        if ($run === null || $run->providerId !== $providerId) {
            throw new SearchReindexRejected('search_reindex_run_unknown');
        }

        return $run;
    }

    private function safeCorrelation(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9_.:-]/', '-', $value) ?? '';

        return substr($value !== '' ? $value : 'search-reindex', 0, 64);
    }

}
