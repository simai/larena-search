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
use Larena\Search\Queue\SearchReindexWorkerAttemptCodec;
use Larena\Search\Queue\SearchReindexWorkerDispatcher;
use Larena\Search\Reindex\SearchReindexExecutionEngine;
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

/** @return array{worker:DurableQueueWorker,dispatcher:SearchReindexDispatcher,service:SearchReindexService,queue:DurableQueueDispatcher,attempts:SearchReindexWorkerAttemptCodec} */
function search_queue_runtime(ConnectionInterface $connection, SearchSourceRegistry $sources, SearchQueueClock $clock): array
{
    $index = new DatabaseSearchIndex($connection);
    $engine = new SearchReindexExecutionEngine(
        $connection, $index, $sources, new SearchQueueAuthorizer(),
        new AuditEventPipeline(new DefaultAuditRedactor(), [new DatabaseAuditSink($connection)]),
    );
    $service = new SearchReindexService($engine);
    $registry = new JobTypeRegistry();
    $store = new \Larena\Queue\Storage\DatabaseQueueStore($connection);
    $queue = new DurableQueueDispatcher($registry, $store, $clock);
    $attempts = new SearchReindexWorkerAttemptCodec(str_repeat('q', 32));
    $dispatcher = new SearchReindexDispatcher($service, $queue, new SearchIndexOperationsQuery($connection, $sources), $attempts);
    $workerDispatcher = new SearchReindexWorkerDispatcher($queue, $attempts);
    $registry->register(new ImmutableJobDescriptor(
        SearchReindexJobHandler::JOB_TYPE, 'search.reindex.run', 'search.reindex.process.handler',
        300, 3, 15, 60, QueuePriority::Maintenance, 'sanitized', 'larena.search.reindex.process.payload',
    ), new SearchReindexJobHandler($engine, $attempts, $workerDispatcher));

    return [
        'worker' => new DurableQueueWorker($registry, $store, $clock),
        'dispatcher' => $dispatcher,
        'service' => $service,
        'queue' => $queue,
        'attempts' => $attempts,
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
    $publicDispatcherMethods = array_map(static fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass(SearchReindexDispatcher::class))->getMethods(ReflectionMethod::IS_PUBLIC));
    search_queue_assert(!in_array('dispatchRun', $publicDispatcherMethods, true), 'Public dispatcher must expose only canonical operator actions.');
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

    $workerAudit = $database->connection()->table('larena_audit_events')
        ->where('source_package', 'larena/search')
        ->whereIn('event_type', ['search.reindex.operation_started', 'search.reindex.failed', 'search.reindex.checkpointed', 'search.reindex.completed'])
        ->orderBy('id')
        ->pluck('payload')
        ->map(static fn (string $payload): array => json_decode($payload, true, 32, JSON_THROW_ON_ERROR))
        ->all();
    search_queue_assert((bool) array_filter($workerAudit, static fn (array $payload): bool => ($payload['operation'] ?? null) === 'continue' && isset($payload['attempt_ref'])), 'Worker continuation Audit must have its own signed attempt identity.');
    search_queue_assert((bool) array_filter($workerAudit, static fn (array $payload): bool => ($payload['operation'] ?? null) === 'retry' && isset($payload['attempt_ref'])), 'Operator retry Audit must retain its distinct signed attempt identity.');
    foreach ($workerAudit as $payload) {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        search_queue_assert(preg_match('/password|cookie|token|secret|query|title|snippet|body|worker_attempt/i', $encoded) !== 1, 'Worker Audit must remain sanitized.');
    }

    $clock->advance(20);
    $staleContinuation = $resumedRuntime['worker']->runNext('worker-stale-continuation');
    search_queue_assert(
        $staleContinuation?->status === JobStatus::Failed
        && $staleContinuation->failureReason === 'search_reindex_worker_attempt_stale',
        'A delayed internal continuation must fail closed after another attempt completed the run.',
    );
    $completedAfterStaleDispatch = $resumedRuntime['service']->find($scheduled->runRef);
    search_queue_assert(
        $completedAfterStaleDispatch?->state === 'completed'
        && $completedAfterStaleDispatch->processedCount === 101
        && $completedAfterStaleDispatch->batchCount === 2,
        'A stale dispatch must not mutate or duplicate the completed generation.',
    );

    $missing = $runtime['queue']->dispatch(new \Larena\Queue\Data\DispatchRequest(
        SearchReindexJobHandler::JOB_TYPE, [], 'search-reindex-negative-missing', 'queue-proof-missing',
    ));
    search_queue_assert(!$missing->duplicate, 'Missing-attempt negative job must be enqueued for executable rejection proof.');
    $missingResult = $resumedRuntime['worker']->runNext('worker-missing-attempt');
    search_queue_assert($missingResult?->status === JobStatus::Failed && $missingResult->failureReason === 'search_reindex_payload_invalid', 'Missing worker attempt must fail closed.');

    $validToken = $runtime['attempts']->issue($completedAfterStaleDispatch, 'continue', 'user:admin_identity:1', 100);
    $forgedToken = substr($validToken, 0, -1) . (str_ends_with($validToken, 'A') ? 'B' : 'A');
    $forged = $runtime['queue']->dispatch(new \Larena\Queue\Data\DispatchRequest(
        SearchReindexJobHandler::JOB_TYPE,
        ['worker_attempt' => $forgedToken],
        'search-reindex-negative-forged',
        'queue-proof-forged',
    ));
    search_queue_assert(!$forged->duplicate, 'Forged-attempt negative job must be enqueued for executable rejection proof.');
    $forgedResult = $resumedRuntime['worker']->runNext('worker-forged-attempt');
    search_queue_assert($forgedResult?->status === JobStatus::Failed && $forgedResult->failureReason === 'search_reindex_worker_attempt_invalid', 'Forged worker attempt must fail closed before Search mutation.');

    $finalRun = $resumedRuntime['service']->find($scheduled->runRef);
    search_queue_assert($finalRun?->state === 'completed' && $finalRun->processedCount === 101 && $database->connection()->table('larena_search_documents')->count() === 101, 'Negative Queue payloads must leave the completed generation unchanged.');
} finally {
    $queueMigration->down();
    $database->rollback();
    $database->close();
}

echo "SearchQueueResumeIntegrationTest passed.\n";
