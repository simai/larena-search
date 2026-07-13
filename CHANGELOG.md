# Changelog

All notable package changes are recorded here. The package does not currently publish a production-ready semantic release.

## Unreleased

### Added

- Database/native persistent Search index, monotonic source-state tombstones and bounded literal query.
- Generic resumable keyset source contracts and registry.
- Durable one-active-provider rebuild runs, permanent provider-level generation fences, generation-safe realtime writes and final cleanup.
- `search:reindex` CLI with separate schedule/run/resume Access operations.
- Sanitized Security Audit lifecycle and transaction rollback on Audit failure.
- SQLite restart, rollback/reapply, stale-resurrection, Access and Audit regression tests.

### Security

- Equal-revision content conflicts fail closed, tombstones win equal revisions and database failures cross package boundaries only as `SearchPersistenceFailed`.
- CLI resume checks Access before validating run existence or provider.
- First schedule, realtime upsert/remove and final sweep serialize through one database-owned provider fence so a concurrent publication cannot be swept into an equal-revision tombstone.

### Nonclaims

- No frontend, route, REST/MCP, external/vector engine or production-readiness claim is included.
