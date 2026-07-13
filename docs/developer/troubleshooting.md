# Troubleshooting

## Search Returns Nothing

Check exact access scopes, optional provider/locale filters and whether the source state is a tombstone. Matching is literal and case-insensitive. No HTTP/API query endpoint exists in this package.

## Reindex Source Is Unknown

Ensure the package registered its `ReindexSource` in the singleton `SearchSourceRegistry` during provider boot. Registration is idempotent by provider id.

## `search_revision_conflict`

The same `(provider, source, revision)` was supplied with different public content. Source revisions must be immutable; allocate a higher revision instead of overwriting.

## `search_persistence_failed`

The public error is intentionally sanitized. Check application database logs, migrations and connection state in the trusted runtime. Do not remap this to a source revision conflict.

## Run Stays Failed

Failed runs retain `active_provider_id` and the matching active references in `larena_search_provider_states`, so realtime writes remain safe. Correct the source/database issue and resume the same run with `--run` and the `search.reindex.resume` permission. A provider-fence mismatch is treated as sanitized persistence corruption; do not clear it manually or schedule a replacement run over it.

## Scope Check Fails

Files must match `.larena/launch-context.json`. Do not broaden scope to routes, UI, external engines or other packages.

## In-Memory Runtime

`InMemorySearchRuntime` is retained for compatibility tests. Use `DatabaseSearchIndex` for persistent runtime; neither runtime is a production-readiness claim.
