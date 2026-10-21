# Package tests

Canonical command:

`/Users/rim/Documents/GitHub/larena/scripts/larena-toolchain run -- bash -lc 'cd /Users/rim/Documents/GitHub/larena-workspace/packages/search && composer quality:gate'`

The suite covers index ordering and bounds, locator rejection, escaped
highlighting, public projection shape, pinned SIMAI Framework assets, Access
operation registration and a real SQLite Queue failure/restart/resume flow with
101 projections and 100-item checkpoints.

Indexed search: `DatabaseSearchSemanticsTest.php` (word-prefix semantics,
multiword, punctuation, Cyrillic, order, cursor and offset paging stability,
visibility under forged cursors, the SQLite plan without a sort, migration
backfill and rollback) and the opt-in `composer test:mysql`
(`SearchMySqlIndexTest.php`: the same corpus on a throwaway MySQL database,
compared with the PHP reference and with SQLite, both ways of answering, the
FULLTEXT plan, stopwords off).
