# Testing

Run package checks from the package repository:

```bash
composer validate --strict
composer run quality:gate
```

`quality:gate` currently runs:

- package metadata validation;
- lint checks;
- static analysis;
- unit tests;
- evidence validation;
- scope check.

## Current Unit Tests

- `tests/Unit/SearchContractTest.php`
- `tests/Unit/SearchFailsClosedTest.php`

The tests verify:

- valid providers and documents can be declared;
- private payloads fail closed;
- external engines require capability gates;
- query context requires access scope;
- result exposure denies or redacts unsafe results;
- reindex job descriptors do not start without valid state.

## Evidence

Batch evidence is recorded at:

`docs/project-management/evidence/data-content/batch-1/search-current/`

The evidence package confirms contract skeleton scope only.

## What Future Tests Need

Future runtime batches must add tests for:

- database/native engine implementation;
- index persistence and stale cleanup;
- access filtering integration;
- query endpoint authorization;
- reindex queue lifecycle;
- audit events for sensitive query/reindex operations;
- external/semantic provider capability gates.
