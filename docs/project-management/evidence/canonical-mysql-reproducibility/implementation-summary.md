# Implementation summary

- Replaced wall-clock timestamps in the provider-state backfill migration.
- Derived created and updated timestamps from each retained reindex run.
- Kept a stable migration-identity fallback for legacy rows without timestamps.
- Added regression assertions for references and exact timestamps.
