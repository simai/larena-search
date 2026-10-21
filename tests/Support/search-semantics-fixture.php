<?php

declare(strict_types=1);

use Larena\Search\Contracts\SearchProjection;
use Larena\Search\Contracts\SearchQuery;
use Larena\Search\Persistence\DatabaseSearchIndex;
use Larena\Search\Persistence\SearchText;

/**
 * A deterministic corpus and query set for the search semantics: the hits of
 * DatabaseSearchIndex (SQLite, and MySQL in the opt-in test) are compared with a
 * PHP reference that applies SearchText's rule to the same documents.
 */
function search_fixture_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return list<SearchProjection> */
function search_fixture_corpus(int $size): array
{
    $titles = ['Oak Chair', 'Chairs and tables', 'Armchair', 'Руководство по установке', 'Ёлка и ель', 'елка', 'SKU-0001 spare part',
        'The about page', 'Café crème', 'Chair, oak', 'chair', 'CHAIR', 'Установка Larena', 'Dining table', 'Стол обеденный', 'Ёжик'];
    $words = ['oak', 'chair', 'table', 'larena', 'the', 'about', 'установка', 'стол', 'ёлка', 'ель', 'sku', '0001', 'part', 'guide',
        'руководство', 'common', 'café', 'x', 'да', 'underscore_value', '100%', 'snake_case', 'привет', 'мир'];
    $locales = ['en', 'ru'];
    $scopes = ['public', 'public', 'public', 'staff'];
    $providers = ['docara.pages', 'content.items'];
    mt_srand(20261021);
    $corpus = [];
    for ($number = 0; $number < $size; $number++) {
        $title = $titles[mt_rand(0, count($titles) - 1)];
        if (mt_rand(0, 2) === 0) {
            $title .= ' ' . $words[mt_rand(0, count($words) - 1)];
        }
        $body = [];
        $length = mt_rand(0, 6);
        for ($word = 0; $word < $length; $word++) {
            $body[] = $words[mt_rand(0, count($words) - 1)];
        }
        // Every tenth document says "common"; two in five hundred say "rarity".
        if ($number % 10 === 0) {
            $body[] = 'common';
        }
        if (in_array($number % 500, [5, 6], true)) {
            $body[] = 'Rarity';
        }
        $corpus[] = new SearchProjection(
            providerId: $providers[$number % 2],
            sourceRef: sprintf('doc:%05d', $number),
            sourceRevision: 1,
            title: $title,
            locator: sprintf('/docs/%05d', $number),
            snippet: 'snippet ' . $number,
            locale: $locales[intdiv($number, 2) % 2],
            accessScope: $scopes[$number % 4],
            searchableText: implode(mt_rand(0, 1) === 0 ? ' ' : ",\n  ", $body),
        );
    }

    return $corpus;
}

/** @return list<array{term: string, providerId?: string, locale?: string, accessScopes?: list<string>}> */
function search_fixture_queries(): array
{
    return [
        ['term' => 'chair'],
        ['term' => 'CHAIR', 'locale' => 'en'],
        ['term' => 'oak chair'],
        ['term' => 'chair oak', 'locale' => 'ru'],
        ['term' => 'oak-chair'],
        ['term' => 'armchair'],
        ['term' => 'arm'],
        ['term' => 'ch'],
        ['term' => 'x'],
        ['term' => 'sku-0001'],
        ['term' => '0001'],
        ['term' => '100%'],
        ['term' => '%%'],
        ['term' => '__'],
        ['term' => 'underscore_'],
        ['term' => 'snake_c'],
        ['term' => 'the'],
        ['term' => 'about the'],
        ['term' => 'руковод'],
        ['term' => 'УСТАНОВКЕ'],
        ['term' => 'ёлка'],
        ['term' => 'елка'],
        ['term' => 'да мир'],
        ['term' => 'café'],
        ['term' => 'crème'],
        ['term' => 'common'],
        ['term' => 'common', 'locale' => 'en', 'providerId' => 'docara.pages'],
        ['term' => 'rarity'],
        ['term' => 'rar', 'locale' => 'ru'],
        ['term' => 'common', 'accessScopes' => ['staff']],
        ['term' => 'common', 'accessScopes' => ['public', 'staff']],
        ['term' => 'chair', 'accessScopes' => ['internal']],
        ['term' => 'larena guide'],
        ['term' => 'table chair oak larena the about guide part sku'],
    ];
}

