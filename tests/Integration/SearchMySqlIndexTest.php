<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Schema;
use Larena\Search\Persistence\DatabaseSearchIndex;
use Larena\Search\Tests\Support\SearchTestDatabase;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/Support/mysql-disposable-database.php';
require_once dirname(__DIR__) . '/Support/search-semantics-fixture.php';

// The indexed search on a throwaway MySQL database (opt-in): the migration (INSTANT
// column, FULLTEXT side table without stopwords, INPLACE order index, backfill and
// rollback), the same corpus and queries as the SQLite test against the PHP
// reference and against SQLite, and both ways of answering (rows checked in order,
// candidates from the FULLTEXT index).

$optIn = getenv('LARENA_SEARCH_MYSQL_TEST');
if (!is_string($optIn) || !filter_var($optIn, FILTER_VALIDATE_BOOL)) {
    echo "SearchMySqlIndexTest skipped (explicit opt-in required).\n";
    exit(0);
}

const SEARCH_MYSQL_MIGRATIONS = [
    '2026_07_13_000001_create_larena_search_documents.php',
    '2026_07_13_000002_create_larena_search_source_states.php',
    '2026_07_13_000003_create_larena_search_reindex_runs.php',
    '2026_07_13_000004_create_larena_search_provider_states.php',
    '2026_10_22_000101_create_larena_search_document_texts.php',
];

/** @return object The migration of that file. */
function search_mysql_migration(string $file): object
{
    $migration = require dirname(__DIR__, 2) . '/database/migrations/' . $file;
    search_fixture_assert(is_object($migration), 'Invalid migration ' . $file);

    return $migration;
}

/** @return list<string> The SQL of each query $run sends. */
function search_mysql_sql(Connection $connection, Closure $run): array
{
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    try {
        $run();
    } finally {
        $connection->disableQueryLog();
    }

    return array_map(static fn (array $entry): string => (string) $entry['query'], $connection->getQueryLog());
}

