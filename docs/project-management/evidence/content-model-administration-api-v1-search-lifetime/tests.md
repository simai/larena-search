# Tests

Local package checks on ServBay PHP 8.4:

- Composer strict validation: passed.
- Full Composer test suite: passed.
- `SearchSourceRegistryTest`: passed.
- `SearchReindexServiceTest`: passed.
- `SearchLaravelPackageContractTest`: passed.
- Package validator: passed.
- PHP syntax lint: passed.
- PHPStan level 5: passed.
- Metadata sync and evidence contract: passed.
- Launch scope check: passed after the current launch context was recorded.

The focused canary proves no source construction during registration,
`has()` or provider-ID enumeration; same-scope reuse; a fresh source and
connection graph after scope clearing; fail-closed identity mismatch; and no
raw factory exception detail crossing the reindex boundary.
