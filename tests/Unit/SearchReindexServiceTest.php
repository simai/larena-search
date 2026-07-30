<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Exceptions\AccessMutationRejected;
use Larena\Audit\Contracts\AuditEvent;
use Larena\Audit\Contracts\AuditEventDescriptor;
use Larena\Audit\Contracts\AuditSink;
use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Audit\Runtime\DefaultAuditRedactor;
use Larena\Audit\Sinks\DatabaseAuditSink;
use Larena\Search\Commands\ReindexSearchCommand;
use Larena\Search\Contracts\ReindexBatch;
use Larena\Search\Contracts\ReindexSource;
use Larena\Search\Contracts\ReindexSourceFactory;
use Larena\Search\Contracts\SearchProjection;
use Larena\Search\Contracts\SearchQuery;
use Larena\Search\Exceptions\SearchPersistenceFailed;
use Larena\Search\Exceptions\SearchReindexRejected;
use Larena\Search\Persistence\DatabaseSearchIndex;
use Larena\Search\Reindex\SearchReindexExecutionEngine;
use Larena\Search\Reindex\SearchReindexService;
use Larena\Search\Runtime\SearchSourceRegistry;
use Larena\Search\Tests\Support\SearchTestDatabase;
use Symfony\Component\Console\Tester\CommandTester;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function search_reindex_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function search_reindex_service(
    Illuminate\Database\ConnectionInterface $connection,
    DatabaseSearchIndex $index,
    SearchSourceRegistry $registry,
    ActorOperationAuthorizer $authorizer,
    AuditEventPipeline $audit,
): SearchReindexService {
    return new SearchReindexService(new SearchReindexExecutionEngine($connection, $index, $registry, $authorizer, $audit));
}

final class SearchReindexTestAuthorizer implements ActorOperationAuthorizer
{
    /** @var list<string> */
    public array $operations = [];

    public function __construct(private readonly bool $allowed = true)
    {
    }

    public function assertAllowed(string $actor, string $operation): void
    {
        $this->operations[] = $operation;
        if (!$this->allowed) {
            throw new AccessMutationRejected('access_actor_forbidden');
        }
    }
}

final class SearchReindexTestSource implements ReindexSource
{
    public bool $fail = false;

    /** @param list<SearchProjection> $projections */
    public function __construct(private readonly string $id, private array $projections)
    {
        usort($this->projections, static fn (SearchProjection $left, SearchProjection $right): int => $left->sourceRef <=> $right->sourceRef);
    }

    public function providerId(): string
    {
        return $this->id;
    }

    public function readBatch(?string $afterCursor, int $limit): ReindexBatch
    {
        if ($this->fail) {
            throw new RuntimeException('raw source detail must not escape');
        }

        $remaining = array_values(array_filter(
            $this->projections,
            static fn (SearchProjection $projection): bool => $afterCursor === null || $projection->sourceRef > $afterCursor,
        ));
        $slice = array_slice($remaining, 0, $limit);
        $hasMore = count($remaining) > count($slice);
        $last = $slice === [] ? $afterCursor : $slice[array_key_last($slice)]->sourceRef;

        return new ReindexBatch($slice, $last, $hasMore);
    }
}

final class SearchReindexTestFactory implements ReindexSourceFactory
{
    public int $createCount = 0;

    public function __construct(private readonly ReindexSource $source)
    {
    }

    public function providerId(): string
    {
        return $this->source->providerId();
    }

    public function create(): ReindexSource
    {
        $this->createCount++;

        return $this->source;
    }
}

final readonly class SearchReindexThrowingFactory implements ReindexSourceFactory
{
    public function providerId(): string
    {
        return 'factory.fail';
    }

    public function create(): ReindexSource
    {
        throw new SearchReindexRejected('raw_sensitive_factory_detail');
    }
}

final readonly class SearchReindexMismatchedFactory implements ReindexSourceFactory
{
    public function providerId(): string
    {
        return 'factory.expected';
    }

    public function create(): ReindexSource
    {
        return new SearchReindexTestSource('factory.actual', []);
    }
}

final class SearchReindexTestConsoleApplication extends Container
{
    public function runningUnitTests(): bool
    {
        return true;
    }
}

final readonly class SearchReindexThrowingSink implements AuditSink
{
    public function accepts(AuditEventDescriptor $descriptor): bool
    {
        return true;
    }

    public function write(AuditEvent $event): void
    {
        throw new RuntimeException('raw audit sink detail must not escape');
    }
}

