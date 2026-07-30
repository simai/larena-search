<?php

declare(strict_types=1);

namespace Larena\Search\Queue;

use InvalidArgumentException;
use Larena\Queue\Contracts\QueueJobHandler;
use Larena\Queue\Data\QueueExecutionContext;
use Larena\Queue\Data\QueueJobResult;
use Larena\Search\Exceptions\SearchReindexRejected;
use Larena\Search\Reindex\SearchReindexExecutionEngine;
use Throwable;

final readonly class SearchReindexJobHandler implements QueueJobHandler
{
    public const JOB_TYPE = 'search.reindex.process';

    public function __construct(
        private SearchReindexExecutionEngine $engine,
        private SearchReindexWorkerAttemptCodec $attempts,
        private SearchReindexWorkerDispatcher $dispatcher,
    ) {
    }

    public function handle(QueueExecutionContext $context, array $payload): QueueJobResult
    {
        if (array_keys($payload) !== ['worker_attempt'] || !is_string($payload['worker_attempt']) || $payload['worker_attempt'] === '') {
            return QueueJobResult::failure('search_reindex_payload_invalid', false);
        }

        try {
            $attempt = $this->attempts->decode($payload['worker_attempt']);
            $next = $this->engine->executeWorkerAttempt($attempt);
            $context->checkpoint();
            if (!$next->isComplete()) {
                $this->dispatcher->continue($next, $attempt->actorRef);
            }

            return QueueJobResult::success([
                'run_ref' => $attempt->runRef,
                'state' => $next->state,
                'processed_count' => $next->processedCount,
                'batch_count' => $next->batchCount,
                'attempt_ref' => $attempt->attemptRef,
            ]);
        } catch (InvalidArgumentException) {
            return QueueJobResult::failure('search_reindex_worker_attempt_invalid', false);
        } catch (SearchReindexRejected $exception) {
            if (in_array($exception->reasonCode, [
                'search_reindex_run_unknown',
                'search_reindex_run_provider_mismatch',
                'search_reindex_not_scheduled',
                'search_reindex_not_running',
                'search_reindex_not_retryable',
                'search_reindex_worker_attempt_stale',
            ], true)) {
                return QueueJobResult::failure($exception->reasonCode, false);
            }

            return QueueJobResult::failure('search_reindex_batch_failed', true);
        } catch (Throwable) {
            return QueueJobResult::failure('search_reindex_batch_failed', true);
        }
    }
}
