# Larena Search Developer Guide

`larena/search` defines the search and indexing contract layer for Larena. It lets packages describe searchable projections, query context, exposure policy and reindex descriptors without making Search the owner of source data.

Current implementation is an interface-first contract skeleton. The package exposes value objects, enums and interfaces that make search behavior explicit and fail closed. It does not yet provide a real engine, persistence layer, query endpoint, admin UI or queue runtime.

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

Out of scope for the current batch:

- real database/native search engine runtime;
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

`docs/project-management/evidence/data-content/batch-1/search-current/`

## Reading Order

1. [Concepts](concepts.md)
2. [API Reference](api-reference.md)
3. [Runtime Boundaries](runtime.md)
4. [Examples](examples.md)
5. [Testing](testing.md)
6. [Troubleshooting](troubleshooting.md)
