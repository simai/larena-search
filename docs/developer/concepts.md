# Concepts

## Search Is A Projection Layer

Search indexes safe projections from other packages. It does not own canonical pages, storage records, files, workflow items or documentation revisions. The source package decides which immutable public fields can be projected.

## Source Revision Fence

The persistent identity is `(provider_id, source_ref)`. Every change carries a positive monotonic `source_revision`:

- lower revision: ignored as stale;
- equal indexed revision and equal hash: idempotent, with generation refresh/self-heal;
- equal indexed revision and different hash: rejected;
- equal tombstone: tombstone wins and cannot be resurrected;
- higher revision: replaces the current state.

## Tombstones

Deletion removes the document but preserves source revision/state in `larena_search_source_states`. This prevents stale rebuild data from resurrecting unpublished content.

## Rebuild Generation

Each rebuild owns a generation. `larena_search_provider_states` keeps one permanent row per observed provider and atomically maps it to the active run/generation. The row remains after completion with both active references cleared.

Schedule and realtime writes first create-or-find and lock this same provider row. If schedule linearizes first, the later realtime write observes and joins its generation. If publication linearizes first, the later source pass sees the committed source revision and its idempotent projection refreshes the document into the generation. A failed or paused run keeps the active references.

Final cleanup holds the same provider fence while it locks source state before document state, rechecks generation, tombstones only documents still outside the active generation, clears the active references and completes the run. A realtime write therefore either commits in the active generation before cleanup or waits and commits after cleanup; it cannot be inserted with `generation_ref = null` inside that window.

The Search-owned lock graph is acyclic: an existing reindex operation takes `run -> provider -> source state -> document`; realtime writes take `provider -> source state -> document`; schedule takes `provider` and inserts a new run that no other transaction can yet hold.

## Access Scope

Search does not infer authorization. A trusted caller supplies explicit
already-authorized access scopes to `SearchQuery`; an empty scope list is
invalid. The package-owned public HTTP presenter exposes only published safe
projections, while protected index operations use canonical Access operations.

## Resumable Reindex

Only one active run can exist per provider. A keyset cursor and counters
persist in `larena_search_reindex_runs`; the provider row is the serialization
source of truth for the active generation. Each projection batch, checkpoint
state and checkpoint Audit event share one database transaction. Running
checkpoints can resume; failed attempts retain their active provider fence and
must use retry.

## Source Lifetime

The source registry owns discovery, not source lifetime. It keeps lightweight
factories in its singleton state and resolves a source for each batch. This
prevents a request-scoped repository, database connection or participant graph
from leaking into a later request after Laravel clears scoped instances.

Provider discovery and scheduling use registered IDs only. They must not open a
source connection or execute a canonical source query.
