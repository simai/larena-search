# Larena Search Developer Guide

`larena/search` defines the search and indexing contract layer for Larena. It lets packages describe searchable projections, query context, exposure policy and reindex descriptors without making Search the owner of source data.

Current implementation includes interface-first contract skeletons and a guarded in-memory runtime baseline. The package exposes value objects, enums, interfaces and `InMemorySearchRuntime` so source registration, safe document projection, in-memory ingest/query, result exposure and reindex planning can be tested without persistence, routes, admin UI or queue runtime.

## Current Scope

Implemented contract surfaces:

- source provider declaration;
- safe index document projection;
- engine profile capability/degraded state;
- access-aware query context;
- fail-closed result exposure policy;
- scoped search result shape;
- reindex job descriptor state;
- `SearchRuntime` interface.
- `InMemorySearchRuntime` baseline for local non-persistent runtime smoke.

Out of scope for the current batch:

- production database/native search engine runtime;
- index storage tables or migrations;
- HTTP/API query endpoints;
- admin diagnostics UI;
- queue-backed reindex execution;
- external search services;
- semantic/vector search providers;
- production result rendering.

## Source Of Truth

Canonical package requirements live in `simai/larena-specs`. This documentation explains the current package code and evidence state; it is not a canonical graph update.

Key evidence path:

`docs/project-management/evidence/data-content/batch-2/search-in-memory-runtime-baseline/`

## Reading Order

1. [Concepts](concepts.md)
2. [API Reference](api-reference.md)
3. [Runtime Boundaries](runtime.md)
4. [Examples](examples.md)
5. [Testing](testing.md)
6. [Troubleshooting](troubleshooting.md)
