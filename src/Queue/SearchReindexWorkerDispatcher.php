<?php

declare(strict_types=1);

namespace Larena\Search\Queue;

use Larena\Queue\Data\DispatchRequest;
use Larena\Queue\Data\DispatchResult;
use Larena\Queue\Runtime\DurableQueueDispatcher;
use Larena\Search\Contracts\ReindexRun;

/** @internal Registered Search Queue workers are the only callers. */
final readonly class SearchReindexWorkerDispatcher
{
    public function __construct(
        private DurableQueueDispatcher $queue,
        private SearchReindexWorkerAttemptCodec $attempts,
        private int $batchSize = 100,
    ) {
    }

    public function continue(ReindexRun $run, string $actor): DispatchResult
    {
        $batchSize = max(1, min(1000, $this->batchSize));
        $token = $this->attempts->issue($run, 'continue', $actor, $batchSize);
        $attempt = $this->attempts->decode($token);

        return $this->queue->dispatch(new DispatchRequest(
            jobType: SearchReindexJobHandler::JOB_TYPE,
            payload: ['worker_attempt' => $token],
            idempotencyKey: 'search-reindex:' . $run->runRef . ':continue:' . $attempt->attemptRef,
            correlationId: $this->safeCorrelation($run->correlationId),
        ));
    }

    private function safeCorrelation(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9_.:-]/', '-', $value) ?? '';

        return substr($value !== '' ? $value : 'search-reindex', 0, 64);
    }
}
