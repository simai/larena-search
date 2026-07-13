# Tests

Package acceptance commands:

```bash
composer validate --strict
composer run quality:gate
```

Runtime scripts cover file-backed SQLite restart, migration rollback/reapply and active-run fence backfill, revision CAS, stale resurrection, missing-row self-heal, query bounds, deterministic publish-first/schedule-first ordering, interrupted/failing/resumed reindex, provider-fenced realtime writes and final sweep, one-active-provider enforcement, stale-run isolation, Access denial, Audit rollback/redaction and provider/CLI contracts.

Fresh result on 2026-07-13:

- `composer validate --strict`: passed;
- `composer run quality:gate`: passed;
- syntax lint: 44 PHP files;
- PHPStan: no errors;
- runtime tests: 7 executable test scripts passed;
- metadata sync, evidence contract and 60-file scope check: passed;
- JSON parsing and `git diff --check`: passed;
- SQLite database files and `-wal`, `-shm`, `-journal` sidecars remaining in the package: none.

Composer emitted deprecation notices from its own PHAR dependencies under the local PHP runtime; they did not originate in package code and did not fail validation.
