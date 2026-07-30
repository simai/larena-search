<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Audit\Runtime\DefaultAuditRedactor;
use Larena\Audit\Sinks\DatabaseAuditSink;
use Larena\Queue\Contracts\QueueClock;
use Larena\Queue\Enums\JobStatus;
use Larena\Queue\Enums\QueuePriority;
use Larena\Queue\Runtime\DurableQueueDispatcher;
use Larena\Queue\Runtime\DurableQueueWorker;
use Larena\Queue\Runtime\ImmutableJobDescriptor;
use Larena\Queue\Runtime\JobTypeRegistry;
use Larena\Search\Contracts\ReindexBatch;
use Larena\Search\Contracts\ReindexSource;
use Larena\Search\Contracts\SearchProjection;
use Larena\Search\Operations\SearchIndexOperationsQuery;
use Larena\Search\Persistence\DatabaseSearchIndex;
use Larena\Search\Queue\SearchReindexDispatcher;
use Larena\Search\Queue\SearchReindexJobHandler;
use Larena\Search\Reindex\SearchReindexService;
use Larena\Search\Runtime\SearchSourceRegistry;
use Larena\Search\Tests\Support\SearchTestDatabase;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function search_queue_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

final class SearchQueueClock implements QueueClock
{
    public function __construct(public DateTimeImmutable $value) {}
    public function now(): DateTimeImmutable { return $this->value; }
    public function advance(int $seconds): void { $this->value = $this->value->modify('+' . $seconds . ' seconds'); }
}

final class SearchQueueAuthorizer implements ActorOperationAuthorizer
{
    public function assertAllowed(string $actor, string $operation): void
    {
        if ($actor !== 'user:admin_identity:1' || !in_array($operation, ['search.reindex.schedule', 'search.reindex.run', 'search.reindex.resume', 'search.reindex.retry'], true)) {
            throw new RuntimeException('denied');
        }
    }
}

final class SearchQueueSource implements ReindexSource
{
    public bool $fail = false;
    /** @param list<SearchProjection> $items */
    public function __construct(private array $items) {}
    public function providerId(): string { return 'docara.published_pages'; }
    public function readBatch(?string $afterCursor, int $limit): ReindexBatch
    {
        if ($this->fail) { throw new RuntimeException('private source detail'); }
        $remaining = array_values(array_filter($this->items, static fn (SearchProjection $item): bool => $afterCursor === null || $item->sourceRef > $afterCursor));
        $items = array_slice($remaining, 0, $limit);
        $cursor = $items === [] ? $afterCursor : $items[array_key_last($items)]->sourceRef;

        return new ReindexBatch($items, $cursor, count($remaining) > count($items));
    }
}

/** @return array{worker:DurableQueueWorker,dispatcher:SearchReindexDispatcher,service:SearchReindexService} */
function search_queue_runtime(ConnectionInterface $connection, SearchSourceRegistry $sources, SearchQueueClock $clock): array
{
    $index = new DatabaseSearchIndex($connection);
    $service = new SearchReindexService(
        $connection, $index, $sources, new SearchQueueAuthorizer(),
        new AuditEventPipeline(new DefaultAuditRedactor(), [new DatabaseAuditSink($connection)]),
    );
    $registry = new JobTypeRegistry();
    $store = new \Larena\Queue\Storage\DatabaseQueueStore($connection);
    $queue = new DurableQueueDispatcher($registry, $store, $clock);
    $dispatcher = new SearchReindexDispatcher($service, $queue, new SearchIndexOperationsQuery($connection, $sources));
    $registry->register(new ImmutableJobDescriptor(
        SearchReindexJobHandler::JOB_TYPE, 'search.reindex.run', 'search.reindex.process.handler',
        300, 3, 15, 60, QueuePriority::Maintenance, 'sanitized', 'larena.search.reindex.process.payload',
    ), new SearchReindexJobHandler($service, $dispatcher));

    return [
        'worker' => new DurableQueueWorker($registry, $store, $clock),
        'dispatcher' => $dispatcher,
        'service' => $service,
    ];
}

