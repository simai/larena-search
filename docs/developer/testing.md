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
- `DatabaseSearchSemanticsTest.php` (word-prefix semantics, order, cursor and offset paging, visibility, the SQLite plan and the migration backfill, against the PHP reference in `tests/Support/search-semantics-fixture.php`)
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
- interrupted/failing/retried rebuild with realtime generation join and persistent fence ownership;
- factory registration/discovery without source construction, same-scope reuse,
  fresh source/connection graph after scope clearing and fail-closed provider
  mismatch;
- scheduling without source resolution and one fresh factory resolution per
  processed batch;
- a factory-thrown forged `SearchReindexRejected` sanitized in the returned
  exception, persisted run error, Audit payload and CLI output;
- one active run per provider and stale-generation cleanup;
- separate Access operations and denial non-mutation;
- exact forbidden run/resume/retry/continuation transitions and stale queued
  continuation failure;
- operation-specific Audit ordering, rollback, event taxonomy and payload
  redaction;
- Laravel provider/CLI auto-discovery contract.

Current lifetime-correction evidence is stored at
`docs/project-management/evidence/content-model-administration-api-v1-search-lifetime/`.

`composer test:mysql` (opt-in, not part of the gate) runs `tests/Integration/SearchMySqlIndexTest.php` on a throwaway MySQL database it creates and drops (credentials from the root's ignored, owner-only `.env.auth-mfa-mysql-test`, local hosts only): the migration's INSTANT column, FULLTEXT side table and INPLACE index, the same corpus and queries against the reference and against SQLite, both ways of answering, and rollback/backfill. `composer bench -- --driver=sqlite|mysql --documents=100000` times the public query before and after.

The package suite is SQLite-focused and proves both legal linearized outcomes. True two-connection row-lock races, including a barrier between first schedule and first realtime write, are proved by the root MySQL acceptance harness and are not inferred from package-only green checks.
