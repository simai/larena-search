# Smoke

Smoke behavior covered by package tests:

- safe provider registration;
- safe projected document creation;
- in-memory ingest;
- scoped query with matched access;
- snippet redaction through policy;
- invalid provider rejection;
- private-looking projection rejection;
- invalid query context returns no results;
- capability-gated engine cannot plan a runnable reindex job.

The runtime does not mutate external state and does not create persistent index records.
