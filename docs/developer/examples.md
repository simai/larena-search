# Examples

## Safe Provider And Redacted Result

```php
use Larena\Search\Contracts\IndexDocument;
use Larena\Search\Contracts\QueryContext;
use Larena\Search\Contracts\ResultExposurePolicy;
use Larena\Search\Contracts\ScopedSearchResult;
use Larena\Search\Contracts\SourceProvider;

$provider = SourceProvider::declare(
    providerId: 'storage.records',
    ownerPackage: 'larena/storage',
    projectionFields: ['title', 'summary'],
    accessScope: 'storage.records.read',
);

$document = new IndexDocument(
    documentId: 'storage:1',
    sourceProvider: $provider,
    projection: ['title' => 'Example', 'summary' => 'Safe summary'],
    tokens: ['example', 'safe'],
);

$context = new QueryContext(
    query: 'example',
    surface: 'admin',
    actorReference: 'user:1',
    accessScope: 'storage.records.read',
);

$policy = new ResultExposurePolicy(
    accessScopeMatched: true,
    snippetAllowed: false,
);

$decision = $policy->decide($document, $context);
$result = new ScopedSearchResult($document, $decision, 'Example', '');
```

The result may return the title, but not the snippet.

## Fail-Closed Private Document

```php
use Larena\Search\Contracts\IndexDocument;
use Larena\Search\Contracts\SourceProvider;

$provider = new SourceProvider('', '', [], '', true);

$document = new IndexDocument(
    documentId: 'secret:1',
    sourceProvider: $provider,
    projection: ['secret' => 'raw'],
    tokens: ['raw'],
    containsPrivatePayload: true,
);

assert($document->isIndexable() === false);
```

This document cannot be indexed.

## Capability-Gated Engine

```php
use Larena\Search\Contracts\EngineProfile;
use Larena\Search\Enums\EngineProfileType;

$engine = new EngineProfile(
    profileId: 'external',
    type: EngineProfileType::External,
    capabilityAllowed: false,
    engineAvailable: true,
);

assert($engine->canRun() === false);
assert($engine->isDegraded() === true);
```

External and semantic engines must not run unless the capability gate allows them.

## In-Memory Runtime Baseline

```php
use Larena\Search\Contracts\QueryContext;
use Larena\Search\Contracts\ResultExposurePolicy;
use Larena\Search\Contracts\SourceProvider;
use Larena\Search\Runtime\InMemorySearchRuntime;

$runtime = new InMemorySearchRuntime();

$provider = SourceProvider::declare(
    providerId: 'storage.records',
    ownerPackage: 'larena/storage',
    projectionFields: ['title', 'summary'],
    accessScope: 'storage.records.read',
);

$runtime->registerSource($provider);

$document = $runtime->createDocument(
    sourceProvider: $provider,
    projection: ['title' => 'Safe Record', 'summary' => 'Safe metadata'],
    tokens: ['safe', 'record'],
);

$runtime->ingest($document);

$results = $runtime->query(
    queryContext: new QueryContext('record', 'admin', 'user:1', 'storage.records.read'),
    policy: new ResultExposurePolicy(accessScopeMatched: true, snippetAllowed: false),
);
```

The result can expose the safe title, but the snippet stays redacted when the policy denies snippets.
