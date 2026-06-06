# Smoke

## Scenario

```text
source provider
-> safe projection
-> in-memory ingest
-> access-scoped query
-> exposure policy
-> reindex job descriptor
```

## Non-Mutating Guarantee

The smoke is represented by package-local unit tests and does not mutate
database state, filesystem indexes, routes, queues, external engines or public
runtime state.
