# Runtime Boundaries

## What Exists Now

The current package contains contract skeletons, tests and a guarded in-memory runtime baseline. The runtime can:

- register valid package-owned source providers;
- create safe index documents from declared projection fields;
- reject private-looking projected payloads;
- ingest indexable documents in process memory;
- query in-memory documents through explicit query context;
- expose results only through result exposure policy;
- plan descriptor-only reindex jobs.

## What Does Not Exist Yet

The package does not yet include:

- production engine adapter implementation;
- database tables;
- migrations;
- queued reindex worker;
- scheduler integration;
- admin screens;
- public or admin query endpoints;
- REST/MCP tools;
- external provider calls;
- semantic/vector indexing.

## Security Boundary

Search is high-risk because stale indexes and snippets can leak protected content. Current contracts enforce safe defaults:

- providers require owner and access scope;
- documents reject private payload;
- query context requires actor and access scope;
- exposure policy hides or denies results without matched access;
- gated engines degrade safely.
- in-memory runtime returns no result when query context or access exposure is invalid.

## Cross-Package Boundaries

Search consumes projections from other packages. It must not own:

- storage schemas or records;
- physical files;
- links or signed tokens;
- workflow items;
- audit/access policy;
- admin UI surfaces.

Search must cooperate with:

- `larena/storage` for searchable record projections;
- `larena/filesystem` for safe metadata projections;
- `larena/access` for query-scope filtering;
- `larena/audit` for future sensitive query/reindex events;
- `larena/queue` for future heavy reindex jobs.

## Current Runtime Boundary

`InMemorySearchRuntime` is intentionally non-persistent and non-routing. It is acceptable for package-local tests and developer smoke, but it must not be treated as the final engine for production search.

## Next Runtime Decisions

Future launch records must choose:

- baseline database/native engine strategy;
- index storage model and cleanup policy;
- access filtering integration;
- query endpoint shape;
- reindex queue lifecycle;
- admin diagnostics fields;
- capability gates for external and semantic engines.
