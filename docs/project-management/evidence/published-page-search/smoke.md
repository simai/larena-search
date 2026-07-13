# Smoke

- Package autoloads `SearchServiceProvider` and `ReindexSearchCommand`.
- Source registry registration is idempotent.
- Database index is transient/current-connection while the registry is singleton.
- A projection remains queryable after database reconnect.
- Provider rows remain durable after completion while active references clear atomically.
- Both publish-first and schedule-first orderings retain the newest projection without equal-revision tombstone lockout.
- CLI requires an explicit actor and does not pre-read run existence before resume Access.
