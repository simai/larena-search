<?php

declare(strict_types=1);

use Larena\Search\Contracts\SearchHit;
use Larena\Search\Http\PublicSearchAssets;
use Larena\Search\Http\PublicSearchPresenter;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function public_search_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$presented = (new PublicSearchPresenter())->hit(new SearchHit(
    'docara.published_pages', 'private-internal-ref', 99,
    '<img src=x onerror=alert(1)> Needle', '/docs/safe',
    '<script>alert(1)</script> needle text', 'en', 'public',
    ['internal_id' => 42],
), 'needle');
$html = (string) $presented['snippet'];
public_search_assert(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'), 'Snippet executable markup must be escaped.');
public_search_assert(str_contains($html, '<mark>needle</mark>'), 'Matched text must be highlighted after escaping.');
$publicShape = json_decode(json_encode($presented, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
public_search_assert(is_array($publicShape) && array_keys($publicShape) === ['title', 'locator', 'snippet', 'provider'], 'Presenter must not expose internal identifiers or payload.');

$assets = (new PublicSearchAssets())->all();
public_search_assert(count($assets) >= 4, 'Public Search must activate the pinned Simai Framework runtime pair.');
public_search_assert(in_array('simai.framework.core.css', array_column($assets, 'key'), true), 'Simai Framework core CSS must be activated.');
public_search_assert(in_array('simai.framework.core.js', array_column($assets, 'key'), true), 'Simai Framework core JavaScript must be activated.');

$view = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/public/search.blade.php');
public_search_assert(str_contains($view, "{!! \$hit['snippet'] !!}") && str_contains($view, "{{ \$hit['title'] }}"), 'Only the escaped presenter snippet may cross the raw HTML boundary.');
public_search_assert(!str_contains($view, "source_ref") && !str_contains($view, "payload"), 'Public view must not reference private index fields.');
public_search_assert(str_contains($view, 'content="noindex,follow"'), 'Internal search result pages must not be indexed by crawlers.');

echo "PublicSearchPresentationTest passed.\n";
