# Changelog

All notable package changes are recorded here. The package does not currently publish a production-ready semantic release.

## Unreleased

- Document the `storage.site_pages` source: how a published Storage entry maps to a Search projection and why its `projection_version` satisfies the revision fence.

### Fixed

- Reindex execution now enforces the same locked transition table in the
  service, Queue worker, CLI and HTTP: run only scheduled, resume only running,
  retry only failed and internal continuation only running. Stale queued
  operations fail closed.
- Reindex Audit now records the exact schedule/run/resume/retry/continuation
  operation on scheduled, started, checkpoint, completion, rejection and
  failure events; retry is no longer reported as resume.
- Existing-run CLI execution requires an explicit
  `--operation=run|resume|retry`.
- The singleton source registry now retains lightweight factories instead of
  request/connection-bound sources. Scheduling and provider enumeration are
  resolution-free, while every processed batch resolves its source from the
  current container scope and rejects factory/source provider mismatches.
- Provider-state migration backfill timestamps now derive from retained reindex
  runs, with a migration-identity fallback, so rollback/reapply is
  deterministic on SQLite and MySQL.

### Added

- Localized `/search` flow with deterministic pagination, empty/no-result/error states, canonical published links and XSS-safe highlighting.
- Protected `/admin/search` provider/run/checkpoint surface with Reader diagnostics, Administrator expected-state mutations and CSRF protection.
- Queue checkpoint delivery, retry/restart continuation and Scheduler adapter for periodic all-provider delivery without synchronous HTTP indexing.
- SIMAI Framework runtime activation through the canonical UI runtime lock; no second frontend system.
- `ReindexSourceFactory`, lazy `registerFactory()`, `has()` and deterministic
  `providerIds()` contracts, plus a static adapter for legacy
  `register(ReindexSource)` callers.
- Database/native persistent Search index, monotonic source-state tombstones and bounded literal query.
- Generic resumable keyset source contracts and registry.
- Durable one-active-provider rebuild runs, permanent provider-level generation fences, generation-safe realtime writes and final cleanup.
- `search:reindex` CLI with separate schedule/run/resume/retry Access operations.
- Sanitized Security Audit lifecycle and transaction rollback on Audit failure.
- SQLite restart, rollback/reapply, stale-resurrection, Access and Audit regression tests.

### Security

- Public presentation projects only title, canonical locator, escaped snippet and provider label; source references, revisions, payload and private fields never reach the view.
- Unsafe absolute/protocol-relative locators, unbounded query/page input and stale expected-state mutations fail closed.
- Factory/source identity mismatch is a stable Search rejection, while
  every factory-thrown exception, including a forged `SearchReindexRejected`,
  is sanitized as `search_reindex_source_failed` before persistence, Audit or
  CLI output.
- Equal-revision content conflicts fail closed, tombstones win equal revisions and database failures cross package boundaries only as `SearchPersistenceFailed`.
- CLI run/resume/retry checks Access before validating run existence or provider.
- First schedule, realtime upsert/remove and final sweep serialize through one database-owned provider fence so a concurrent publication cannot be swept into an equal-revision tombstone.

### Nonclaims

- No frontend, route, REST/MCP, external/vector engine or production-readiness claim is included.
