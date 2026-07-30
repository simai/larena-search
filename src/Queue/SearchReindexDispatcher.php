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
    ) {
    }

    /** @return array{run:ReindexRun,dispatch:DispatchResult} */
    public function schedule(string $providerId, string $actor, string $expectedState, ?string $correlationId = null): array
    {
        $current = $this->operations->provider($providerId);
        if ($current === null || $expectedState !== (string) $current['state']) {
            throw new SearchReindexRejected('search_reindex_expected_state_mismatch');
        }
        $run = $this->reindex->schedule($providerId, $actor, null, $correlationId);

        return ['run' => $run, 'dispatch' => $this->dispatchRun($run)];
    }

    public function dispatchRun(ReindexRun $run): DispatchResult
    {
        return $this->queue->dispatch(new DispatchRequest(
            jobType: SearchReindexJobHandler::JOB_TYPE,
            payload: ['run_ref' => $run->runRef, 'provider_id' => $run->providerId, 'actor_ref' => $run->requestedBy, 'batch_size' => 100],
            idempotencyKey: 'search-reindex:' . $run->runRef . ':batch:' . $run->batchCount,
            correlationId: $this->safeCorrelation($run->correlationId),
        ));
    }

    public function resume(string $providerId, string $runRef, string $actor, string $expectedState): DispatchResult
    {
        $current = $this->operations->provider($providerId);
        if ($current === null || $current['run_ref'] !== $runRef || $current['state'] !== $expectedState || !in_array($expectedState, ['failed', 'running', 'scheduled'], true)) {
            throw new SearchReindexRejected('search_reindex_expected_state_mismatch');
        }
        $run = $this->reindex->find($runRef);
        if ($run === null || $run->providerId !== $providerId) {
            throw new SearchReindexRejected('search_reindex_run_unknown');
        }

        return $this->queue->dispatch(new DispatchRequest(
            jobType: SearchReindexJobHandler::JOB_TYPE,
            payload: ['run_ref' => $run->runRef, 'provider_id' => $run->providerId, 'actor_ref' => $actor, 'batch_size' => 100],
            idempotencyKey: 'search-reindex:' . $run->runRef . ':resume:' . $run->batchCount,
            correlationId: $this->safeCorrelation($run->correlationId),
        ));
    }

    private function safeCorrelation(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9_.:-]/', '-', $value) ?? '';

        return substr($value !== '' ? $value : 'search-reindex', 0, 64);
    }
}
