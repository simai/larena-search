# Migration rollback

`DatabaseSearchIndexTest.php` performs Search migration rollback and reapply on
a fresh temporary SQLite file and proves all four Search tables are removed and
recreated. Root SQLite/MySQL lifecycle receipts remain the integration owner.

`2026_10_22_000101_create_larena_search_document_texts` (indexed search) adds
`larena_search_document_texts`, the `title_key` column and the
`larena_search_document_order` index, and fills them for existing documents.
`DatabaseSearchSemanticsTest.php` rolls it back and reapplies it on a filled
SQLite file and proves the hits are unchanged and a second run is a no-op; the
opt-in `SearchMySqlIndexTest.php` does the same on a throwaway MySQL database
(INSTANT column, FULLTEXT side table, INPLACE index) and rolls back every Search
migration.
