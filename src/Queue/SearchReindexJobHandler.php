<?php

declare(strict_types=1);

namespace Larena\Search\Queue;

use Larena\Queue\Contracts\QueueJobHandler;
use Larena\Queue\Data\QueueExecutionContext;
use Larena\Queue\Data\QueueJobResult;
use Larena\Search\Exceptions\SearchReindexRejected;
use Larena\Search\Reindex\SearchReindexService;
use Throwable;

final readonly class SearchReindexJobHandler implements QueueJobHandler
{
    public const JOB_TYPE = 'search.reindex.process';

    public function __construct(private SearchReindexService $reindex, private SearchReindexDispatcher $dispatcher)
    {
    }

    public function handle(QueueExecutionContext $context, array $payload): QueueJobResult
    {
        $runRef = is_string($payload['run_ref'] ?? null) ? $payload['run_ref'] : '';
        $providerId = is_string($payload['provider_id'] ?? null) ? $payload['provider_id'] : '';
        $actor = is_string($payload['actor_ref'] ?? null) ? $payload['actor_ref'] : '';
        $batchSize = is_int($payload['batch_size'] ?? null) ? $payload['batch_size'] : 100;
        $operation = is_string($payload['operation'] ?? null) ? $payload['operation'] : 'continue';
        if ($runRef === '' || $providerId === '' || $actor === '' || $batchSize < 1 || $batchSize > 1000 || !in_array($operation, ['run', 'resume', 'retry', 'continue'], true)) {
            return QueueJobResult::failure('search_reindex_payload_invalid', false);
        }

        try {
            $current = $this->reindex->find($runRef);
            if ($current === null || $current->providerId !== $providerId) {
                return QueueJobResult::failure('search_reindex_run_unknown', false);
            }
            $next = match ($operation) {
                'run' => $this->reindex->run($runRef, $actor, $batchSize, 1),
                'retry' => $this->reindex->retry($runRef, $actor, $batchSize, 1, $providerId),
                'resume' => $this->reindex->resume($runRef, $actor, $batchSize, 1, $providerId),
                default => $this->reindex->continueRunning($runRef, $actor, $batchSize, 1, $providerId),
            };
            $context->checkpoint();
            if (!$next->isComplete()) {
                $this->dispatcher->dispatchRun($next, 'continue', $actor);
            }

            return QueueJobResult::success([
                'run_ref' => $runRef,
                'state' => $next->state,
                'processed_count' => $next->processedCount,
                'batch_count' => $next->batchCount,
            ]);
        } catch (SearchReindexRejected $exception) {
            if (in_array($exception->reasonCode, [
                'search_reindex_run_unknown',
                'search_reindex_run_provider_mismatch',
                'search_reindex_not_scheduled',
                'search_reindex_not_running',
                'search_reindex_not_retryable',
            ], true)) {
                return QueueJobResult::failure($exception->reasonCode, false, ['run_ref' => $runRef]);
            }

            return QueueJobResult::failure('search_reindex_batch_failed', true, ['run_ref' => $runRef]);
        } catch (Throwable) {
            return QueueJobResult::failure('search_reindex_batch_failed', true, ['run_ref' => $runRef]);
        }
    }
}
