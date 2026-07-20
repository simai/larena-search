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
connection graph after scope clearing; fail-closed identity mismatch; and a
factory-forged `SearchReindexRejected` reduced to
`search_reindex_source_failed` in the exception, persisted run, Audit payload
and CLI output.
