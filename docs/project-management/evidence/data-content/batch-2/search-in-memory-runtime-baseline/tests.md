# Tests

## Commands

```bash
composer validate --strict
composer run validate:larena
composer run lint
composer run analyse
composer test
composer run evidence:check
composer run scope:check
composer run quality:gate
```

## Test Coverage

- `SearchContractTest.php`
- `SearchFailsClosedTest.php`
- `InMemorySearchRuntimeTest.php`
- `InMemorySearchRuntimeFailsClosedTest.php`

## Expected Result

All commands pass. Composer on local ServBay/PHP may emit dependency
deprecation notices while still exiting successfully.
