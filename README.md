# Larena Search

Search and indexing layer for access-aware discovery across storage records, content, documentation, file metadata, file-manager views, REST/MCP contexts and future package-defined sources.

Current implementation status: interface-first contract skeletons are present, and the package now includes a guarded in-memory `SearchRuntime` baseline for local source registration, safe document creation, in-memory ingest/query, access-aware result exposure and descriptor-only reindex planning.

This package does not yet implement a real production search engine, index persistence, query endpoints, admin diagnostics UI, queue runtime, external/semantic providers, routes, migrations or production result rendering.

Canonical specifications are in `simai/larena-specs`.

Developer documentation:

- [Developer Guide](docs/developer/README.md)
- [Concepts](docs/developer/concepts.md)
- [API Reference](docs/developer/api-reference.md)
- [Runtime Boundaries](docs/developer/runtime.md)
- [Examples](docs/developer/examples.md)
- [Testing](docs/developer/testing.md)
- [Troubleshooting](docs/developer/troubleshooting.md)
