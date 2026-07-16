# Migration and rollback

The fourth Search migration creates provider fences from retained active reindex runs. Its timestamps now come from those source rows and use the literal migration timestamp only when both retained timestamps are absent.

The package test applies the migration, captures ordered semantic rows without surrogate ids, rolls it back, reapplies it, and requires byte-equivalent rows. Root acceptance separately exercises the full canonical batch on an owned disposable MySQL clone; no package test may write an existing application database.
