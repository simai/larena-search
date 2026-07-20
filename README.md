# Larena Search

Search and indexing layer for safe, access-scoped projections owned by other Larena packages.

The current database/native baseline provides:

- persistent documents and monotonic source-state tombstones;
- source-revision compare-and-set rules that reject conflicting equal revisions;
- bounded literal query with provider, locale and access-scope filters;
- package-owned resumable keyset sources and a singleton factory registry that
  resolves request/connection-bound sources only while processing a batch;
- one active rebuild per provider, a durable provider-level generation fence, checkpoints and generation-safe cleanup;
- separate `search.reindex.schedule`, `search.reindex.run` and `search.reindex.resume` permissions;
- sanitized Security Audit events for start, resume, checkpoint, completion and failure;
- Laravel auto-discovery, migrations and the `search:reindex` command.

`InMemorySearchRuntime` remains available as a compatibility/developer contract baseline. It is not the persistent runtime.

This package exposes no routes, UI, REST/MCP endpoints, external engines or vector search. It does not claim production readiness or readiness of all Larena packages.

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
