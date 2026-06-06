# API Reference

## `SourceProvider`

Namespace: `Larena\Search\Contracts`

Creates a package-owned source descriptor.

Important methods:

- `SourceProvider::declare(...)`
- `isValid()`

Use it when a package wants to expose a safe searchable projection. Do not pass private fields, raw file contents, tokens, credentials or hidden metadata.

## `IndexDocument`

Represents one projected document.

Important method:

- `isIndexable()`

The document must have a valid source provider, projection fields and tokens. It fails closed when `containsPrivatePayload` is true.

## `EngineProfile`

Represents a search backend profile.

Important methods:

- `databaseBaseline()`
- `canRun()`
- `isDegraded()`

External and semantic engines require capability gates. They must not run by default.

## `QueryContext`

Represents a search request context.

Important method:

- `isValid()`

The context must include query text, surface, actor reference and access scope.

## `ResultExposurePolicy`

Evaluates whether a projected document can be returned.

Important methods:

- `denyByDefault()`
- `decide(IndexDocument $document, QueryContext $context)`

The policy returns a `ResultExposureDecision`.

## `ScopedSearchResult`

Represents a result after exposure policy evaluation.

Important methods:

- `canReturnToCaller()`
- `exposesSnippet()`

Only `allowed` and `redacted` decisions can be returned to callers.

## `ReindexJob`

Represents a reindex job descriptor.

Important methods:

- `canStart()`
- `hasSafeDiagnostics()`

This is not a worker. It does not execute indexing.

## `SearchRuntime`

Defines the future runtime boundary:

- `registerSource(...)`
- `createDocument(...)`
- `exposeResult(...)`
- `planReindex(...)`

The current implementation is `Larena\Search\Runtime\InMemorySearchRuntime`.

## `InMemorySearchRuntime`

Namespace: `Larena\Search\Runtime`

Provides the current guarded runtime baseline:

- `registerSource(SourceProvider $sourceProvider)`;
- `createDocument(SourceProvider $sourceProvider, array $projection, array $tokens)`;
- `ingest(IndexDocument $document)`;
- `query(QueryContext $queryContext, ResultExposurePolicy $policy)`;
- `exposeResult(IndexDocument $document, QueryContext $queryContext, ResultExposurePolicy $policy)`;
- `planReindex(SourceProvider $sourceProvider, EngineProfile $engineProfile)`.

It stores valid providers and indexable documents in memory only. It is useful for local package tests and cross-package smoke, but it is not index persistence, an HTTP query endpoint, a queue worker or a production search backend.
