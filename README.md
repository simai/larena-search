# Larena Search

Search and indexing layer for safe, access-scoped projections owned by other Larena packages.

The current database/native baseline provides:

- persistent documents and monotonic source-state tombstones;
- source-revision compare-and-set rules that reject conflicting equal revisions;
- localized server-rendered public HTTP search with bounded deterministic pagination, escaped highlighting and public-scope-only results;
- package-owned resumable keyset sources and a singleton factory registry that
  resolves request/connection-bound sources only while processing a batch;
- one active rebuild per provider, a durable provider-level generation fence, checkpoints and generation-safe cleanup;
- protected SIMAI Framework administration surface with Reader diagnostics and Administrator-only expected-state mutations;
- separate `search.reindex.read`, `search.reindex.schedule`, `search.reindex.run`, `search.reindex.resume` and `search.reindex.retry` permissions;
- one bounded Search batch per durable Queue job, restart-safe continuation and a Scheduler-owned delivery adapter;
- sanitized Security Audit events with exact schedule, run, resume, retry and
  internal-continuation identity for starts, checkpoints, completions,
  rejections and failures;
- Laravel auto-discovery, migrations and the `search:reindex` command.

`InMemorySearchRuntime` remains available as a compatibility/developer contract baseline. It is not the persistent runtime.

The database-native public route rejects non-empty terms shorter than two characters, caps input at 200 characters, returns at most 20 results per page, and caps pagination at page 500. These are the explicit bounded-query limits.

Search matches word prefixes, as Storage's record lists do: every whitespace-separated term of the query must start a word of a document's title or searchable text, in any order (`chair` finds "Chairs", not "Armchair"; `oak chair` finds "Chair, oak"; punctuation separates words). This replaced substring matching. Hits are ordered by the lowercased title and then by when the document was indexed. Queries are answered through indexes: on MySQL a FULLTEXT side table (`larena_search_document_texts`) and an order index, on SQLite the order index with the same results. The next-page link carries a cursor (`after`), so a following page costs what the first one does; see the [API Reference](docs/developer/api-reference.md).

The package owns `/search` and the optional local/testing `/admin/search` operations surface. Both are composed only where their package is installed: `/search` needs `larena/ui`, `/admin/search` and its menu entry need `larena/admin`; without them the index and `search:reindex` keep working. The source packages own the published projections — Storage (site pages, `storage.site_pages`), Content and Docara — and Search never reads their private tables or draft payload. External engines, vector/semantic search and crawler/analytics are not included. The package does not claim production readiness, frontend completeness or readiness of all Larena packages.

Canonical specifications are in `simai/larena-specs`.

Developer documentation:

- [Developer Guide](docs/developer/README.md)
- [Concepts](docs/developer/concepts.md)
- [API Reference](docs/developer/api-reference.md)
- [Runtime Boundaries](docs/developer/runtime.md)
- [Examples](docs/developer/examples.md)
- [Testing](docs/developer/testing.md)
- [Troubleshooting](docs/developer/troubleshooting.md)

Run `composer run quality:gate` with the ServBay PHP runtime used by the Larena workspace.
