# Testing

Run package checks from the package repository:

```bash
composer validate --strict
composer run quality:gate
```

`quality:gate` runs package metadata validation, lint, PHPStan, all unit/runtime scripts, evidence validation and launch-scope validation.

## Test Scripts

- `SearchContractTest.php`
- `SearchFailsClosedTest.php`
- `InMemorySearchRuntimeTest.php`
- `InMemorySearchRuntimeFailsClosedTest.php`
- `DatabaseSearchIndexTest.php`
- `SearchSourceRegistryTest.php`
- `SearchReindexServiceTest.php`
- `SearchLaravelPackageContractTest.php`

The persistent tests cover:

- file-backed SQLite persistence across a fresh connection;
- revision CAS, tombstone wins and missing-row self-heal;
- literal query filters and bounds;
- rollback/reapply of all Search migrations;
- additive provider-fence migration backfill for an active failed/resumable run;
- deterministic publish-first and schedule-first ordering with generation refresh/join and no tombstone lockout;
- interrupted/failing/resumed rebuild with realtime generation join and persistent fence ownership;
- factory registration/discovery without source construction, same-scope reuse,
  fresh source/connection graph after scope clearing and fail-closed provider
  mismatch;
- scheduling without source resolution and one fresh factory resolution per
  processed batch;
- a factory-thrown forged `SearchReindexRejected` sanitized in the returned
  exception, persisted run error, Audit payload and CLI output;
- one active run per provider and stale-generation cleanup;
- separate Access operations and denial non-mutation;
- Audit rollback, event taxonomy and payload redaction;
- Laravel provider/CLI auto-discovery contract.

Current lifetime-correction evidence is stored at
`docs/project-management/evidence/content-model-administration-api-v1-search-lifetime/`.

The package suite is SQLite-focused and proves both legal linearized outcomes. True two-connection row-lock races, including a barrier between first schedule and first realtime write, are proved by the root MySQL acceptance harness and are not inferred from package-only green checks.
