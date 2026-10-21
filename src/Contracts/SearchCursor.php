<?php

declare(strict_types=1);

namespace Larena\Search\Contracts;

use InvalidArgumentException;

/**
 * The position after the last hit of a page in the index's order (the title's
 * sort key, then the document id): the next page starts after it instead of
 * skipping rows by offset. It only positions a query; the query's access
 * scopes, provider and locale still decide which documents can be seen, so an
 * altered cursor can move a page but never shows anything more.
 */
final readonly class SearchCursor
{
    public const MAX_ENCODED_LENGTH = 1024;

    public function __construct(
        public string $titleKey,
        public int $documentId,
    ) {
        if ($this->documentId < 1 || mb_strlen($this->titleKey, 'UTF-8') > 191 || preg_match('//u', $this->titleKey) !== 1) {
            throw new InvalidArgumentException('search_query_cursor_invalid');
        }
    }

    /** The cursor as an opaque URL-safe string. */
    public function encode(): string
    {
        $json = json_encode([$this->titleKey, $this->documentId], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    public static function decode(string $encoded): self
    {
        if ($encoded === '' || strlen($encoded) > self::MAX_ENCODED_LENGTH || preg_match('/^[A-Za-z0-9_-]+$/', $encoded) !== 1) {
            throw new InvalidArgumentException('search_query_cursor_invalid');
        }
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        if (!is_string($json)) {
            throw new InvalidArgumentException('search_query_cursor_invalid');
        }
        try {
            $decoded = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('search_query_cursor_invalid');
        }
        if (!is_array($decoded) || !array_is_list($decoded) || count($decoded) !== 2 || !is_string($decoded[0]) || !is_int($decoded[1])) {
            throw new InvalidArgumentException('search_query_cursor_invalid');
        }

        return new self($decoded[0], $decoded[1]);
    }
}
