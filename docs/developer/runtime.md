# Runtime Boundaries

## Database Ownership

Search owns four tables:

- `larena_search_documents` — current searchable projection;
- `larena_search_source_states` — monotonic indexed/tombstone fence;
- `larena_search_reindex_runs` — generation, cursor and resumable run state.
- `larena_search_provider_states` — permanent provider-level serialization row and active run/generation references.

All writes use the application's current default connection. Source packages that need atomic publication must resolve Search against the same connection and call it inside their transaction.

## Provider Generation Fence

Every `upsert()` and `remove()` transaction performs `insertOrIgnore` for the permanent provider row and then locks it before reading `active_generation_ref` or touching source/document state. On MySQL the unique provider key plus `SELECT ... FOR UPDATE` serializes first use; on SQLite the first write serializes writers and the same protocol remains fail-closed.

`schedule()` locks the provider row, rejects an active claim, inserts the run, stores both active references and routes the started Audit event in one transaction. Batch execution locks the existing run and then the provider row before any Search source-state/document lock. Final sweep, fence clear, run completion and completed Audit stay inside that outer transaction. Failed/resumable runs do not clear the claim.

The additive `2026_07_13_000004` migration leaves the first three tables unchanged and backfills provider claims from any run whose `active_provider_id` is still set. Deploy the migration before code using the fence. Rolling back only this migration is a code-downgrade operation; do not run the new runtime while the provider table is absent.

## Security Boundary

The persistent runtime enforces:

- scalar-only projection payload with forbidden sensitive field names;
- literal bounded queries and explicit access scopes;
- monotonic revision/tombstone compare-and-set;
- sanitized Search-domain persistence errors;
- separate Access checks for schedule, run and resume;
- Security Audit payloads containing only stable identifiers/counters;
- transactional checkpoint and Audit mutation.
- provider-fenced schedule/realtime/final-sweep linearization.

Search consumes safe projections but does not own source schemas, physical files, canonical access roles, Audit storage or user-facing surfaces.

## CLI

```bash
php artisan search:reindex docara.published_pages --actor=user:admin_identity:1
php artisan search:reindex docara.published_pages --actor=user:admin_identity:1 --run=search-example
```

`--actor` is mandatory. `--schedule-only` creates an audited resumable run. `--max-batches=N` is useful for controlled checkpoints; zero runs until completion.

The command is an operator/developer baseline, not a production scheduler claim.

## Not Implemented

- queued/background worker integration;
- public/admin query endpoints;
- UI or admin diagnostics;
- REST/MCP tools;
- external, semantic or vector engines;
- production readiness.
