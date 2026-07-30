# Migration rollback

`DatabaseSearchIndexTest.php` performs Search migration rollback and reapply on
a fresh temporary SQLite file and proves all four Search tables are removed and
recreated. Root SQLite/MySQL lifecycle receipts remain the integration owner.
