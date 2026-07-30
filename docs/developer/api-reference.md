# API Reference

## Persistent index

`Larena\Search\Persistence\DatabaseSearchIndex` is constructed with an `Illuminate\Database\ConnectionInterface`.

- `connection(): ConnectionInterface`
- `upsert(SearchProjection $projection, ?string $generationRef = null): SearchWriteResult`
- `remove(string $providerId, string $sourceRef, int $sourceRevision, ?string $generationRef = null): SearchWriteResult`
- `query(SearchQuery $query): array<SearchHit>`
- `removeMissingFromGeneration(string $providerId, string $generationRef): int`

Laravel binds the index transiently so a connection purge/reconnect cannot leave it pinned to an obsolete connection.

The optional non-null `generationRef` is reserved for the reindex service and must match the provider's locked active generation. Ordinary realtime callers omit it; Search resolves the generation only after acquiring the durable provider fence. `removeMissingFromGeneration()` is likewise a trusted reindex-internal operation and fails closed unless that generation is still active.

## Projection and query DTOs

`SearchProjection` carries `providerId`, `sourceRef`, monotonic positive `sourceRevision`, safe title/locator/snippet/locale/access scope/searchable text and a scalar-only payload. Sensitive-looking payload field names fail closed.

`SearchQuery` requires a non-empty term, one to twenty explicit access scopes and a limit from 1 to 100. Provider and locale filters are optional and exact. Matching is a case-insensitive literal substring; SQL wildcard characters are escaped.

`SearchHit` returns only the persisted safe projection. Canonical source data is never loaded by Search query.

## Reindex source

```php
interface ReindexSource
{
    public function providerId(): string;
    public function readBatch(?string $afterCursor, int $limit): ReindexBatch;
}

interface ReindexSourceFactory
{
    public function providerId(): string;
    public function create(): ReindexSource;
}
```

`ReindexBatch` contains a list of `SearchProjection`, the opaque next keyset cursor and `hasMore`. A non-advancing or missing cursor fails closed when more data is declared.

The singleton `Larena\Search\Runtime\SearchSourceRegistry` exposes:

- `registerFactory(ReindexSourceFactory $factory): bool`;
- `has(string $providerId): bool`;
- `providerIds(): list<string>`;
- `get(string $providerId): ?ReindexSource`;
- the backward-compatible `register(ReindexSource $source): bool` and
  `all(): list<ReindexSource>`.

`registerFactory()`, `has()` and `providerIds()` never construct a source.
`get()` invokes the factory every time, so the factory may resolve a scoped
source from the current Laravel container scope. The registry verifies that
the resolved source has the factory's registered provider ID. A mismatch fails
closed as `search_reindex_source_provider_mismatch`.

Use `register()` only for a genuinely static, lifecycle-neutral source. It
wraps the source in `StaticReindexSourceFactory`; it does not restore eager
resolution for factory registrations.

## Reindex service

`Larena\Search\Reindex\SearchReindexService` exposes:

- `schedule(providerId, actor, ?runRef, ?correlationId): ReindexRun`;
- `run(runRef, actor, batchSize = 100, maxBatches = 0): ReindexRun`;
- `resume(runRef, actor, batchSize = 100, maxBatches = 0, ?expectedProviderId = null): ReindexRun`;
- `retry(runRef, actor, batchSize = 100, maxBatches = 0, ?expectedProviderId = null): ReindexRun`;
- `continueRunning(runRef, actor, batchSize = 100, maxBatches = 0, ?expectedProviderId = null): ReindexRun`
  for the package-owned Queue continuation path only;
- `find(runRef): ?ReindexRun` for trusted internal diagnostics/tests.

The CLI never calls `find()` before the selected operation permission is
checked. It requires an explicit run/resume/retry operation for an existing
run. The optional expected provider and the exact operation state are validated
inside the locked processing transaction.
Scheduling checks only registered provider metadata and does not construct or
read the source. Batch processing resolves the source immediately before each
`readBatch()` call.

## Compatibility contracts

The earlier `SourceProvider`, `IndexDocument`, `QueryContext`, `ResultExposurePolicy`, `ReindexJob`, `SearchRuntime` and `InMemorySearchRuntime` remain available for compatibility and isolated developer tests. They are not used as persistent storage.
