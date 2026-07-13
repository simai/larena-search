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
```

`ReindexBatch` contains a list of `SearchProjection`, the opaque next keyset cursor and `hasMore`. A non-advancing or missing cursor fails closed when more data is declared.

Register sources through `Larena\Search\Runtime\SearchSourceRegistry::register()`.

## Reindex service

`Larena\Search\Reindex\SearchReindexService` exposes:

- `schedule(providerId, actor, ?runRef, ?correlationId): ReindexRun`;
- `run(runRef, actor, batchSize = 100, maxBatches = 0): ReindexRun`;
- `resume(runRef, actor, batchSize = 100, maxBatches = 0, ?expectedProviderId = null): ReindexRun`;
- `find(runRef): ?ReindexRun` for trusted internal diagnostics/tests.

The CLI never calls `find()` before the resume permission is checked. The optional expected provider is validated inside the locked processing transaction.

## Compatibility contracts

The earlier `SourceProvider`, `IndexDocument`, `QueryContext`, `ResultExposurePolicy`, `ReindexJob`, `SearchRuntime` and `InMemorySearchRuntime` remain available for compatibility and isolated developer tests. They are not used as persistent storage.