/** @param array{term: string, providerId?: string, locale?: string, accessScopes?: list<string>} $spec */
function search_fixture_query(array $spec, int $limit = 20, int $offset = 0, ?string $after = null): SearchQuery
{
    return new SearchQuery(
        term: $spec['term'],
        providerId: $spec['providerId'] ?? null,
        locale: $spec['locale'] ?? null,
        accessScopes: $spec['accessScopes'] ?? ['public'],
        limit: $limit,
        offset: $offset,
        after: $after,
    );
}

/**
 * The reference: the source refs a query must find, in order (the title's sort
 * key, then the order the documents were written, which is their id order).
 *
 * @param list<SearchProjection> $corpus
 * @param array{term: string, providerId?: string, locale?: string, accessScopes?: list<string>} $spec
 * @return list<string>
 */
function search_fixture_expected(array $corpus, array $spec): array
{
    $terms = SearchText::terms($spec['term']);
    $scopes = $spec['accessScopes'] ?? ['public'];
    $matched = [];
    foreach ($corpus as $position => $projection) {
        if (!in_array($projection->accessScope, $scopes, true)
            || (isset($spec['providerId']) && $projection->providerId !== $spec['providerId'])
            || (isset($spec['locale']) && $projection->locale !== $spec['locale'])
            || !SearchText::matches(SearchText::document($projection->title, $projection->searchableText), $terms)) {
            continue;
        }
        $matched[] = [SearchText::titleKey($projection->title), $position, $projection->sourceRef];
    }
    usort($matched, static fn (array $left, array $right): int => [$left[0], $left[1]] <=> [$right[0], $right[1]]);

    return array_map(static fn (array $row): string => $row[2], $matched);
}

/**
 * Every hit of a query, page by page, by cursor or by offset.
 *
 * @param array{term: string, providerId?: string, locale?: string, accessScopes?: list<string>} $spec
 * @return list<string>
 */
function search_fixture_walk(DatabaseSearchIndex $index, array $spec, int $limit, bool $byCursor, int $maxPages = 1000): array
{
    $refs = [];
    $after = null;
    for ($page = 1; $page <= $maxPages; $page++) {
        $result = $index->queryPage(search_fixture_query($spec, $limit, $byCursor ? 0 : ($page - 1) * $limit, $after));
        foreach ($result->hits as $hit) {
            $refs[] = $hit->sourceRef;
        }
        search_fixture_assert($result->hasNext === ($result->nextCursor !== null), 'A page has a next cursor exactly when it has a next page.');
        if (!$result->hasNext) {
            return $refs;
        }
        $after = $byCursor ? $result->nextCursor : null;
    }

    throw new RuntimeException('The walk did not end.');
}

/**
 * Checks every query of the set against the reference, whole (one page of 100
 * when it fits) and walked by cursor and by offset.
 *
 * @param list<SearchProjection> $corpus
 * @return array<string, list<string>> The hits of each query, by its JSON.
 */
function search_fixture_check_all(DatabaseSearchIndex $index, array $corpus, string $label): array
{
    $results = [];
    foreach (search_fixture_queries() as $spec) {
        $expected = search_fixture_expected($corpus, $spec);
        $name = json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $byCursor = search_fixture_walk($index, $spec, 7, true);
        search_fixture_assert($byCursor === $expected, $label . ': cursor pages of ' . $name . ' differ from the reference (' . count($byCursor) . ' vs ' . count($expected) . ').');
        if (count($expected) <= 400) {
            $byOffset = search_fixture_walk($index, $spec, 7, false);
            search_fixture_assert($byOffset === $expected, $label . ': offset pages of ' . $name . ' differ from the reference.');
        }
        $results[$name] = $byCursor;
    }

    return $results;
}
