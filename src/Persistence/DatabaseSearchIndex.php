<?php

declare(strict_types=1);

namespace Larena\Search\Persistence;

use Illuminate\Database\ConnectionInterface;
use Larena\Search\Contracts\SearchHit;
use Larena\Search\Contracts\SearchPage;
use Larena\Search\Contracts\SearchProjection;
use Larena\Search\Contracts\SearchQuery;
use Larena\Search\Contracts\SearchWriteResult;
use Larena\Search\Exceptions\SearchPersistenceFailed;
use Larena\Search\Exceptions\SearchRevisionConflict;
use InvalidArgumentException;
use stdClass;
use Throwable;

final readonly class DatabaseSearchIndex
{
    private ProviderGenerationFence $providerFence;

    public function __construct(private ConnectionInterface $database)
    {
        $this->providerFence = new ProviderGenerationFence($database);
    }

    public function connection(): ConnectionInterface
    {
        return $this->database;
    }

    public function upsert(SearchProjection $projection, ?string $generationRef = null): SearchWriteResult
    {
        try {
            return $this->database->transaction(function () use ($projection, $generationRef): SearchWriteResult {
                $providerState = $this->providerFence->lock($projection->providerId);
                $writeGenerationRef = $this->providerFence->generationForWrite($providerState, $generationRef);
                $this->ensureStateRow($projection->providerId, $projection->sourceRef);
                $state = $this->lockedState($projection->providerId, $projection->sourceRef);
                $storedRevision = (int) $state->source_revision;
                $storedState = (string) $state->state;
                $contentHash = $projection->contentHash();

                if ($projection->sourceRevision < $storedRevision) {
                    return SearchWriteResult::unchanged('stale_revision', $projection->providerId, $projection->sourceRef, $projection->sourceRevision);
                }

                if ($projection->sourceRevision === $storedRevision) {
                    if ($storedState === 'removed') {
                        return SearchWriteResult::unchanged('tombstone_wins', $projection->providerId, $projection->sourceRef, $projection->sourceRevision);
                    }
                    if ((string) $state->projection_hash !== $contentHash) {
                        throw new SearchRevisionConflict();
                    }

                    $wasMissing = !$this->documentExists($projection->providerId, $projection->sourceRef);
                    $this->persistDocument($projection, $contentHash, $writeGenerationRef);
                    $this->touchIndexedState($projection, $contentHash, $writeGenerationRef);

                    return $wasMissing
                        ? SearchWriteResult::changed('repaired', $projection->providerId, $projection->sourceRef, $projection->sourceRevision)
                        : SearchWriteResult::unchanged('idempotent', $projection->providerId, $projection->sourceRef, $projection->sourceRevision);
                }

                $this->persistDocument($projection, $contentHash, $writeGenerationRef);
                $this->touchIndexedState($projection, $contentHash, $writeGenerationRef);

                return SearchWriteResult::changed('indexed', $projection->providerId, $projection->sourceRef, $projection->sourceRevision);
            });
        } catch (SearchRevisionConflict|SearchPersistenceFailed|InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw SearchPersistenceFailed::from($exception);
        }
    }

    public function remove(
        string $providerId,
        string $sourceRef,
        int $sourceRevision,
        ?string $generationRef = null,
    ): SearchWriteResult {
        $this->assertIdentity($providerId, $sourceRef, $sourceRevision);

        try {
            return $this->database->transaction(function () use ($providerId, $sourceRef, $sourceRevision, $generationRef): SearchWriteResult {
                $providerState = $this->providerFence->lock($providerId);
                $writeGenerationRef = $this->providerFence->generationForWrite($providerState, $generationRef);
                $this->ensureStateRow($providerId, $sourceRef);
                $state = $this->lockedState($providerId, $sourceRef);
                $storedRevision = (int) $state->source_revision;
                $storedState = (string) $state->state;

                if ($sourceRevision < $storedRevision) {
                    return SearchWriteResult::unchanged('stale_revision', $providerId, $sourceRef, $sourceRevision);
                }
                if ($sourceRevision === $storedRevision && $storedState === 'removed') {
                    $this->touchRemovedState($providerId, $sourceRef, $sourceRevision, $writeGenerationRef);

                    return SearchWriteResult::unchanged('idempotent', $providerId, $sourceRef, $sourceRevision);
                }

                $this->database->table('larena_search_documents')
                    ->where('provider_id', $providerId)
                    ->where('source_ref', $sourceRef)
                    ->delete();
                $this->touchRemovedState($providerId, $sourceRef, $sourceRevision, $writeGenerationRef);

                return SearchWriteResult::changed('removed', $providerId, $sourceRef, $sourceRevision);
            });
        } catch (SearchRevisionConflict|SearchPersistenceFailed|InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw SearchPersistenceFailed::from($exception);
        }
    }

    /** @return list<SearchHit> */
    public function query(SearchQuery $query): array
    {
        return $this->queryPage($query)->hits;
    }

    public function queryPage(SearchQuery $query): SearchPage
    {
        try {
            $needle = mb_strtolower(trim($query->term));
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle);
            $builder = $this->database->table('larena_search_documents')
                ->whereIn('access_scope', array_values(array_unique($query->accessScopes)))
                ->whereRaw("LOWER(searchable_text) LIKE ? ESCAPE '!'", ['%' . $escaped . '%']);

            if ($query->providerId !== null) {
                $builder->where('provider_id', $query->providerId);
            }
            if ($query->locale !== null) {
                $builder->where('locale', $query->locale);
            }

            $rows = $builder
                ->orderBy('title')
                ->orderBy('provider_id')
                ->orderBy('source_ref')
                ->offset($query->offset)
                ->limit($query->limit + 1)
                ->get();

            $hasNext = $rows->count() > $query->limit;
            if ($hasNext) {
                $rows = $rows->take($query->limit);
            }

            $hits = [];
            foreach ($rows as $row) {
                $payload = $this->decodePayload((string) $row->payload);
                $hits[] = new SearchHit(
                    providerId: (string) $row->provider_id,
                    sourceRef: (string) $row->source_ref,
                    sourceRevision: (int) $row->source_revision,
                    title: (string) $row->title,
                    locator: (string) $row->locator,
                    snippet: (string) ($row->snippet ?? ''),
                    locale: $row->locale === null ? null : (string) $row->locale,
                    accessScope: (string) $row->access_scope,
                    payload: $payload,
                );
            }

            return new SearchPage(
                $hits,
                intdiv($query->offset, $query->limit) + 1,
                $query->limit,
                $hasNext,
            );
        } catch (SearchPersistenceFailed|InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw SearchPersistenceFailed::from($exception);
        }
    }

    public function removeMissingFromGeneration(string $providerId, string $generationRef): int
    {
        $this->assertProviderGeneration($providerId, $generationRef);

        try {
            return $this->database->transaction(function () use ($providerId, $generationRef): int {
                $providerState = $this->providerFence->lock($providerId);
                if ($providerState->activeGenerationRef !== $generationRef) {
                    throw new \RuntimeException('search_provider_generation_inactive');
                }

                $rows = $this->database->table('larena_search_documents')
                    ->where('provider_id', $providerId)
                    ->where(static function ($query) use ($generationRef): void {
                        $query->whereNull('generation_ref')->orWhere('generation_ref', '!=', $generationRef);
                    })
                    ->orderBy('source_ref')
                    ->get(['source_ref']);

                $removed = 0;
                foreach ($rows as $row) {
                    $sourceRef = (string) $row->source_ref;
                    $this->ensureStateRow($providerId, $sourceRef);
                    $state = $this->lockedState($providerId, $sourceRef);
                    $current = $this->database->table('larena_search_documents')
                        ->where('provider_id', $providerId)
                        ->where('source_ref', $sourceRef)
                        ->lockForUpdate()
                        ->first(['source_revision', 'generation_ref']);
                    if (!$current instanceof stdClass || (string) ($current->generation_ref ?? '') === $generationRef) {
                        continue;
                    }
                    $documentRevision = (int) $current->source_revision;
                    $stateRevision = (int) $state->source_revision;
                    if ((string) $state->state === 'indexed' && $stateRevision > $documentRevision) {
                        continue;
                    }

                    $tombstoneRevision = max($stateRevision, $documentRevision);
                    $this->database->table('larena_search_documents')
                        ->where('provider_id', $providerId)
                        ->where('source_ref', $sourceRef)
                        ->where('source_revision', $documentRevision)
                        ->where(static function ($query) use ($generationRef): void {
                            $query->whereNull('generation_ref')->orWhere('generation_ref', '!=', $generationRef);
                        })
                        ->delete();
                    $this->touchRemovedState($providerId, $sourceRef, $tombstoneRevision, $generationRef);
                    $removed++;
                }

                return $removed;
            });
        } catch (SearchRevisionConflict|SearchPersistenceFailed|InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw SearchPersistenceFailed::from($exception);
        }
    }

    private function ensureStateRow(string $providerId, string $sourceRef): void
    {
        $this->database->table('larena_search_source_states')->insertOrIgnore([
            'provider_id' => $providerId,
            'source_ref' => $sourceRef,
            'source_revision' => 0,
            'state' => 'removed',
            'projection_hash' => null,
            'generation_ref' => null,
            'updated_at' => $this->timestamp(),
        ]);
    }

    private function lockedState(string $providerId, string $sourceRef): stdClass
    {
        $state = $this->database->table('larena_search_source_states')
            ->where('provider_id', $providerId)
            ->where('source_ref', $sourceRef)
            ->lockForUpdate()
            ->first();

        if (!$state instanceof stdClass) {
            throw new SearchRevisionConflict();
        }

        return $state;
    }

    private function persistDocument(SearchProjection $projection, string $contentHash, ?string $generationRef): void
    {
        $timestamp = $this->timestamp();
        $identity = ['provider_id' => $projection->providerId, 'source_ref' => $projection->sourceRef];
        $values = [
                'source_revision' => $projection->sourceRevision,
                'title' => $projection->title,
                'locator' => $projection->locator,
                'snippet' => $projection->snippet === '' ? null : $projection->snippet,
                'locale' => $projection->locale,
                'access_scope' => $projection->accessScope,
                'searchable_text' => mb_strtolower($projection->title . "\n" . $projection->searchableText),
                'payload' => json_encode($projection->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'projection_hash' => $contentHash,
                'generation_ref' => $generationRef,
                'updated_at' => $timestamp,
        ];
        if ($this->documentExists($projection->providerId, $projection->sourceRef)) {
            $this->database->table('larena_search_documents')->where($identity)->update($values);
        } else {
            $this->database->table('larena_search_documents')->insert($identity + $values + ['created_at' => $timestamp]);
        }
    }

    private function touchIndexedState(SearchProjection $projection, string $contentHash, ?string $generationRef): void
    {
        $this->database->table('larena_search_source_states')
            ->where('provider_id', $projection->providerId)
            ->where('source_ref', $projection->sourceRef)
            ->update([
                'source_revision' => $projection->sourceRevision,
                'state' => 'indexed',
                'projection_hash' => $contentHash,
                'generation_ref' => $generationRef,
                'updated_at' => $this->timestamp(),
            ]);
    }

    private function touchRemovedState(string $providerId, string $sourceRef, int $sourceRevision, ?string $generationRef): void
    {
        $this->database->table('larena_search_source_states')
            ->where('provider_id', $providerId)
            ->where('source_ref', $sourceRef)
            ->update([
                'source_revision' => $sourceRevision,
                'state' => 'removed',
                'projection_hash' => null,
                'generation_ref' => $generationRef,
                'updated_at' => $this->timestamp(),
            ]);
    }

    private function documentExists(string $providerId, string $sourceRef): bool
    {
        return $this->database->table('larena_search_documents')
            ->where('provider_id', $providerId)
            ->where('source_ref', $sourceRef)
            ->exists();
    }

    private function assertProviderGeneration(string $providerId, string $generationRef): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,119}$/', $providerId) !== 1
            || $generationRef === ''
            || strlen($generationRef) > 64
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/', $generationRef) !== 1) {
            throw new InvalidArgumentException('search_provider_generation_invalid');
        }
    }

    private function assertIdentity(string $providerId, string $sourceRef, int $sourceRevision): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,119}$/', $providerId) !== 1
            || trim($sourceRef) === ''
            || strlen($sourceRef) > 191
            || $sourceRevision < 1) {
            throw new \InvalidArgumentException('search_source_identity_invalid');
        }
    }

    /** @return array<string, scalar|null> */
    private function decodePayload(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $safe = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key) && (is_scalar($value) || $value === null)) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    private function timestamp(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