$database = SearchTestDatabase::create();

try {
    $connection = $database->connection();
    $index = new DatabaseSearchIndex($connection);
    $registry = new SearchSourceRegistry();
    $source = new SearchReindexTestSource('docara.pages', [
        new SearchProjection('docara.pages', 'page:a', 1, 'Page A', '/docs/a', searchableText: 'alpha public'),
        new SearchProjection('docara.pages', 'page:b', 1, 'Page B', '/docs/b', searchableText: 'beta public'),
        new SearchProjection('docara.pages', 'page:c', 1, 'Page C', '/docs/c', searchableText: 'gamma public'),
    ]);
    $sourceFactory = new SearchReindexTestFactory($source);
    $registry->registerFactory($sourceFactory);
    $authorizer = new SearchReindexTestAuthorizer();
    $goodPipeline = new AuditEventPipeline(new DefaultAuditRedactor(), [new DatabaseAuditSink($connection)]);
    $service = search_reindex_service($connection, $index, $registry, $authorizer, $goodPipeline);
    $publicServiceMethods = array_map(static fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass(SearchReindexService::class))->getMethods(ReflectionMethod::IS_PUBLIC));
    search_reindex_assert(!in_array('continueRunning', $publicServiceMethods, true), 'Public Search service must expose no continuation escape hatch.');

    $index->upsert(new SearchProjection('docara.pages', 'page:orphan', 1, 'Orphan', '/docs/orphan', searchableText: 'remove me'));
    $scheduled = $service->schedule('docara.pages', 'user:admin_identity:1', 'run-main', 'correlation-main');
    search_reindex_assert($scheduled->state === 'scheduled', 'Schedule must persist a resumable run.');
    search_reindex_assert($sourceFactory->createCount === 0, 'Scheduling must not resolve or read the source.');
    $scheduledFence = $connection->table('larena_search_provider_states')->where('provider_id', 'docara.pages')->first();
    search_reindex_assert(
        $scheduledFence !== null
        && (string) $scheduledFence->active_run_ref === $scheduled->runRef
        && (string) $scheduledFence->active_generation_ref === $scheduled->generationRef,
        'Schedule must atomically claim the durable provider fence.',
    );
    search_reindex_assert(
        $connection->table('larena_audit_events')->where('event_type', 'search.reindex.scheduled')->count() === 1,
        'Schedule-only state must be covered by Security Audit.',
    );

    $oneActiveRejected = false;
    try {
        $service->schedule('docara.pages', 'user:admin_identity:1', 'run-duplicate');
    } catch (SearchReindexRejected $exception) {
        $oneActiveRejected = $exception->reasonCode === 'search_reindex_already_active';
    }
    search_reindex_assert($oneActiveRejected, 'Only one active reindex may exist per provider.');

    $wrongProviderRejected = false;
    try {
        $service->resume($scheduled->runRef, 'user:admin_identity:1', 1, 1, 'other.pages');
    } catch (SearchReindexRejected $exception) {
        $wrongProviderRejected = $exception->reasonCode === 'search_reindex_run_provider_mismatch';
    }
    search_reindex_assert($wrongProviderRejected, 'Expected provider mismatch must fail after Access without existence leakage.');
    $unchangedScheduled = $service->find($scheduled->runRef);
    search_reindex_assert(
        $unchangedScheduled?->state === 'scheduled' && $unchangedScheduled->processedCount === 0,
        'Protocol rejection must not mark or mutate a scheduled run.',
    );
    foreach ([
        'resume' => 'search_reindex_not_running',
        'retry' => 'search_reindex_not_retryable',
    ] as $method => $reason) {
        try {
            $service->{$method}($scheduled->runRef, 'user:admin_identity:1', 1, 1, 'docara.pages');
            throw new RuntimeException("{$method} must reject a scheduled run.");
        } catch (SearchReindexRejected $exception) {
            search_reindex_assert($exception->reasonCode === $reason, "{$method} returned the wrong scheduled-state reason.");
        }
    }

    $interrupted = $service->run($scheduled->runRef, 'user:admin_identity:1', 1, 1);
    search_reindex_assert($interrupted->state === 'running' && $interrupted->cursor === 'page:a', 'maxBatches must leave a resumable checkpoint.');
    search_reindex_assert($sourceFactory->createCount === 1, 'Each processed batch must resolve its source exactly once.');
    foreach ([
        'run' => 'search_reindex_not_scheduled',
        'retry' => 'search_reindex_not_retryable',
    ] as $method => $reason) {
        try {
            $service->{$method}($scheduled->runRef, 'user:admin_identity:1', 1, 1);
            throw new RuntimeException("{$method} must reject a running run.");
        } catch (SearchReindexRejected $exception) {
            search_reindex_assert($exception->reasonCode === $reason, "{$method} returned the wrong running-state reason.");
        }
    }

    $source->fail = true;
    $sourceFailureSanitized = false;
    try {
        $service->resume($scheduled->runRef, 'user:admin_identity:1', 1);
    } catch (SearchReindexRejected $exception) {
        $sourceFailureSanitized = $exception->reasonCode === 'search_reindex_source_failed'
            && !str_contains($exception->getMessage(), 'raw source detail');
    }
    search_reindex_assert($sourceFailureSanitized, 'Source failure must cross the public boundary as a sanitized reason.');
    search_reindex_assert($sourceFactory->createCount === 2, 'A failed batch must resolve the source immediately before its read.');
    search_reindex_assert($service->find($scheduled->runRef)?->state === 'failed', 'Failed run must remain resumable and active.');
    $failedFence = $connection->table('larena_search_provider_states')->where('provider_id', 'docara.pages')->first();
    search_reindex_assert(
        $failedFence !== null
        && (string) $failedFence->active_run_ref === $scheduled->runRef
        && (string) $failedFence->active_generation_ref === $scheduled->generationRef,
        'Failed/resumable runs must retain the durable provider fence.',
    );

    $realtime = new SearchProjection(
        'docara.pages', 'page:a', 2, 'Page A live', '/docs/a-live', searchableText: 'realtime newest revision',
    );
    $index->upsert($realtime);
    $generation = $connection->table('larena_search_documents')->where('source_ref', 'page:a')->value('generation_ref');
    search_reindex_assert($generation === $scheduled->generationRef, 'Realtime writes during failed/resumable state must join the active generation.');

    $source->fail = false;
    foreach ([
        'run' => 'search_reindex_not_scheduled',
        'resume' => 'search_reindex_not_running',
    ] as $method => $reason) {
        try {
            $service->{$method}($scheduled->runRef, 'user:admin_identity:1', 1, 1);
            throw new RuntimeException("{$method} must reject a failed run.");
        } catch (SearchReindexRejected $exception) {
            search_reindex_assert($exception->reasonCode === $reason, "{$method} returned the wrong failed-state reason.");
        }
    }
    $completed = $service->retry($scheduled->runRef, 'user:admin_identity:1', 1);
    search_reindex_assert($completed->isComplete(), 'Retry must complete from the durable failed checkpoint.');
    search_reindex_assert($sourceFactory->createCount === 4, 'Every retry/continuation batch must resolve the source again without registry caching.');
    search_reindex_assert(count($index->query(new SearchQuery('realtime newest'))) === 1, 'Final sweep must retain concurrent active-generation writes.');
    search_reindex_assert(count($index->query(new SearchQuery('remove me'))) === 0, 'Final sweep must tombstone documents absent from the source.');
    search_reindex_assert(
        (string) $connection->table('larena_search_source_states')->where('source_ref', 'page:orphan')->value('state') === 'removed',
        'Final sweep deletion must leave a monotonic tombstone.',
    );
    $completedFence = $connection->table('larena_search_provider_states')->where('provider_id', 'docara.pages')->first();
    search_reindex_assert(
        $completedFence !== null
        && $completedFence->active_run_ref === null
        && $completedFence->active_generation_ref === null,
        'Completion must clear but retain the permanent provider fence row.',
    );

    $publishFirstProjection = new SearchProjection(
        'race.publish_first', 'page:first', 1, 'Publish first', '/race/publish-first', searchableText: 'publish first survives',
    );
    $index->upsert($publishFirstProjection);
    search_reindex_assert(
        $connection->table('larena_search_documents')->where('provider_id', 'race.publish_first')->value('generation_ref') === null,
        'A publish linearized before the first schedule starts outside a generation.',
    );
    $registry->register(new SearchReindexTestSource('race.publish_first', [$publishFirstProjection]));
    $publishFirstRun = $service->schedule('race.publish_first', 'user:admin_identity:1', 'run-publish-first');
    $publishFirstCompleted = $service->run($publishFirstRun->runRef, 'user:admin_identity:1');
    search_reindex_assert($publishFirstCompleted->isComplete(), 'Publish-first linearization must complete.');
    search_reindex_assert(
        $connection->table('larena_search_documents')
            ->where('provider_id', 'race.publish_first')
            ->where('source_ref', 'page:first')
            ->exists(),
        'The equal-revision source pass must refresh generation and retain a publish linearized before schedule.',
    );
    search_reindex_assert(
        (string) $connection->table('larena_search_source_states')
            ->where('provider_id', 'race.publish_first')
            ->where('source_ref', 'page:first')
            ->value('state') === 'indexed',
        'Publish-first ordering must not create an equal-revision tombstone lockout.',
    );

    $registry->register(new SearchReindexTestSource('race.schedule_first', []));
    $scheduleFirstRun = $service->schedule('race.schedule_first', 'user:admin_identity:1', 'run-schedule-first');
    $scheduleFirstProjection = new SearchProjection(
        'race.schedule_first', 'page:first', 1, 'Schedule first', '/race/schedule-first', searchableText: 'schedule first survives',
    );
    $index->upsert($scheduleFirstProjection);
    search_reindex_assert(
        (string) $connection->table('larena_search_documents')
            ->where('provider_id', 'race.schedule_first')
            ->where('source_ref', 'page:first')
            ->value('generation_ref') === $scheduleFirstRun->generationRef,
        'A realtime publish linearized after schedule must join the active generation.',
    );
    $scheduleFirstCompleted = $service->run($scheduleFirstRun->runRef, 'user:admin_identity:1');
    search_reindex_assert($scheduleFirstCompleted->isComplete(), 'Schedule-first linearization must complete.');
    search_reindex_assert(
        $connection->table('larena_search_documents')
            ->where('provider_id', 'race.schedule_first')
            ->where('source_ref', 'page:first')
            ->exists(),
        'Final sweep must retain a post-schedule realtime publish even when the source batch is empty.',
    );

    $newerRun = $service->schedule('race.schedule_first', 'user:admin_identity:1', 'run-after-completed');
    try {
        $service->run($scheduleFirstRun->runRef, 'user:admin_identity:1');
        throw new RuntimeException('Run must reject a completed stale run.');
    } catch (SearchReindexRejected $exception) {
        search_reindex_assert($exception->reasonCode === 'search_reindex_not_scheduled', 'Completed stale run must fail with the stable run-state reason.');
    }
    $newerFence = $connection->table('larena_search_provider_states')->where('provider_id', 'race.schedule_first')->first();
    search_reindex_assert(
        $newerFence !== null
        && (string) $newerFence->active_run_ref === $newerRun->runRef
        && (string) $newerFence->active_generation_ref === $newerRun->generationRef,
        'A rejected completed stale run must not clear a newer provider fence.',
    );
    $service->run($newerRun->runRef, 'user:admin_identity:1');

    foreach (['search.reindex.scheduled', 'search.reindex.operation_started', 'search.reindex.checkpointed', 'search.reindex.completed', 'search.reindex.rejected', 'search.reindex.failed'] as $eventType) {
        search_reindex_assert(
            $connection->table('larena_audit_events')->where('event_type', $eventType)->exists(),
            "Missing Security Audit event {$eventType}.",
        );
    }
    $operationPayloads = $connection->table('larena_audit_events')
        ->where('source_package', 'larena/search')
        ->pluck('payload')
        ->map(static fn (mixed $payload): string => (string) (json_decode((string) $payload, true, 512, JSON_THROW_ON_ERROR)['operation'] ?? ''))
        ->all();
    foreach (['schedule', 'run', 'resume', 'retry', 'continue'] as $operation) {
        search_reindex_assert(in_array($operation, $operationPayloads, true), "Missing truthful Audit operation {$operation}.");
    }
    $mainAuditRows = $connection->table('larena_audit_events')
        ->where('correlation_id', 'correlation-main')
        ->orderBy('id')
        ->get(['event_type', 'payload']);
    $mainAudit = array_map(static function (object $row): array {
        $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);

        return ['type' => (string) $row->event_type, 'operation' => (string) ($payload['operation'] ?? '')];
    }, $mainAuditRows->all());
    search_reindex_assert(
        in_array(['type' => 'search.reindex.failed', 'operation' => 'resume'], $mainAudit, true),
        'A failed resume must retain resume identity in Audit.',
    );
    search_reindex_assert(
        in_array(['type' => 'search.reindex.operation_started', 'operation' => 'retry'], $mainAudit, true),
        'Retry must have its own operation-start identity in Audit.',
    );
    search_reindex_assert(
        !in_array(['type' => 'search.reindex.operation_started', 'operation' => 'resume'], $mainAudit, true),
        'A resume that rolls back before processing must not be recorded as a successful start.',
    );
    search_reindex_assert(
        !$connection->table('larena_audit_events')->where('event_type', 'search.reindex.resumed')->exists(),
        'Legacy ambiguous resume Audit identity must not remain active.',
    );
    foreach ($connection->table('larena_audit_events')->where('source_package', 'larena/search')->pluck('payload') as $payload) {
        search_reindex_assert(
            preg_match('/password|session_id|cookie|token|secret|query|title|snippet|body/i', (string) $payload) !== 1,
            'Search reindex Audit payload must contain identifiers/counters only.',
        );
    }
    $authorizedOperations = array_values(array_unique($authorizer->operations));
    sort($authorizedOperations);
    search_reindex_assert(
        $authorizedOperations === ['search.reindex.resume', 'search.reindex.retry', 'search.reindex.run', 'search.reindex.schedule'],
        'Schedule, run, resume and retry must have separate canonical Access checks.',
    );

    $registry->registerFactory(new SearchReindexThrowingFactory());
    $factoryFailureRun = $service->schedule('factory.fail', 'user:admin_identity:1', 'run-factory-failure');
    $factoryFailureSanitized = false;
    try {
        $service->run($factoryFailureRun->runRef, 'user:admin_identity:1');
    } catch (SearchReindexRejected $exception) {
        $factoryFailureSanitized = $exception->reasonCode === 'search_reindex_source_failed'
            && !str_contains($exception->getMessage(), 'raw_sensitive_factory_detail');
    }
    search_reindex_assert($factoryFailureSanitized, 'Factory failures must cross the public boundary as a sanitized reason.');
    $persistedFactoryFailure = $service->find($factoryFailureRun->runRef);
    search_reindex_assert(
        $persistedFactoryFailure?->state === 'failed'
        && $persistedFactoryFailure->errorCode === 'search_reindex_source_failed',
        'A forged factory reason must persist only the sanitized failure code.',
    );
    $factoryFailureAuditPayload = (string) $connection->table('larena_audit_events')
        ->where('event_type', 'search.reindex.failed')
        ->where('correlation_id', $factoryFailureRun->correlationId)
        ->orderByDesc('id')
        ->value('payload');
    search_reindex_assert(
        str_contains($factoryFailureAuditPayload, 'search_reindex_source_failed')
        && str_contains($factoryFailureAuditPayload, '"operation":"run"')
        && !str_contains($factoryFailureAuditPayload, 'raw_sensitive_factory_detail'),
        'Factory failure Audit must contain the sanitized reason and exact operation.',
    );

    $factoryFailureCommand = new ReindexSearchCommand($service);
    $factoryFailureCommand->setLaravel(new SearchReindexTestConsoleApplication());
    $missingOperationTester = new CommandTester($factoryFailureCommand);
    $missingOperationExitCode = $missingOperationTester->execute([
        'provider' => 'factory.fail',
        '--actor' => 'user:admin_identity:1',
        '--run' => $factoryFailureRun->runRef,
    ]);
    search_reindex_assert(
        $missingOperationExitCode === ReindexSearchCommand::FAILURE
        && str_contains($missingOperationTester->getDisplay(), '--run requires an explicit --operation'),
        'CLI must reject an existing run without an explicit operation before service execution.',
    );
    $factoryFailureCommandTester = new CommandTester($factoryFailureCommand);
    $factoryFailureExitCode = $factoryFailureCommandTester->execute([
        'provider' => 'factory.fail',
        '--actor' => 'user:admin_identity:1',
        '--run' => $factoryFailureRun->runRef,
        '--operation' => 'retry',
    ]);
    $factoryFailureDisplay = $factoryFailureCommandTester->getDisplay();
    search_reindex_assert($factoryFailureExitCode === ReindexSearchCommand::FAILURE, 'CLI must fail for a factory resolution error.');
    search_reindex_assert(
        str_contains($factoryFailureDisplay, 'search_reindex_source_failed')
        && !str_contains($factoryFailureDisplay, 'raw_sensitive_factory_detail'),
        'CLI output must expose only the sanitized factory failure reason.',
    );

    $registry->registerFactory(new SearchReindexMismatchedFactory());
    $factoryMismatchRun = $service->schedule('factory.expected', 'user:admin_identity:1', 'run-factory-mismatch');
    $factoryMismatchRejected = false;
    try {
        $service->run($factoryMismatchRun->runRef, 'user:admin_identity:1');
    } catch (SearchReindexRejected $exception) {
        $factoryMismatchRejected = $exception->reasonCode === 'search_reindex_source_provider_mismatch';
    }
    search_reindex_assert($factoryMismatchRejected, 'Registry-generated provider mismatch must retain its exact stable reason.');
    search_reindex_assert(
        $service->find($factoryMismatchRun->runRef)?->errorCode === 'search_reindex_source_provider_mismatch',
        'Registry-generated provider mismatch must persist only its exact stable reason.',
    );

    $deniedAuthorizer = new SearchReindexTestAuthorizer(false);
    $denied = search_reindex_service($connection, $index, $registry, $deniedAuthorizer, $goodPipeline);
    $runCountBeforeDenial = $connection->table('larena_search_reindex_runs')->count();
    try {
        $denied->schedule('docara.pages', 'user:forbidden');
        throw new RuntimeException('Denied actor must not schedule Search reindex.');
    } catch (AccessMutationRejected $exception) {
        search_reindex_assert($exception->reasonCode === 'access_actor_forbidden', 'Access denial must remain explicit.');
    }
    search_reindex_assert($connection->table('larena_search_reindex_runs')->count() === $runCountBeforeDenial, 'Access denial must not mutate reindex state.');

    $failingSource = new SearchReindexTestSource('audit.fail', [
        new SearchProjection('audit.fail', 'item:1', 1, 'Item', '/item/1', searchableText: 'audit rollback'),
    ]);
    $registry->register($failingSource);
    $failingPipeline = new AuditEventPipeline(new DefaultAuditRedactor(), [
        new DatabaseAuditSink($connection),
        new SearchReindexThrowingSink(),
    ]);
    $failingService = search_reindex_service($connection, $index, $registry, new SearchReindexTestAuthorizer(), $failingPipeline);
    $auditCountBefore = $connection->table('larena_audit_events')->count();
    $scheduleRolledBack = false;
    try {
        $failingService->schedule('audit.fail', 'user:admin_identity:1', 'run-audit-rollback');
    } catch (SearchPersistenceFailed $exception) {
        $scheduleRolledBack = $exception->getMessage() === 'search_persistence_failed';
    }
    search_reindex_assert($scheduleRolledBack, 'Audit sink failure must be sanitized.');
    search_reindex_assert(!$connection->table('larena_search_reindex_runs')->where('run_ref', 'run-audit-rollback')->exists(), 'Audit failure must roll back scheduled run.');
    search_reindex_assert(
        !$connection->table('larena_search_provider_states')->where('provider_id', 'audit.fail')->exists(),
        'Audit failure must roll back the first durable provider fence row and its claim.',
    );
    search_reindex_assert($connection->table('larena_audit_events')->count() === $auditCountBefore, 'Audit failure must roll back its earlier database sink write.');

    $goodAuditFailService = search_reindex_service($connection, $index, $registry, new SearchReindexTestAuthorizer(), $goodPipeline);
    $auditRun = $goodAuditFailService->schedule('audit.fail', 'user:admin_identity:1', 'run-checkpoint-rollback');
    $checkpointRolledBack = false;
    try {
        $failingService->run($auditRun->runRef, 'user:admin_identity:1', 10);
    } catch (SearchPersistenceFailed $exception) {
        $checkpointRolledBack = $exception->getMessage() === 'search_persistence_failed';
    }
    search_reindex_assert($checkpointRolledBack, 'Checkpoint Audit failure must be sanitized.');
    search_reindex_assert($goodAuditFailService->find($auditRun->runRef)?->state === 'scheduled', 'Checkpoint Audit failure must roll back run state/cursor.');
    search_reindex_assert(!$connection->table('larena_search_documents')->where('provider_id', 'audit.fail')->exists(), 'Checkpoint Audit failure must roll back indexed documents.');
    $checkpointFence = $connection->table('larena_search_provider_states')->where('provider_id', 'audit.fail')->first();
    search_reindex_assert(
        $checkpointFence !== null
        && (string) $checkpointFence->active_run_ref === $auditRun->runRef
        && (string) $checkpointFence->active_generation_ref === $auditRun->generationRef,
        'Checkpoint Audit rollback must preserve the scheduled provider fence for retry.',
    );
} finally {
    $database->close();
}

echo "SearchReindexServiceTest passed.\n";
