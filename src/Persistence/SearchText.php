<?php

declare(strict_types=1);

namespace Larena\Search\Persistence;

/**
 * The text rule of the search index, the same as Storage's record lists
 * (Larena\Storage\View\StorageViewSearch), on MySQL and SQLite alike:
 *
 * - a word is a run of letters, combining marks, digits and underscores; text is
 *   lowercased as mb_strtolower does;
 * - the search text of a document is the words of its title and the words of its
 *   searchable text, each field a run of words separated by single spaces, the
 *   two fields separated by the unit separator (so nothing matches across them),
 *   with a leading space. It is computed once, when the document is written;
 * - a query is split on whitespace into terms; each term is turned into its words
 *   the same way (so `sku-0001` is `sku 0001`), and a document matches when every
 *   term occurs in its search text at the start of a word: `chair` finds "Chair"
 *   and "chairs", not "armchair"; `oak ch` finds "Oak Chair". Terms match in any
 *   order and in either field. A query of punctuation alone finds nothing.
 *
 * In SQL a term is `search_text LIKE '% <words>%'` (binary on MySQL, so exact). On
 * MySQL the FULLTEXT index (InnoDB's default parser, which splits the stored text
 * at the same places, built with the stopword list off) finds the candidates
 * first, with `+word*` for the longest word of the query that has at least
 * innodb_ft_min_token_size characters; the LIKE then checks them. Every document
 * the LIKE accepts has that word at the start of a word, so the index never
 * finds fewer documents than the LIKE and both ways give the same hits.
 */
final class SearchText
{
    public const MAX_TERMS = 16;
    // InnoDB's defaults, when the server cannot be asked.
    public const DEFAULT_MIN_TOKEN = 3;
    public const DEFAULT_MAX_TOKEN = 84;
    public const FIELD_SEPARATOR = "\x1F";
    // The sort key is a VARCHAR(191) with a binary collation: the index of
    // (access_scope, locale, title_key, id) stays under InnoDB's key size.
    public const TITLE_KEY_LENGTH = 191;
    public const ESCAPE = '!';

    private const WORD = '[\p{L}\p{M}\p{N}_]+';

    /** The search text of a document: the words of its title and of its searchable text. */
    public static function document(string $title, string $searchableText): string
    {
        $fields = [];
        foreach ([$title, $searchableText] as $value) {
            $words = self::words($value);
            if ($words !== []) {
                $fields[] = implode(' ', $words);
            }
        }

        return $fields === [] ? '' : ' ' . implode(' ' . self::FIELD_SEPARATOR . ' ', $fields);
    }

    /**
     * The sort key of a title: lowercased, whitespace collapsed, `ё` as `е` (as
     * Russian dictionaries order it), at most TITLE_KEY_LENGTH characters. It
     * compares byte for byte on MySQL (utf8mb4_bin) and SQLite alike.
     */
    public static function titleKey(string $title): string
    {
        $collapsed = preg_replace('/[\s\p{Z}]+/u', ' ', self::lower($title));
        $key = trim(is_string($collapsed) ? $collapsed : self::lower($title));
        $key = str_replace('ё', 'е', $key);

        return mb_substr($key, 0, self::TITLE_KEY_LENGTH, 'UTF-8');
    }

    /** @return list<string> The lowercased words of a text. */
    public static function words(string $value): array
    {
        if (preg_match_all('/' . self::WORD . '/u', self::lower($value), $matches) === false) {
            return [];
        }

        return $matches[0];
    }

    /**
     * The terms of a query: each term's words joined by a space, distinct, at most
     * MAX_TERMS. A term without a word (punctuation alone) is dropped.
     *
     * @return list<string>
     */
    public static function terms(string $search): array
    {
        $parts = preg_split('/[\s\p{Z}]+/u', $search, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) {
            $parts = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $terms = [];
        foreach ($parts as $part) {
            $words = self::words($part);
            if ($words !== []) {
                $terms[implode(' ', $words)] = true;
            }
        }

        return array_slice(array_map('strval', array_keys($terms)), 0, self::MAX_TERMS);
    }

    /** The LIKE pattern of a term: its words at the start of a word (escape character `!`). */
    public static function likePattern(string $term): string
    {
        return '% ' . str_replace([self::ESCAPE, '%', '_'], [self::ESCAPE . self::ESCAPE, self::ESCAPE . '%', self::ESCAPE . '_'], $term) . '%';
    }

    /**
     * The boolean-mode query for the FULLTEXT index: `+word*` for the longest word
     * of the terms the index holds (between the server's minimum and maximum token
     * size; the first of equally long ones), or null when no word is long enough.
     *
     * @param list<string> $terms
     */
    public static function booleanQuery(array $terms, int $minToken = self::DEFAULT_MIN_TOKEN, int $maxToken = self::DEFAULT_MAX_TOKEN): ?string
    {
        $best = null;
        $bestLength = 0;
        foreach ($terms as $term) {
            foreach (explode(' ', $term) as $word) {
                $length = mb_strlen($word, 'UTF-8');
                if ($word !== '' && $length >= max(1, $minToken) && $length <= $maxToken && $length > $bestLength) {
                    $best = $word;
                    $bestLength = $length;
                }
            }
        }

        return $best === null ? null : '+' . $best . '*';
    }

    /**
     * The same match in PHP: every term at the start of a word of the search text.
     *
     * @param list<string> $terms
     */
    public static function matches(string $text, array $terms): bool
    {
        if ($terms === []) {
            return false;
        }
        foreach ($terms as $term) {
            if (!str_contains($text, ' ' . $term)) {
                return false;
            }
        }

        return true;
    }

    private static function lower(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }
}
