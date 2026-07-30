@extends('larena-admin::layouts.app')

@section('title', __('larena-search::admin.title'))
@section('heading', __('larena-search::admin.heading'))
@section('eyebrow', __('larena-search::admin.eyebrow'))
@section('description', __('larena-search::admin.description'))

@section('content')
<section class="larena-admin-card"><div class="larena-admin-table-wrap"><table class="larena-admin-table"><thead><tr><th>{{ __('larena-search::admin.provider') }}</th><th>{{ __('larena-search::admin.state') }}</th><th>{{ __('larena-search::admin.progress') }}</th><th>{{ __('larena-search::admin.generation') }}</th><th>{{ __('larena-search::admin.error') }}</th><th>{{ __('larena-search::admin.actions') }}</th></tr></thead><tbody>
@forelse($providers as $provider)<tr><td><code>{{ $provider['provider_id'] }}</code></td><td>{{ $provider['state'] }}</td><td>{{ $provider['processed_count'] }} / {{ $provider['batch_count'] }}</td><td><code>{{ $provider['generation_ref'] ? substr($provider['generation_ref'], 0, 20) : '—' }}</code></td><td>{{ $provider['error_code'] ?? '—' }}</td><td>
@if($canSchedule && in_array($provider['state'], ['idle', 'completed'], true))<form method="post" action="{{ route('larena.search.admin.schedule', $provider['provider_id']) }}">@csrf<input type="hidden" name="expected_state" value="{{ $provider['state'] }}"><button class="sf-button" type="submit">{{ __('larena-search::admin.schedule') }}</button></form>@endif
@if($canRun && $provider['state'] === 'scheduled' && $provider['run_ref'])<form method="post" action="{{ route('larena.search.admin.run', [$provider['provider_id'], $provider['run_ref']]) }}">@csrf<input type="hidden" name="expected_state" value="scheduled"><button class="sf-button" type="submit">{{ __('larena-search::admin.run') }}</button></form>@endif
@if($canResume && $provider['state'] === 'running' && $provider['run_ref'])<form method="post" action="{{ route('larena.search.admin.resume', [$provider['provider_id'], $provider['run_ref']]) }}">@csrf<input type="hidden" name="expected_state" value="running"><button class="sf-button" type="submit">{{ __('larena-search::admin.resume') }}</button></form>@endif
@if($canRetry && $provider['state'] === 'failed' && $provider['run_ref'])<form method="post" action="{{ route('larena.search.admin.retry', [$provider['provider_id'], $provider['run_ref']]) }}">@csrf<input type="hidden" name="expected_state" value="failed"><button class="sf-button" type="submit">{{ __('larena-search::admin.retry') }}</button></form>@endif
</td></tr>@empty<tr><td colspan="6">{{ __('larena-search::admin.empty') }}</td></tr>@endforelse
</tbody></table></div></section>
@endsection
