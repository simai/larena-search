# Migration And Rollback

This correction adds no database migration and changes no Search table.

Rollback is code-only: pin the preceding exact Search revision, regenerate the
root lock/manifest, and rerun Search plus Content/Search integration tests.
Existing Search documents, source states, runs and provider fences require no
data rollback.
