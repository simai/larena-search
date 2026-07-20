# Larena Search Developer Guide

`larena/search` stores searchable safe projections without taking ownership of source records. Source packages own canonical data, immutable revision semantics and the projection they expose.

## Current Scope

Implemented runtime surfaces:

- `SearchProjection`, `SearchQuery`, `SearchHit` and monotonic write results;
- `ReindexSource`, `ReindexSourceFactory`, `ReindexBatch` and the lazy
  `SearchSourceRegistry`;
- `DatabaseSearchIndex` on the application's current default database connection;
- persistent document, tombstone, rebuild-run and durable provider-fence migrations;
- resumable `SearchReindexService` and `search:reindex` CLI;
- Access descriptors and sanitized Security Audit events;
- compatibility in-memory contracts from the earlier baseline.

Out of scope:

- HTTP/API query endpoints;
- admin diagnostics UI;
- queue-backed or scheduled execution beyond the resumable CLI;
- external search services;
- semantic/vector search providers;
- production result rendering and production-readiness claims.

## Source Of Truth

Canonical package requirements live in `simai/larena-specs`. This documentation explains the current package code and evidence state; it is not a canonical graph update.

Current lifetime-correction evidence:
`docs/project-management/evidence/content-model-administration-api-v1-search-lifetime/`.

## Reading Order

1. [Concepts](concepts.md)
2. [API Reference](api-reference.md)
3. [Runtime Boundaries](runtime.md)
4. [Examples](examples.md)
5. [Testing](testing.md)
6. [Troubleshooting](troubleshooting.md)
