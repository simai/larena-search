@extends('larena-admin::layouts.app')

@section('title', __('larena-search::admin.title'))
@section('heading', __('larena-search::admin.heading'))
@section('eyebrow', __('larena-search::admin.eyebrow'))
@section('description', __('larena-search::admin.description'))

@section('content')
{{-- Success, error and validation notices come from the admin shell. --}}

@if (is_array($selected))
<section id="search-provider" class="larena-panel larena-panel-padded" aria-labelledby="search-provider-title" data-larena-search-provider="{{ $selected['provider_id'] }}">
    <h2 id="search-provider-title"><code>{{ $selected['provider_id'] }}</code></h2>
    <dl>
        <dt>{{ __('larena-search::admin.state') }}</dt><dd>{{ $selected['state'] }}</dd>
        <dt>{{ __('larena-search::admin.progress') }}</dt><dd>{{ $selected['processed_count'] }} / {{ $selected['batch_count'] }}</dd>
        <dt>{{ __('larena-search::admin.generation') }}</dt><dd><code>{{ $selected['generation_ref'] ? substr($selected['generation_ref'], 0, 20) : '—' }}</code></dd>
        <dt>{{ __('larena-search::admin.error') }}</dt><dd>{{ $selected['error_code'] ?? '—' }}</dd>
    </dl>
    <div class="larena-form-actions">
    @if ($canSchedule && in_array($selected['state'], ['idle', 'completed'], true))
        <form method="post" action="{{ route('larena.search.admin.schedule', $selected['provider_id']) }}">@csrf<input type="hidden" name="expected_state" value="{{ $selected['state'] }}">{!! \Larena\Admin\Runtime\AdminControls::submit(__('larena-search::admin.schedule'), 'primary') !!}</form>
    @endif
    @if ($canRun && $selected['state'] === 'scheduled' && $selected['run_ref'])
        <form method="post" action="{{ route('larena.search.admin.run', [$selected['provider_id'], $selected['run_ref']]) }}">@csrf<input type="hidden" name="expected_state" value="scheduled">{!! \Larena\Admin\Runtime\AdminControls::submit(__('larena-search::admin.run'), 'primary') !!}</form>
    @endif
    @if ($canResume && $selected['state'] === 'running' && $selected['run_ref'])
        <form method="post" action="{{ route('larena.search.admin.resume', [$selected['provider_id'], $selected['run_ref']]) }}">@csrf<input type="hidden" name="expected_state" value="running">{!! \Larena\Admin\Runtime\AdminControls::submit(__('larena-search::admin.resume')) !!}</form>
    @endif
    @if ($canRetry && $selected['state'] === 'failed' && $selected['run_ref'])
        <form method="post" action="{{ route('larena.search.admin.retry', [$selected['provider_id'], $selected['run_ref']]) }}">@csrf<input type="hidden" name="expected_state" value="failed">{!! \Larena\Admin\Runtime\AdminControls::submit(__('larena-search::admin.retry')) !!}</form>
    @endif
    </div>
</section>
@endif

@if (is_array($providersView))
    @include('larena-admin::dataview.page', ['dataview' => $providersView, 'sectionId' => 'search-providers', 'owner' => 'larena/search',
        'ariaLabel' => __('larena-search::admin.heading'), 'queryUrl' => route('larena.search.admin.index', [], false),
        'matchedCount' => count($providers), 'source' => 'search_operations_projection'])
@else
    <section class="larena-panel larena-panel-padded"><p>{{ __('larena-search::admin.empty') }}</p></section>
@endif
@endsection
