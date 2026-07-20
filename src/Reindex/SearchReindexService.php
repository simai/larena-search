<?php

declare(strict_types=1);

namespace Larena\Search\Reindex;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Audit\Contracts\AuditEvent;
use Larena\Audit\Enums\AuditRetentionClass;
use Larena\Audit\Enums\AuditSeverity;
use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Search\Audit\SearchReindexAuditEventDescriptor;
use Larena\Search\Contracts\ReindexRun;
use Larena\Search\Contracts\SearchProjection;
use Larena\Search\Exceptions\SearchPersistenceFailed;
use Larena\Search\Exceptions\SearchReindexRejected;
use Larena\Search\Persistence\DatabaseSearchIndex;
use Larena\Search\Persistence\ProviderGenerationFence;
use Larena\Search\Runtime\SearchSourceRegistry;
use stdClass;
use Throwable;

final readonly class SearchReindexService
{
    private ProviderGenerationFence $providerFence;

    public function __construct(
        private ConnectionInterface $database,
        private DatabaseSearchIndex $index,
        private SearchSourceRegistry $sources,
        private ActorOperationAuthorizer $authorizer,
        private AuditEventPipeline $audit,
    ) {
        if ($index->connection() !== $database) {
            throw new InvalidArgumentException('search_reindex_connection_mismatch');
        }
        $this->providerFence = new ProviderGenerationFence($database);
    }

    public function connection(): ConnectionInterface
    {
        return $this->database;
    }

    public function schedule(
        string $providerId,
        string $actor,
        ?string $runRef = null,
        ?string $correlationId = null,
    ): ReindexRun {
        $this->assertProviderId($providerId);
        $this->assertActor($actor);
        $this->authorizer->assertAllowed($actor, 'search.reindex.schedule');
        if (!$this->sources->has($providerId)) {
            throw new SearchReindexRejected('search_reindex_source_unknown');
        }

        $runRef = $this->validatedReference($runRef ?? 'search-' . bin2hex(random_bytes(16)), 'search_reindex_run_ref_invalid', 64);
        $correlationId = $this->validatedReference($correlationId ?? $runRef, 'search_reindex_correlation_invalid', 191);
        $generationRef = 'generation-' . bin2hex(random_bytes(12));

        try {
            return $this->database->transaction(function () use ($providerId, $actor, $runRef, $correlationId, $generationRef): ReindexRun {
                $providerState = $this->providerFence->lock($providerId);
                if ($providerState->isActive()) {
                    throw new SearchReindexRejected('search_reindex_already_active');
                }

                $timestamp = $this->timestamp();
                $this->database->table('larena_search_reindex_runs')->insert([
                    'run_ref' => $runRef,
                    'provider_id' => $providerId,
                    'active_provider_id' => $providerId,
                    'generation_ref' => $generationRef,
                    'state' => 'scheduled',
                    'cursor' => null,
                    'processed_count' => 0,
                    'batch_count' => 0,
                    'requested_by' => $actor,
                    'correlation_id' => $correlationId,
                    'error_code' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
                $this->providerFence->activate($providerState, $runRef, $generationRef);

                $run = new ReindexRun($runRef, $providerId, $generationRef, 'scheduled', null, 0, 0, $actor, $correlationId);
                $this->audit('search.reindex.started', $run, $actor, []);

                return $run;
            });
        } catch (SearchReindexRejected $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw SearchPersistenceFailed::from($exception);
        }
    }

    public function run(string $runRef, string $actor, int $batchSize = 100, int $maxBatches = 0): ReindexRun
    {
        $this->assertActor($actor);
        $this->authorizer->assertAllowed($actor, 'search.reindex.run');

        return $this->execute($runRef, $actor, $batchSize, $maxBatches, false, null);
    }

    public function resume(
        string $runRef,
        string $actor,
        int $batchSize = 100,
        int $maxBatches = 0,
        ?string $expectedProviderId = null,
    ): ReindexRun {
        $this->assertActor($actor);
        $this->authorizer->assertAllowed($actor, 'search.reindex.resume');
        if ($expectedProviderId !== null) {
            $this->assertProviderId($expectedProviderId);
        }

        return $this->execute($runRef, $actor, $batchSize, $maxBatches, true, $expectedProviderId);
    }

    public function find(string $runRef): ?ReindexRun
    {
        try {
            $row = $this->database->table('larena_search_reindex_runs')->where('run_ref', $runRef)->first();

            return $row instanceof stdClass ? $this->hydrate($row) : null;
        } catch (Throwable $exception) {
            throw SearchPersistenceFailed::from($exception);
        }
    }

    private function execute(
        string $runRef,
        string $actor,
        int $batchSize,
        int $maxBatches,
        bool $resuming,
        ?string $expectedProviderId,
    ): ReindexRun
    {
        $runRef = $this->validatedReference($runRef, 'search_reindex_run_ref_invalid', 64);
        $this->assertActor($actor);
        if ($batchSize < 1 || $batchSize > 1000 || $maxBatches < 0 || $maxBatches > 100000) {
            throw new InvalidArgumentException('search_reindex_batch_bounds_invalid');
        }

        $processedBatches = 0;
        $firstBatch = true;

        try {
            do {
                $run = $this->processBatch($runRef, $actor, $batchSize, $resuming, $firstBatch, $expectedProviderId);
                $firstBatch = false;
                $processedBatches++;
                if ($run->isComplete() || ($maxBatches > 0 && $processedBatches >= $maxBatches)) {
                    return $run;
                }
            } while (true);
        } catch (Throwable $exception) {
            if (!$this->isProtocolRejection($exception)) {
                $this->recordFailure($runRef, $actor, $this->failureCode($exception));
            }

            if ($exception instanceof SearchReindexRejected || $exception instanceof SearchPersistenceFailed || $exception instanceof InvalidArgumentException) {
                throw $exception;
            }

            throw new SearchReindexRejected('search_reindex_failed', $exception);
        }
    }

    private function processBatch(
        string $runRef,
        string $actor,
        int $batchSize,
        bool $resuming,
        bool $firstBatch,
        ?string $expectedProviderId,
    ): ReindexRun {
        try {
            return $this->database->transaction(function () use ($runRef, $actor, $batchSize, $resuming, $firstBatch, $expectedProviderId): ReindexRun {
                $row = $this->database->table('larena_search_reindex_runs')
                    ->where('run_ref', $runRef)
                    ->lockForUpdate()
                    ->first();
                if (!$row instanceof stdClass) {
                    throw new SearchReindexRejected('search_reindex_run_unknown');
                }

                $run = $this->hydrate($row);
                if ($expectedProviderId !== null && $run->providerId !== $expectedProviderId) {
                    throw new SearchReindexRejected('search_reindex_run_provider_mismatch');
                }
                if ($run->isComplete()) {
                    return $run;
                }
                if ($resuming && !in_array($run->state, ['scheduled', 'running', 'failed'], true)) {
                    throw new SearchReindexRejected('search_reindex_not_resumable');
                }
                if (!$resuming && $run->state !== 'scheduled' && !($run->state === 'running' && !$firstBatch)) {
                    throw new SearchReindexRejected('search_reindex_not_scheduled');
                }

                $providerState = $this->providerFence->lock($run->providerId);
                $this->providerFence->assertActive($providerState, $run->runRef, $run->generationRef);

                if ($firstBatch) {
                    $this->database->table('larena_search_reindex_runs')->where('run_ref', $runRef)->update([
                        'state' => 'running',
                        'error_code' => null,
                        'updated_at' => $this->timestamp(),
                    ]);
                    if ($resuming) {
                        $this->audit('search.reindex.resumed', $run, $actor, []);
                    }
                }

                try {
                    $source = $this->sources->get($run->providerId);
                } catch (SearchReindexRejected $exception) {
                    if ($exception->reasonCode === 'search_reindex_source_provider_mismatch') {
                        throw $exception;
                    }

                    throw new SearchReindexRejected('search_reindex_source_failed', $exception);
                } catch (Throwable $exception) {
                    throw new SearchReindexRejected('search_reindex_source_failed', $exception);
                }
                if ($source === null) {
                    throw new SearchReindexRejected('search_reindex_source_unknown');
                }

                try {
                    $batch = $source->readBatch($run->cursor, $batchSize);
                } catch (SearchPersistenceFailed $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    throw new SearchReindexRejected('search_reindex_source_failed', $exception);
                }
                if (count($batch->projections) > $batchSize || ($batch->hasMore && $batch->projections === [])) {
                    throw new SearchReindexRejected('search_reindex_batch_invalid');
                }
                if ($batch->hasMore && $batch->nextCursor === $run->cursor) {
                    throw new SearchReindexRejected('search_reindex_cursor_not_advancing');
                }

                foreach ($batch->projections as $projection) {
                    if ($projection->providerId !== $run->providerId) {
                        throw new SearchReindexRejected('search_reindex_projection_provider_mismatch');
                    }
                    $this->index->upsert($projection, $run->generationRef);
                }

                $processed = $run->processedCount + count($batch->projections);
                $batchCount = $run->batchCount + 1;
                $cursor = $batch->nextCursor ?? $run->cursor;
                $this->database->table('larena_search_reindex_runs')->where('run_ref', $runRef)->update([
                    'state' => 'running',
                    'cursor' => $cursor,
                    'processed_count' => $processed,
                    'batch_count' => $batchCount,
                    'error_code' => null,
                    'updated_at' => $this->timestamp(),
                ]);

                $checkpoint = new ReindexRun(
                    $run->runRef,
                    $run->providerId,
                    $run->generationRef,
                    'running',
                    $cursor,
                    $processed,
                    $batchCount,
                    $run->requestedBy,
                    $run->correlationId,
                );
                $this->audit('search.reindex.checkpointed', $checkpoint, $actor, [
                    'batch_size' => count($batch->projections),
                    'cursor_hash' => $cursor === null ? null : hash('sha256', $cursor),
                ]);

                if ($batch->hasMore) {
                    return $checkpoint;
                }

                $removed = $this->index->removeMissingFromGeneration($run->providerId, $run->generationRef);
                $this->providerFence->clear($providerState, $run->runRef, $run->generationRef);
                $this->database->table('larena_search_reindex_runs')->where('run_ref', $runRef)->update([
                    'state' => 'completed',
                    'active_provider_id' => null,
                    'error_code' => null,
                    'updated_at' => $this->timestamp(),
                ]);
                $completed = new ReindexRun(
                    $run->runRef,
                    $run->providerId,
                    $run->generationRef,
                    'completed',
                    $cursor,
                    $processed,
                    $batchCount,
                    $run->requestedBy,
                    $run->correlationId,
                );
                $this->audit('search.reindex.completed', $completed, $actor, ['removed_count' => $removed]);

                return $completed;
            });
        } catch (SearchReindexRejected|SearchPersistenceFailed|InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw SearchPersistenceFailed::from($exception);
        }
    }

    private function recordFailure(string $runRef, string $actor, string $errorCode): void
    {
        try {
            $this->database->transaction(function () use ($runRef, $actor, $errorCode): void {
                $row = $this->database->table('larena_search_reindex_runs')
                    ->where('run_ref', $runRef)
                    ->lockForUpdate()
                    ->first();
                if (!$row instanceof stdClass || (string) $row->state === 'completed') {
                    return;
                }

                $run = $this->hydrate($row);
                $this->database->table('larena_search_reindex_runs')->where('run_ref', $runRef)->update([
                    'state' => 'failed',
                    'error_code' => $errorCode,
                    'updated_at' => $this->timestamp(),
                ]);
                $this->audit('search.reindex.failed', $run, $actor, ['error_code' => $errorCode]);
            });
        } catch (Throwable) {
            // The original error remains authoritative; failed Audit cannot commit partial failure state.
        }
    }

    /** @param array<string, scalar|null> $extra */
    private function audit(string $type, ReindexRun $run, string $actor, array $extra): void
    {
        $descriptor = new SearchReindexAuditEventDescriptor($type);
        $this->audit->route($descriptor, AuditEvent::create(
            sourcePackage: 'larena/search',
            category: 'search_reindex',
            type: $type,
            actor: $actor,
            subject: 'search-reindex:' . $run->runRef,
            severity: AuditSeverity::Security,
            retentionClass: AuditRetentionClass::Security,
            correlationId: $run->correlationId,
            payload: [
                'provider_id' => $run->providerId,
                'run_ref' => $run->runRef,
                'generation_ref' => $run->generationRef,
                'processed_count' => $run->processedCount,
                'batch_count' => $run->batchCount,
            ] + $extra,
        ));
    }

    private function hydrate(stdClass $row): ReindexRun
    {
        return new ReindexRun(
            runRef: (string) $row->run_ref,
            providerId: (string) $row->provider_id,
            generationRef: (string) $row->generation_ref,
            state: (string) $row->state,
            cursor: $row->cursor === null ? null : (string) $row->cursor,
            processedCount: (int) $row->processed_count,
            batchCount: (int) $row->batch_count,
            requestedBy: (string) $row->requested_by,
            correlationId: (string) $row->correlation_id,
            errorCode: $row->error_code === null ? null : (string) $row->error_code,
        );
    }

    private function failureCode(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof SearchReindexRejected => $exception->reasonCode,
            $exception instanceof SearchPersistenceFailed => 'search_persistence_failed',
            default => 'search_reindex_source_failed',
        };
    }

    private function isProtocolRejection(Throwable $exception): bool
    {
        return $exception instanceof SearchReindexRejected && in_array($exception->reasonCode, [
            'search_reindex_run_unknown',
            'search_reindex_run_provider_mismatch',
            'search_reindex_not_resumable',
            'search_reindex_not_scheduled',
        ], true);
    }

    private function assertProviderId(string $providerId): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,119}$/', $providerId) !== 1) {
            throw new InvalidArgumentException('search_provider_id_invalid');
        }
    }

    private function assertActor(string $actor): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]*:[A-Za-z0-9_.@:-]{1,170}$/', $actor) !== 1) {
            throw new InvalidArgumentException('search_actor_invalid');
        }
    }

    private function validatedReference(string $reference, string $error, int $maxLength): string
    {
        if ($reference === '' || strlen($reference) > $maxLength || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/', $reference) !== 1) {
            throw new InvalidArgumentException($error);
        }

        return $reference;
    }

    private function timestamp(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
