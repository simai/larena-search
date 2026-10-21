<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Schema;
use Larena\Search\Contracts\SearchCursor;
use Larena\Search\Contracts\SearchProjection;
use Larena\Search\Contracts\SearchQuery;
use Larena\Search\Persistence\DatabaseSearchIndex;
use Larena\Search\Persistence\SearchText;
use Larena\Search\Tests\Support\SearchTestDatabase;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/Support/search-semantics-fixture.php';

// Word-prefix semantics, order, paging and visibility of the indexed search on
// SQLite, against the PHP reference of tests/Support/search-semantics-fixture.php
// (the opt-in MySQL test runs the same set there).

/** @return list<string> */
function search_semantics_refs(DatabaseSearchIndex $index, SearchQuery $query): array
{
    return array_map(static fn ($hit): string => $hit->sourceRef, $index->queryPage($query)->hits);
}

$database = SearchTestDatabase::create(false);

try {
    $connection = $database->connection();
    if (!$connection instanceof Connection) {
        throw new RuntimeException('The test needs a Laravel connection.');
    }
    $index = new DatabaseSearchIndex($connection);
    $write = static function (string $ref, string $title, string $text, string $scope = 'public', ?string $locale = 'en', string $provider = 'docara.pages') use ($index): void {
        $index->upsert(new SearchProjection($provider, $ref, 1, $title, '/docs/' . $ref, '', $locale, $scope, $text));
    };

    // The rule, word by word.
    $write('chairs', 'Chairs', 'four legs');
    $write('armchair', 'Armchair', 'soft');
    $write('oak', 'Chair, oak', 'solid');
    $write('fields', 'Oak', 'chair-like');
    $write('sku', 'Spare part', 'SKU-0001; 100% steel, snake_case');
    $write('ru', 'Руководство по УСТАНОВКЕ', 'Ёлка, ель и сосна');
    $write('cafe', 'Café crème', 'déjà vu');
    $write('staff', 'Chair of the board', 'internal minutes', 'staff');
    $write('ru-locale', 'Chair', 'ru only', 'public', 'ru');
    $find = static fn (string $term, array $extra = []): array => search_semantics_refs($index, new SearchQuery($term, ...$extra));

    search_fixture_assert($find('chair') === ['ru-locale', 'oak', 'chairs', 'fields'], 'A term must start a word: "chair" finds Chair, Chairs, "Chair, oak" and chair-like, never Armchair (got ' . implode(',', $find('chair')) . ').');
    search_fixture_assert($find('CHAIR') === $find('chair'), 'Matching must be case-insensitive.');
    search_fixture_assert($find('arm') === ['armchair'] && $find('mchair') === [], 'A term matches at the start of a word only.');
    search_fixture_assert($find('oak chair') === ['oak', 'fields'] && $find('chair oak') === ['oak', 'fields'], 'Terms match in any order and in either field.');
    search_fixture_assert($find('oak-chair') === [] && $find('chair-like') === ['fields'], 'Words of one term are consecutive and never span the title and the text.');
    search_fixture_assert($find('sku-0001') === ['sku'] && $find('0001') === ['sku'] && $find('SKU 0001') === ['sku'], 'Punctuation separates words.');
    search_fixture_assert($find('100%') === ['sku'] && $find('%') === [] && $find('%%') === [] && $find('!!') === [], 'Punctuation alone finds nothing; wildcards are never wildcards.');
    search_fixture_assert($find('snake_c') === ['sku'] && $find('_case') === [] && $find('__') === [], 'An underscore is a word character, matched literally.');
    search_fixture_assert($find('руковод') === ['ru'] && $find('установке') === ['ru'] && $find('ЁЛКА') === ['ru'] && $find('ель') === ['ru'], 'Cyrillic words match by prefix, case-insensitively.');
    search_fixture_assert($find('елка') === [], 'ё and е are different letters for matching (as in Storage).');
    search_fixture_assert($find('déj') === ['cafe'] && $find('creme') === [], 'Accented letters are letters; no accent folding.');

    // Visibility is exactly the query's scopes, provider and locale.
    search_fixture_assert(!in_array('staff', $find('chair'), true) && !in_array('ru-locale', $find('chair', ['locale' => 'en']), true), 'A public query never sees staff documents or another locale.');
    search_fixture_assert($find('board', ['accessScopes' => ['staff']]) === ['staff'] && $find('board') === [], 'Only the named scopes are searched.');
    search_fixture_assert($find('chair', ['providerId' => 'content.items']) === [], 'The provider filter is exact.');
    search_fixture_assert($find('chair', ['locale' => 'ru']) === ['ru-locale'], 'The locale filter is exact.');
    // A cursor only positions: one made up before every key still shows public documents only.
    $forged = (new SearchCursor('', 1))->encode();
    $paged = $index->queryPage(new SearchQuery('chair', limit: 50, after: $forged));
    search_fixture_assert(!in_array('staff', array_map(static fn ($hit): string => $hit->sourceRef, $paged->hits), true), 'A forged cursor never widens what a query sees.');
    foreach (['', 'not base64!', base64_encode('{"a":1}'), rtrim(strtr(base64_encode('["x",0]'), '+/', '-_'), '=')] as $bad) {
        $rejected = false;
        try {
            new SearchQuery('chair', after: $bad);
        } catch (InvalidArgumentException $exception) {
            $rejected = $exception->getMessage() === 'search_query_cursor_invalid';
        }
        search_fixture_assert($rejected, 'A malformed cursor is refused as search_query_cursor_invalid.');
    }

    // The stored text is normalized when the document is written.
    $stored = (string) $connection->table('larena_search_document_texts as t')
        ->join('larena_search_documents as d', 'd.id', '=', 't.document_id')
        ->where('d.source_ref', 'sku')->value('t.search_text');
    search_fixture_assert($stored === ' spare part ' . SearchText::FIELD_SEPARATOR . ' sku 0001 100 steel snake_case', 'The search text holds the lowercased words of the title and the text, apart.');
    search_fixture_assert($connection->table('larena_search_documents')->where('source_ref', 'ru')->value('title_key') === 'руководство по установке', 'The sort key is the lowercased title.');
    search_fixture_assert(SearchText::titleKey("  Ёлка \t\n  Ель ") === 'елка ель', 'The sort key collapses whitespace and orders ё as е.');

    // A rewrite replaces the text; a removal takes it away.
    $index->upsert(new SearchProjection('docara.pages', 'armchair', 2, 'Recliner', '/docs/armchair', '', 'en', 'public', 'soft'));
    search_fixture_assert($find('armchair') === [] && $find('recliner') === ['armchair'], 'A newer revision replaces the search text.');
    $index->remove('docara.pages', 'armchair', 3);
    search_fixture_assert($find('recliner') === [] && $connection->table('larena_search_document_texts')->count() === $connection->table('larena_search_documents')->count(), 'A removal takes the search text away with the document.');

    // Order: the title's sort key (case-insensitive, whitespace collapsed), then the id.
    foreach (['b2' => 'beta', 'a1' => 'Alpha', 'b1' => 'Beta', 'a2' => 'alpha', 'z' => 'zeta', 'e' => 'Ёж'] as $ref => $title) {
        $write('order-' . $ref, $title, 'ordering');
    }
    search_fixture_assert($find('ordering') === ['order-a1', 'order-a2', 'order-b2', 'order-b1', 'order-z', 'order-e'], 'Hits are ordered by title, case-insensitively, then by when they were indexed.');

    // The public query walks the order index: no sort of the matched rows on SQLite.
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $index->queryPage(new SearchQuery('ordering', locale: 'en', limit: 2));
    $log = $connection->getQueryLog();
    $connection->disableQueryLog();
    search_fixture_assert(count($log) === 2, 'A page costs two queries (keys, then documents), got ' . count($log) . '.');
    $plan = implode("\n", array_map(static fn (object $row): string => (string) $row->detail, $connection->select('EXPLAIN QUERY PLAN ' . $log[0]['query'], $log[0]['bindings'])));
    search_fixture_assert(str_contains($plan, 'larena_search_document_order') && !str_contains($plan, 'TEMP B-TREE'), "The public query must read the order index without sorting:\n" . $plan);
    search_fixture_assert(!str_contains(strtolower($log[0]['query']), 'lower('), 'The query must not lowercase rows.');

    // Paging: by cursor and by offset, the same hits in the same order; a document
    // written between two cursor pages does not repeat or shift the next page.
    $first = $index->queryPage(new SearchQuery('ordering', limit: 2));
    $write('order-a0', 'Aardvark', 'ordering');
    $second = $index->queryPage(new SearchQuery('ordering', limit: 2, offset: 2, after: $first->nextCursor));
    search_fixture_assert(array_map(static fn ($hit): string => $hit->sourceRef, $second->hits) === ['order-b2', 'order-b1'] && $second->page === 2, 'A cursor page continues after the last hit, whatever was written before it.');

    // The corpus against the reference.
    $database->close();
    $database = SearchTestDatabase::create(false);
    $connection = $database->connection();
    $index = new DatabaseSearchIndex($connection);
    $corpus = search_fixture_corpus(600);
    foreach ($corpus as $projection) {
        $index->upsert($projection);
    }
    search_fixture_check_all($index, $corpus, 'sqlite');

    // The migration fills documents written before it, and rolls back cleanly.
    $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_22_000101_create_larena_search_document_texts.php';
    $before = array_map(static fn (array $spec): array => search_fixture_walk($index, $spec, 50, true), search_fixture_queries());
    $migration->down();
    search_fixture_assert(!Schema::hasTable('larena_search_document_texts') && !Schema::hasColumn('larena_search_documents', 'title_key'), 'Rollback removes the search texts and the sort key.');
    $migration->up();
    search_fixture_assert(Schema::hasTable('larena_search_document_texts') && $connection->table('larena_search_document_texts')->count() === count($corpus), 'The migration fills the search text of every existing document.');
    $after = array_map(static fn (array $spec): array => search_fixture_walk(new DatabaseSearchIndex($connection), $spec, 50, true), search_fixture_queries());
    search_fixture_assert($after === $before, 'Backfilled documents are found exactly as freshly written ones.');
    $migration->up();
    search_fixture_assert($connection->table('larena_search_document_texts')->count() === count($corpus), 'Running the migration again changes nothing.');
} finally {
    $database->close();
}

echo "DatabaseSearchSemanticsTest passed.\n";
