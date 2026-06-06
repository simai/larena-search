# Runtime Boundaries

## What Exists Now

The current package contains contract skeletons and tests. The contracts can be loaded through Composer autoload and validated through package-local checks.

## What Does Not Exist Yet

The package does not yet include:

- engine adapter implementation;
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

## Next Runtime Decisions

Future launch records must choose:

- baseline database/native engine strategy;
- index storage model and cleanup policy;
- access filtering integration;
- query endpoint shape;
- reindex queue lifecycle;
- admin diagnostics fields;
- capability gates for external and semantic engines.