$database = SearchTestDatabase::create();
$queueMigration = require dirname(__DIR__, 2) . '/vendor/larena/queue/database/migrations/2026_07_26_000000_create_larena_queue_tables.php';
$queueMigration->up();
try {
    $items = [];
    for ($number = 1; $number <= 101; $number++) {
        $ref = sprintf('page:%03d', $number);
        $items[] = new SearchProjection('docara.published_pages', $ref, 1, 'Page ' . $number, '/docs/' . $number, locale: 'en', searchableText: 'public queue proof');
    }
    $source = new SearchQueueSource($items);
    $sources = new SearchSourceRegistry();
    $sources->register($source);
    $clock = new SearchQueueClock(new DateTimeImmutable('2026-07-30T00:00:00Z'));
    $runtime = search_queue_runtime($database->connection(), $sources, $clock);
    $scheduled = $runtime['dispatcher']->schedule('docara.published_pages', 'user:admin_identity:1', 'idle', 'queue-proof');
    search_queue_assert($database->connection()->table('larena_queue_jobs')->count() === 0, 'Schedule must create state without starting synchronous or queued work.');
    $started = $runtime['dispatcher']->run('docara.published_pages', $scheduled->runRef, 'user:admin_identity:1', 'scheduled');
    search_queue_assert(!$started->duplicate, 'Run must enqueue the scheduled run exactly once.');
    search_queue_assert($runtime['dispatcher']->run('docara.published_pages', $scheduled->runRef, 'user:admin_identity:1', 'scheduled')->duplicate, 'Duplicate run request must be idempotent.');

    $first = $runtime['worker']->runNext('worker-before-restart');
    search_queue_assert($first?->status === JobStatus::Completed, 'First bounded batch job must complete.');
    $run = $database->connection()->table('larena_search_reindex_runs')->where('run_ref', $scheduled->runRef)->first();
    search_queue_assert((int) $run->processed_count === 100 && (string) $run->state === 'running', 'One job must process only one bounded batch and persist its checkpoint.');

    $source->fail = true;
    $restarted = search_queue_runtime($database->reconnect(), $sources, $clock);
    $failed = $restarted['worker']->runNext('worker-after-restart-failure');
    search_queue_assert($failed?->status === JobStatus::Retrying && $failed->failureReason === 'search_reindex_batch_failed', 'Failed continuation must retain a sanitized durable retry.');
    $run = $database->connection()->table('larena_search_reindex_runs')->where('run_ref', $scheduled->runRef)->first();
    search_queue_assert((string) $run->state === 'failed' && (int) $run->processed_count === 100, 'Failed batch must preserve the prior checkpoint.');

    $source->fail = false;
    $resumedRuntime = search_queue_runtime($database->reconnect(), $sources, $clock);
    $retry = $resumedRuntime['dispatcher']->retry('docara.published_pages', $scheduled->runRef, 'user:admin_identity:1', 'failed');
    search_queue_assert(!$retry->duplicate, 'Retry must enqueue a distinct failed-attempt continuation.');
    search_queue_assert($resumedRuntime['dispatcher']->retry('docara.published_pages', $scheduled->runRef, 'user:admin_identity:1', 'failed')->duplicate, 'Duplicate retry request must be idempotent.');
    $completed = $resumedRuntime['worker']->runNext('worker-after-restart-resume');
    search_queue_assert($completed?->status === JobStatus::Completed, 'Retry after restart must complete successfully.');
    $run = $database->connection()->table('larena_search_reindex_runs')->where('run_ref', $scheduled->runRef)->first();
    search_queue_assert((string) $run->state === 'completed' && (int) $run->processed_count === 101 && (int) $run->batch_count === 2, 'Resume must continue from the exact checkpoint without duplicate indexing.');
    search_queue_assert($database->connection()->table('larena_search_documents')->count() === 101, 'Completed generation must contain every published projection exactly once.');

    $clock->advance(20);
    $staleContinuation = $resumedRuntime['worker']->runNext('worker-stale-continuation');
    search_queue_assert(
        $staleContinuation?->status === JobStatus::Failed
        && $staleContinuation->failureReason === 'search_reindex_not_running',
        'A delayed internal continuation must fail closed after another attempt completed the run.',
    );
    $completedAfterStaleDispatch = $resumedRuntime['service']->find($scheduled->runRef);
    search_queue_assert(
        $completedAfterStaleDispatch?->state === 'completed'
        && $completedAfterStaleDispatch->processedCount === 101
        && $completedAfterStaleDispatch->batchCount === 2,
        'A stale dispatch must not mutate or duplicate the completed generation.',
    );
} finally {
    $queueMigration->down();
    $database->rollback();
    $database->close();
}

echo "SearchQueueResumeIntegrationTest passed.\n";
