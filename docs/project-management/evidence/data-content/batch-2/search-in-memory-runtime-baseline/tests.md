# Tests

Commands run with ServBay PHP/Composer path:

```bash
PATH=/Applications/ServBay/package/php/8.4/8.4.20/bin:/Applications/ServBay/bin:$PATH composer run test
PATH=/Applications/ServBay/package/php/8.4/8.4.20/bin:/Applications/ServBay/bin:$PATH composer run scope:check
PATH=/Applications/ServBay/package/php/8.4/8.4.20/bin:/Applications/ServBay/bin:$PATH composer run analyse
```

Observed results:

- unit runtime baseline tests passed;
- scope check passed for the currently changed files;
- PHPStan reported no errors.

Full final quality-gate results are recorded in the goal final report and repository commit.
