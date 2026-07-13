# Migration And Rollback

Apply order:

1. `larena_search_documents`
2. `larena_search_source_states`
3. `larena_search_reindex_runs`
4. `larena_search_provider_states`

Rollback runs in reverse order and removes all four tables. The package test applies, rolls back, verifies absence, reapplies and verifies the schema again on a disposable file-backed SQLite database.

Migration 4 is additive for installations that already applied migrations 1–3. Its `up()` backfills `active_run_ref` and `active_generation_ref` from every reindex row whose `active_provider_id` remains set, including failed/resumable runs. It does not rewrite documents, tombstones, cursors or completed runs. Runtime code using the fence is compatible only after migration 4 has been applied.

Operational rollback warning: rolling back removes Search-derived index/checkpoint/fence data, not canonical source data. After reapply, run an authorized full reindex. Do not run rollback on an existing application database as part of package tests.