$database = larena_search_mysql_disposable_database('larena_search_index_test_');
$sqlite = null;
try {
    $connection = larena_search_mysql_connect($database['config']);
    foreach (SEARCH_MYSQL_MIGRATIONS as $file) {
        search_mysql_migration($file)->up();
    }
    $fulltext = $connection->select("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'larena_search_document_texts' AND INDEX_TYPE = 'FULLTEXT'");
    search_fixture_assert(count($fulltext) === 1, 'The search texts carry one FULLTEXT index.');
    $order = $connection->select("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'larena_search_documents' AND INDEX_NAME = 'larena_search_document_order' ORDER BY SEQ_IN_INDEX");
    search_fixture_assert(array_map(static fn (object $row): string => (string) $row->COLUMN_NAME, $order) === ['access_scope', 'locale', 'title_key', 'id'], 'The order index is (access_scope, locale, title_key, id).');
    $collation = $connection->selectOne("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'larena_search_documents' AND COLUMN_NAME = 'title_key'");
    search_fixture_assert(($collation->COLLATION_NAME ?? null) === 'utf8mb4_bin', 'The sort key compares byte for byte, as on SQLite.');
    // A FULLTEXT index on the documents table would forbid INSTANT column changes there; the side table keeps them.
    $connection->statement("ALTER TABLE `larena_search_documents` ADD COLUMN `probe_instant` TINYINT NULL, ALGORITHM=INSTANT");
    $connection->statement('ALTER TABLE `larena_search_documents` DROP COLUMN `probe_instant`, ALGORITHM=INSTANT');

    $index = new DatabaseSearchIndex($connection);
    $corpus = search_fixture_corpus(2500);
    foreach ($corpus as $projection) {
        $index->upsert($projection);
    }
    $connection->statement('ANALYZE TABLE `larena_search_documents`, `larena_search_document_texts`');
    $mysqlHits = search_fixture_check_all($index, $corpus, 'mysql');

    // The same corpus on SQLite gives the same hits in the same order.
    $sqlite = SearchTestDatabase::create(false);
    $sqliteIndex = new DatabaseSearchIndex($sqlite->connection());
    foreach ($corpus as $projection) {
        $sqliteIndex->upsert($projection);
    }
    search_fixture_assert(search_fixture_check_all($sqliteIndex, $corpus, 'sqlite') === $mysqlHits, 'MySQL and SQLite must give the same hits in the same order.');
    $sqlite->close();
    $sqlite = null;
    $connection = larena_search_mysql_connect($database['config']);
    $index = new DatabaseSearchIndex($connection);

    // A common word fills its page from the first rows in order; a rare word is
    // found through the FULLTEXT index; a word below the token size is checked in order.
    $common = search_mysql_sql($connection, static fn () => $index->queryPage(search_fixture_query(['term' => 'common', 'locale' => 'en'], 20)));
    search_fixture_assert(!str_contains(implode("\n", $common), 'MATCH('), 'A common word is answered from the rows in order.');
    $rare = search_mysql_sql($connection, static fn () => $index->queryPage(search_fixture_query(['term' => 'rarity'], 20)));
    search_fixture_assert(str_contains(implode("\n", $rare), 'MATCH('), 'A rare word is answered through the FULLTEXT index.');
    $short = search_mysql_sql($connection, static fn () => $index->queryPage(search_fixture_query(['term' => 'x'], 20)));
    search_fixture_assert(!str_contains(implode("\n", $short), 'MATCH('), 'A word below the token size has no FULLTEXT form.');
    // Past a window of 1000 rows that holds 10% matches, page 8 lies within the walk limit: it walks on.
    $deepPage = null;
    $deep = search_mysql_sql($connection, static function () use ($index, &$deepPage): void {
        $deepPage = $index->queryPage(search_fixture_query(['term' => 'common'], 20, 140));
    });
    search_fixture_assert(!str_contains(implode("\n", $deep), 'MATCH('), 'A common word on a deep page walks on in order instead of sorting every FULLTEXT candidate.');
    search_fixture_assert(array_map(static fn ($hit): string => $hit->sourceRef, $deepPage->hits) === array_slice(search_fixture_expected($corpus, ['term' => 'common']), 140, 20), 'The walked deep page is the reference page.');
    $connection->enableQueryLog();
    $index->queryPage(search_fixture_query(['term' => 'rarity'], 20));
    $entry = array_values(array_filter($connection->getQueryLog(), static fn (array $item): bool => str_contains((string) $item['query'], 'MATCH(')))[0] ?? null;
    $connection->disableQueryLog();
    search_fixture_assert($entry !== null, 'The FULLTEXT query was not logged.');
    $plan = $connection->select('EXPLAIN ' . $entry['query'], $entry['bindings']);
    search_fixture_assert(in_array('fulltext', array_map(static fn (object $row): string => (string) $row->type, $plan), true), 'The rare-word query reads the FULLTEXT index.');
    // Stopwords are off: "the" and "about" are words like any other.
    search_fixture_assert($index->queryPage(search_fixture_query(['term' => 'about the'], 20))->hits !== [], 'Default stopwords must stay searchable.');

    // Rollback and reapply of the migration on filled MySQL tables: the backfill.
    $before = array_map(static fn (array $spec): array => search_fixture_walk($index, $spec, 50, true), search_fixture_queries());
    $migration = search_mysql_migration(SEARCH_MYSQL_MIGRATIONS[4]);
    $migration->down();
    search_fixture_assert(!Schema::hasTable('larena_search_document_texts') && !Schema::hasColumn('larena_search_documents', 'title_key'), 'Rollback removes the search texts and the sort key on MySQL.');
    $migration->up();
    $after = array_map(static fn (array $spec): array => search_fixture_walk(new DatabaseSearchIndex($connection), $spec, 50, true), search_fixture_queries());
    search_fixture_assert($after === $before, 'Backfilled documents are found exactly as freshly written ones on MySQL.');

    foreach (array_reverse(SEARCH_MYSQL_MIGRATIONS) as $file) {
        search_mysql_migration($file)->down();
    }
    search_fixture_assert(!Schema::hasTable('larena_search_documents') && !Schema::hasTable('larena_search_document_texts'), 'Every Search table is gone after rollback.');
} finally {
    $sqlite?->close();
    ($database['drop'])();
}

echo "SearchMySqlIndexTest passed.\n";
