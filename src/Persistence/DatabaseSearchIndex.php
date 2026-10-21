<?php

declare(strict_types=1);

namespace Larena\Search\Persistence;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Larena\Search\Contracts\SearchCursor;
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
    public const PROBE_WINDOW = 1000;
    public const MAX_PROBE_WINDOW = 20000;
    // The rows in order a page may be looked for in, checked one by one, when the
    // window's matches say it lies within them; beyond, the FULLTEXT index answers.
    public const MAX_WALK = 100000;
    // ...and only when at least this share of the window matched: checking a row in
    // order costs about what reading a FULLTEXT candidate does, so a rarer term
    // reads fewer rows through the index.
    public const MIN_WALK_SHARE = 0.05;
    private const WALK = 'walk';
    private const INDEX = 'index';
    private const DOCUMENTS = 'larena_search_documents';
    private const TEXTS = 'larena_search_document_texts';

    private ProviderGenerationFence $providerFence;
    /** Per-instance answers from the server (the FULLTEXT token sizes). */
    private stdClass $memo;

    public function __construct(private ConnectionInterface $database)
    {
        $this->providerFence = new ProviderGenerationFence($database);
        $this->memo = new stdClass();
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

                $this->deleteTexts($providerId, $sourceRef);
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

    /**
     * One page of hits: every term of the query at the start of a word of the
     * title or searchable text (SearchText), within the query's access scopes,
     * provider and locale, ordered by the title's sort key and then the document
     * id. The page starts at `offset`, or after `after` when the query carries a
     * cursor; `nextCursor` names the page after this one.
     *
     * Documents are checked in order along the (access_scope, locale, title_key,
     * id) index, and the walk stops when the page is full. On MySQL a query with a
     * word of at least innodb_ft_min_token_size characters first checks a window
     * of the first documents in order (a common term fills its page there); when
     * the window does not hold the page, its share of matches decides: dense
     * enough, the walk goes on in order; otherwise the FULLTEXT index finds the
     * candidates (a rare term). Every way gives the same hits.
     */
    public function queryPage(SearchQuery $query): SearchPage
    {
        try {
            $page = intdiv($query->offset, $query->limit) + 1;
            $terms = SearchText::terms($query->term);
            if ($terms === []) {
                // A query of punctuation alone finds nothing.
                return new SearchPage([], $page, $query->limit, false);
            }
            $offset = $query->cursor === null ? $query->offset : 0;
            $keys = $this->matchedKeys($query, $terms, $offset);

            $hasNext = count($keys) > $query->limit;
            if ($hasNext) {
                $keys = array_slice($keys, 0, $query->limit);
            }
            $ids = array_map(static fn (array $key): int => $key['id'], $keys);
            $rows = [];
            if ($ids !== []) {
                // The documents are read under the same scopes, provider and locale again.
                foreach ($this->visible($query)->whereIn('d.id', $ids)->get(['d.*']) as $row) {
                    $rows[(int) $row->id] = $row;
                }
            }

            $hits = [];
            foreach ($ids as $id) {
                $row = $rows[$id] ?? null;
                if (!$row instanceof stdClass) {
                    continue;
                }
                $hits[] = new SearchHit(
                    providerId: (string) $row->provider_id,
                    sourceRef: (string) $row->source_ref,
                    sourceRevision: (int) $row->source_revision,
                    title: (string) $row->title,
                    locator: (string) $row->locator,
                    snippet: (string) ($row->snippet ?? ''),
                    locale: $row->locale === null ? null : (string) $row->locale,
                    accessScope: (string) $row->access_scope,
                    payload: $this->decodePayload((string) $row->payload),
                );
            }
            $last = $keys === [] ? null : $keys[count($keys) - 1];
            $nextCursor = $hasNext && $last !== null ? (new SearchCursor($last['title_key'], $last['id']))->encode() : null;

            return new SearchPage($hits, $page, $query->limit, $hasNext, $nextCursor);
        } catch (SearchPersistenceFailed|InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw SearchPersistenceFailed::from($exception);
        }
    }

    /**
     * The sort keys of the matched documents of a page, in order, with one more
     * than the limit when there is more.
     *
     * @param list<string> $terms
     * @return list<array{id: int, title_key: string}>
     */
    private function matchedKeys(SearchQuery $query, array $terms, int $offset): array
    {
        $patterns = array_map(SearchText::likePattern(...), $terms);
        $mysql = $this->mysql();
        $boolean = $mysql ? SearchText::booleanQuery($terms, ...$this->tokenSizes()) : null;

        if ($boolean !== null) {
            $probed = $this->probe($query, $patterns, $offset);
            if (is_array($probed)) {
                return $probed;
            }
            if ($probed === self::WALK) {
                $boolean = null;
            }
        }

        $builder = $this->visible($query)->join(self::TEXTS . ' as t', 't.document_id', '=', 'd.id');
        if ($boolean !== null) {
            $builder->whereRaw('MATCH(t.search_text) AGAINST (? IN BOOLEAN MODE)', [$boolean]);
        }
        $this->checkTerms($builder, $patterns);
        $this->after($builder, $query->cursor, 'd');
        $builder->select(['d.id', 'd.title_key'])->orderBy('d.title_key')->orderBy('d.id')->limit($query->limit + 1);
        if ($offset > 0) {
            $builder->offset($offset);
        }

        return $this->keys($builder->get());
    }

    /**
     * The page from the first documents in order, checked one by one, when the
     * window holds the page or every document. Otherwise how the page is best
     * read: WALK on when at least MIN_WALK_SHARE of the window matched and the
     * page lies within MAX_WALK rows in order at that share (a common word on a
     * deep page), INDEX through the FULLTEXT candidates (few documents match).
     *
     * @param list<string> $patterns
     * @return list<array{id: int, title_key: string}>|self::WALK|self::INDEX
     */
    private function probe(SearchQuery $query, array $patterns, int $offset): array|string
    {
        $wanted = $offset + $query->limit + 1;
        $window = min(self::MAX_PROBE_WINDOW, max(self::PROBE_WINDOW, 4 * $wanted));
        $candidates = $this->visible($query)->select(['d.id', 'd.title_key']);
        $this->after($candidates, $query->cursor, 'd');
        $candidates->orderBy('d.title_key')->orderBy('d.id')->limit($window);

        $builder = $this->database->table(self::DOCUMENTS)->fromSub($candidates, 'w')
            ->join(self::TEXTS . ' as t', 't.document_id', '=', 'w.id');
        $this->checkTerms($builder, $patterns);
        $builder->select(['w.id', 'w.title_key'])->selectRaw('count(*) over () as larena_matched')
            ->orderBy('w.title_key')->orderBy('w.id')->limit($query->limit + 1);
        if ($offset > 0) {
            $builder->offset($offset);
        }
        $rows = $builder->get();
        $keys = $this->keys($rows);
        if (count($keys) > $query->limit) {
            return $keys;
        }
        $seen = $this->database->table(self::DOCUMENTS)->fromSub($candidates, 'w')->count();
        if ($seen < $window) {
            return $keys;
        }
        $first = $rows->first();
        if (is_object($first) && is_numeric($first->larena_matched ?? null)) {
            $matched = (int) $first->larena_matched;
        } else {
            // Past the window's last match no row carries the count.
            $counted = $this->database->table(self::DOCUMENTS)->fromSub($candidates, 'w')
                ->join(self::TEXTS . ' as t', 't.document_id', '=', 'w.id');
            $this->checkTerms($counted, $patterns);
            $matched = $counted->count();
        }

        return $matched >= self::MIN_WALK_SHARE * $window && $wanted * $window / $matched <= self::MAX_WALK ? self::WALK : self::INDEX;
    }

    /** The documents the query may see: its access scopes, provider and locale, exactly. */
    private function visible(SearchQuery $query): Builder
    {
        $builder = $this->database->table(self::DOCUMENTS . ' as d')
            ->whereIn('d.access_scope', array_values(array_unique($query->accessScopes)));
        if ($query->providerId !== null) {
            $builder->where('d.provider_id', $query->providerId);
        }
        if ($query->locale !== null) {
            $builder->where('d.locale', $query->locale);
        }

        return $builder;
    }

    /** @param list<string> $patterns */
    private function checkTerms(Builder $builder, array $patterns): void
    {
        $like = 't.search_text' . ($this->mysql() ? ' COLLATE utf8mb4_bin' : '') . " LIKE ? ESCAPE '" . SearchText::ESCAPE . "'";
        foreach ($patterns as $pattern) {
            $builder->whereRaw($like, [$pattern]);
        }
    }

    private function after(Builder $builder, ?SearchCursor $cursor, string $alias): void
    {
        if ($cursor === null) {
            return;
        }
        // (title_key, id) > (?, ?), with a leading range the index can seek to.
        $builder->where($alias . '.title_key', '>=', $cursor->titleKey)
            ->where(static function (Builder $where) use ($alias, $cursor): void {
                $where->where($alias . '.title_key', '>', $cursor->titleKey)
                    ->orWhere($alias . '.id', '>', $cursor->documentId);
            });
    }

    /**
     * @param iterable<mixed> $rows
     * @return list<array{id: int, title_key: string}>
     */
    private function keys(iterable $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $keys[] = ['id' => (int) $row->id, 'title_key' => (string) $row->title_key];
            }
        }

        return $keys;
    }

    private function mysql(): bool
    {
        return $this->database instanceof Connection && in_array($this->database->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /** @return array{0: int, 1: int} The server's minimum and maximum FULLTEXT token size, asked once. */
    private function tokenSizes(): array
    {
        if (!isset($this->memo->tokenSizes)) {
            $sizes = [SearchText::DEFAULT_MIN_TOKEN, SearchText::DEFAULT_MAX_TOKEN];
            try {
                $row = $this->database->selectOne('select @@innodb_ft_min_token_size as min_size, @@innodb_ft_max_token_size as max_size');
                if (is_object($row) && is_numeric($row->min_size ?? null) && is_numeric($row->max_size ?? null)) {
                    $sizes = [(int) $row->min_size, (int) $row->max_size];
                }
            } catch (Throwable) {
            }
            $this->memo->tokenSizes = $sizes;
        }

        /** @var array{0: int, 1: int} */
        return $this->memo->tokenSizes;
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
                        ->first(['id', 'source_revision', 'generation_ref']);
                    if (!$current instanceof stdClass || (string) ($current->generation_ref ?? '') === $generationRef) {
                        continue;
                    }
                    $documentRevision = (int) $current->source_revision;
                    $stateRevision = (int) $state->source_revision;
                    if ((string) $state->state === 'indexed' && $stateRevision > $documentRevision) {
                        continue;
                    }

                    $tombstoneRevision = max($stateRevision, $documentRevision);
                    $deleted = $this->database->table('larena_search_documents')
                        ->where('provider_id', $providerId)
                        ->where('source_ref', $sourceRef)
                        ->where('source_revision', $documentRevision)
                        ->where(static function ($query) use ($generationRef): void {
                            $query->whereNull('generation_ref')->orWhere('generation_ref', '!=', $generationRef);
                        })
                        ->delete();
                    if ($deleted > 0) {
                        $this->database->table(self::TEXTS)->where('document_id', (int) $current->id)->delete();
                    }
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
                'title_key' => SearchText::titleKey($projection->title),
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
        $id = $this->database->table('larena_search_documents')->where($identity)->value('id');
        if ($id !== null) {
            $this->database->table('larena_search_documents')->where($identity)->update($values);
            $id = (int) $id;
        } else {
            $this->database->table('larena_search_documents')->insert($identity + $values + ['created_at' => $timestamp]);
            $id = (int) $this->database->table('larena_search_documents')->where($identity)->value('id');
        }
        $this->writeText($id, SearchText::document($projection->title, $projection->searchableText));
    }

    /**
     * The document's search text, written in its transaction; an unchanged text is
     * not written again (every FULLTEXT write leaves work for the index).
     */
    private function writeText(int $documentId, string $searchText): void
    {
        $current = $this->database->table(self::TEXTS)->where('document_id', $documentId)->value('search_text');
        if ($current === null) {
            $this->database->table(self::TEXTS)->insert(['document_id' => $documentId, 'search_text' => $searchText]);
        } elseif ((string) $current !== $searchText) {
            $this->database->table(self::TEXTS)->where('document_id', $documentId)->update(['search_text' => $searchText]);
        }
    }

    private function deleteTexts(string $providerId, string $sourceRef): void
    {
        $ids = $this->database->table('larena_search_documents')
            ->where('provider_id', $providerId)
            ->where('source_ref', $sourceRef)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        if ($ids !== []) {
            $this->database->table(self::TEXTS)->whereIn('document_id', $ids)->delete();
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
