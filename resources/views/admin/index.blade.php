@extends('larena-admin::layouts.app')

@section('title', __('larena-search::admin.title'))
@section('heading', __('larena-search::admin.heading'))
@section('eyebrow', __('larena-search::admin.eyebrow'))
@section('description', __('larena-search::admin.description'))

@section('content')
{{-- Success, error and validation notices come from the admin shell. The providers are a list over the page's host
     port: a provider opens in the record panel, its reindex steps are its row's actions. --}}
@if (is_array($providersView))
    @include('larena-admin::dataview.page', ['dataview' => $providersView, 'sectionId' => 'search-providers', 'owner' => 'larena/search',
        'ariaLabel' => __('larena-search::admin.heading'), 'queryUrl' => route('larena.search.admin.index', [], false),
        'matchedCount' => count($providers), 'source' => 'search_operations_projection'])
    {!! $panel !!}
@else
    <section class="larena-panel larena-panel-padded"><p>{{ __('larena-search::admin.empty') }}</p></section>
@endif
@endsection
