# Examples

## Persistent projection and literal query

```php
use Larena\Search\Contracts\SearchProjection;
use Larena\Search\Contracts\SearchQuery;

$index->upsert(new SearchProjection(
    providerId: 'docara.published_pages',
    sourceRef: 'page:welcome',
    sourceRevision: 7,
    title: 'Welcome',
    locator: '/docs/welcome',
    snippet: 'Public summary',
    locale: 'en',
    accessScope: 'public',
    searchableText: 'Only immutable published content',
    payload: ['slug' => 'welcome'],
));

$hits = $index->query(new SearchQuery(
    term: 'published',
    providerId: 'docara.published_pages',
    locale: 'en',
    accessScopes: ['public'],
    limit: 20,
));
```

Use `remove(providerId, sourceRef, sourceRevision)` when the source becomes non-public. Never reuse a revision for different content.

## Resumable source

```php
use Larena\Search\Contracts\ReindexBatch;
use Larena\Search\Contracts\ReindexSource;

final class PublishedPageSource implements ReindexSource
{
    public function providerId(): string
    {
        return 'docara.published_pages';
    }

    public function readBatch(?string $afterCursor, int $limit): ReindexBatch
    {
        // Read immutable published revisions with a keyset (`source_ref > cursor`).
        return new ReindexBatch($projections, $nextCursor, $hasMore);
    }
}

$registry->register(new PublishedPageSource());
```

The source owns its canonical query and safe projection. Search owns checkpointing, index CAS and cleanup.

## Compatibility in-memory runtime

The earlier `SourceProvider`, `IndexDocument`, `QueryContext`, `ResultExposurePolicy` and `InMemorySearchRuntime` remain usable for isolated compatibility tests. They do not persist data and must not be substituted for `DatabaseSearchIndex`.
