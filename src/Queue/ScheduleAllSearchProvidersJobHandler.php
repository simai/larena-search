<?php

declare(strict_types=1);

namespace Larena\Search\Queue;

use Larena\Queue\Contracts\QueueJobHandler;
use Larena\Queue\Data\QueueExecutionContext;
use Larena\Queue\Data\QueueJobResult;
use Larena\Search\Operations\SearchIndexOperationsQuery;
use Larena\Search\Runtime\SearchSourceRegistry;
use Throwable;

final readonly class ScheduleAllSearchProvidersJobHandler implements QueueJobHandler
{
    public function __construct(
        private SearchSourceRegistry $sources,
        private SearchIndexOperationsQuery $operations,
        private SearchReindexDispatcher $dispatcher,
    ) {
    }

    public function handle(QueueExecutionContext $context, array $payload): QueueJobResult
    {
        $actor = is_string($payload['actor_ref'] ?? null) ? $payload['actor_ref'] : '';
        if ($actor === '') {
            return QueueJobResult::failure('search_reindex_payload_invalid', false);
        }
        $scheduled = 0;
        try {
            foreach (array_slice($this->sources->providerIds(), 0, 20) as $providerId) {
                $context->checkpoint();
                $state = $this->operations->provider($providerId);
                if ($state !== null && in_array($state['state'], ['idle', 'completed'], true)) {
                    $this->dispatcher->schedule($providerId, $actor, (string) $state['state'], $context->correlationId);
                    $scheduled++;
                }
            }

            return QueueJobResult::success(['scheduled_count' => $scheduled]);
        } catch (Throwable) {
            return QueueJobResult::failure('search_reindex_schedule_all_failed', true, ['scheduled_count' => $scheduled]);
        }
    }
}
