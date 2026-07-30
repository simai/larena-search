<?php

declare(strict_types=1);

namespace Larena\Search\Http;

use Illuminate\Support\HtmlString;
use Larena\Search\Contracts\SearchHit;

final class PublicSearchPresenter
{
    /** @return array{title:string,locator:string,snippet:HtmlString,provider:string} */
    public function hit(SearchHit $hit, string $term): array
    {
        return [
            'title' => $hit->title,
            'locator' => $hit->locator,
            'snippet' => new HtmlString($this->highlight($hit->snippet, $term)),
            'provider' => match ($hit->providerId) {
                'docara.published_pages' => 'Docara',
                'content.published_items' => 'Content',
                default => 'Larena',
            },
        ];
    }

    private function highlight(string $text, string $term): string
    {
        $text = mb_substr(trim($text), 0, 600);
        $term = trim($term);
        if ($text === '' || $term === '') {
            return e($text);
        }

        $parts = preg_split('/(' . preg_quote($term, '/') . ')/iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return e($text);
        }

        return implode('', array_map(
            static fn (string $part, int $index): string => $index % 2 === 1 ? '<mark>' . e($part) . '</mark>' : e($part),
            $parts,
            array_keys($parts),
        ));
    }
}
