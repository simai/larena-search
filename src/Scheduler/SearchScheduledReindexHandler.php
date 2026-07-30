<?php

declare(strict_types=1);

namespace Larena\Search\Scheduler;

use Larena\Queue\Data\DispatchRequest;
use Larena\Queue\Data\DispatchResult;
use Larena\Queue\Data\QueueJobSnapshot;
use Larena\Queue\Runtime\DurableQueueDispatcher;
use Larena\Queue\Runtime\QueueControlService;
use Larena\Scheduler\Contracts\ScheduledOperationHandler;
use Larena\Scheduler\Data\ScheduledDispatchContext;

final readonly class SearchScheduledReindexHandler implements ScheduledOperationHandler
{
    public const OPERATION_REF = 'search.reindex.all';
    public const JOB_TYPE = 'search.reindex.schedule_all';

    public function __construct(private DurableQueueDispatcher $queue, private QueueControlService $control)
    {
    }

    public function operationRef(): string
    {
        return self::OPERATION_REF;
    }

    public function dispatch(ScheduledDispatchContext $context): DispatchResult
    {
        return $this->queue->dispatch(new DispatchRequest(
            self::JOB_TYPE,
            ['actor_ref' => $context->schedule->actorRef],
            $context->idempotencyKey,
            $context->correlationId,
        ));
    }

    public function status(string $jobId): QueueJobSnapshot
    {
        return $this->control->status($jobId);
    }
}
