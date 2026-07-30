<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index,follow">
    <title>{{ __('larena-search::public.title') }}</title>
    @foreach($assets as $asset)@if($asset['kind'] === 'css')<link rel="stylesheet" href="{{ $asset['path'] }}" data-larena-asset-key="{{ $asset['key'] }}">@endif @endforeach
    @foreach($assets as $asset)@if($asset['kind'] === 'javascript')<script defer src="{{ $asset['path'] }}" data-larena-asset-key="{{ $asset['key'] }}"></script>@endif @endforeach
</head>
<body class="sf-theme-system">
<a href="#search-results">{{ __('larena-search::public.skip') }}</a>
<main id="search-results" tabindex="-1">
    <header><p>Larena</p><h1>{{ __('larena-search::public.heading') }}</h1></header>
    <form method="get" action="{{ route('larena.search.public') }}" role="search">
        <label for="search-query">{{ __('larena-search::public.query') }}</label>
        <input id="search-query" name="q" type="search" value="{{ $term }}" maxlength="200" required>
        <label for="search-locale">{{ __('larena-search::public.locale') }}</label>
        <select id="search-locale" name="locale">@foreach($locales as $choice)<option value="{{ $choice }}" @selected($locale === $choice)>{{ strtoupper($choice) }}</option>@endforeach</select>
        <button type="submit" class="sf-button">{{ __('larena-search::public.submit') }}</button>
    </form>

    <section aria-live="polite">
        @if($state === 'empty')<p>{{ __('larena-search::public.empty') }}</p>@endif
        @if($state === 'no_results')<p>{{ __('larena-search::public.no_results') }}</p>@endif
        @if($state === 'error')<p role="alert">{{ __('larena-search::public.error') }}</p>@endif
        @if($state === 'success')
            <h2>{{ __('larena-search::public.results') }}</h2>
            <ol>@foreach($hits as $hit)<li><article><p>{{ $hit['provider'] }}</p><h3><a href="{{ $hit['locator'] }}">{{ $hit['title'] }}</a></h3><p>{!! $hit['snippet'] !!}</p></article></li>@endforeach</ol>
            <nav aria-label="{{ __('larena-search::public.pagination') }}">
                @if($page > 1)<a rel="prev" href="{{ route('larena.search.public', ['q' => $term, 'locale' => $locale, 'page' => $page - 1]) }}">{{ __('larena-search::public.previous') }}</a>@endif
                @if($result?->hasNext)<a rel="next" href="{{ route('larena.search.public', ['q' => $term, 'locale' => $locale, 'page' => $page + 1]) }}">{{ __('larena-search::public.next') }}</a>@endif
            </nav>
        @endif
    </section>
</main>
</body>
</html>
