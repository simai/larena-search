<?php

declare(strict_types=1);

namespace Larena\Search\Queue;

use Larena\Queue\Contracts\QueueJobHandler;
use Larena\Queue\Data\QueueExecutionContext;
use Larena\Queue\Data\QueueJobResult;
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
        if ($runRef === '' || $providerId === '' || $actor === '' || $batchSize < 1 || $batchSize > 1000) {
            return QueueJobResult::failure('search_reindex_payload_invalid', false);
        }

        try {
            $current = $this->reindex->find($runRef);
            if ($current === null || $current->providerId !== $providerId) {
                return QueueJobResult::failure('search_reindex_run_unknown', false);
            }
            if ($current->isComplete()) {
                return QueueJobResult::success(['run_ref' => $runRef, 'state' => 'completed', 'processed_count' => $current->processedCount]);
            }

            $next = $current->state === 'scheduled'
                ? $this->reindex->run($runRef, $actor, $batchSize, 1)
                : $this->reindex->resume($runRef, $actor, $batchSize, 1, $providerId);
            $context->checkpoint();
            if (!$next->isComplete()) {
                $this->dispatcher->dispatchRun($next);
            }

            return QueueJobResult::success([
                'run_ref' => $runRef,
                'state' => $next->state,
                'processed_count' => $next->processedCount,
                'batch_count' => $next->batchCount,
            ]);
        } catch (Throwable) {
            return QueueJobResult::failure('search_reindex_batch_failed', true, ['run_ref' => $runRef]);
        }
    }
}
