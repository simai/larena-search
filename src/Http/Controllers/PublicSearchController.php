<?php

declare(strict_types=1);

namespace Larena\Search\Http\Controllers;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Larena\Search\Contracts\SearchQuery;
use Larena\Search\Exceptions\SearchPersistenceFailed;
use Larena\Search\Http\PublicSearchPresenter;
use Larena\Search\Http\PublicSearchAssets;
use Larena\Search\Persistence\DatabaseSearchIndex;

final readonly class PublicSearchController
{
    public function __construct(
        private DatabaseSearchIndex $index,
        private PublicSearchPresenter $presenter,
        private PublicSearchAssets $assets,
        private Factory $views,
        private Config $config,
        private Translator $translator,
    ) {
    }

    public function __invoke(Request $request): mixed
    {
        $locales = array_values(array_filter((array) $this->config->get('larena-search.public.locales', ['ru', 'en']), 'is_string'));
        $defaultLocale = (string) $this->config->get('larena-search.public.default_locale', 'ru');
        $minimumTermLength = max(1, min(20, (int) $this->config->get('larena-search.public.minimum_term_length', 2)));
        $request->merge(['q' => trim((string) $request->input('q', ''))]);
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'min:' . $minimumTermLength, 'max:200'],
            'locale' => ['nullable', 'string', 'in:' . implode(',', $locales)],
            'page' => ['nullable', 'integer', 'min:1', 'max:' . (int) $this->config->get('larena-search.public.maximum_page', 500)],
        ]);
        $term = trim((string) ($validated['q'] ?? ''));
        $locale = (string) ($validated['locale'] ?? $defaultLocale);
        $this->translator->setLocale($locale);
        $page = (int) ($validated['page'] ?? 1);
        $perPage = max(1, min(50, (int) $this->config->get('larena-search.public.per_page', 20)));
        $state = $term === '' ? 'empty' : 'success';
        $result = null;
        $hits = [];

        if ($term !== '') {
            try {
                $result = $this->index->queryPage(new SearchQuery(
                    term: $term,
                    locale: $locale,
                    accessScopes: ['public'],
                    limit: $perPage,
                    offset: ($page - 1) * $perPage,
                ));
                $hits = array_map(fn ($hit): array => $this->presenter->hit($hit, $term), $result->hits);
                if ($hits === []) {
                    $state = 'no_results';
                }
            } catch (SearchPersistenceFailed) {
                $state = 'error';
            }
        }

        $assets = $this->assets->all();
        $response = $this->views->make('larena-search::public.search', compact('term', 'locale', 'locales', 'page', 'result', 'hits', 'state', 'assets'));

        return $state === 'error' ? new Response($response->render(), 503) : $response;
    }
}
