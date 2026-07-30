<?php

declare(strict_types=1);

namespace Larena\Search\Http\Controllers;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Str;
use Larena\Access\Runtime\AccessOperationAuthorizer;
use Larena\Search\Exceptions\SearchReindexRejected;
use Larena\Search\Operations\SearchIndexOperationsQuery;
use Larena\Search\Queue\SearchReindexDispatcher;
use Throwable;

final readonly class SearchAdminController
{
    public function __construct(
        private SearchIndexOperationsQuery $operations,
        private SearchReindexDispatcher $dispatcher,
        private AccessOperationAuthorizer $access,
        private Factory $views,
        private Redirector $redirector,
        private Translator $translator,
    ) {
    }

    public function index(Request $request): mixed
    {
        return $this->views->make('larena-search::admin.index', [
            'providers' => $this->operations->providers(),
            'canSchedule' => $this->access->authorize($request, 'search.reindex.schedule')->isAllowed(),
            'canRun' => $this->access->authorize($request, 'search.reindex.run')->isAllowed(),
            'canResume' => $this->access->authorize($request, 'search.reindex.resume')->isAllowed(),
            'canRetry' => $this->access->authorize($request, 'search.reindex.retry')->isAllowed(),
        ]);
    }

    public function schedule(Request $request, string $providerId): RedirectResponse
    {
        $validated = $request->validate(['expected_state' => ['required', 'string', 'in:idle,completed']]);
        try {
            $this->dispatcher->schedule($providerId, $this->actor($request), (string) $validated['expected_state'], $this->correlation($request));
        } catch (Throwable $exception) {
            return $this->rejected($exception);
        }

        return $this->redirector->route('larena.search.admin.index')->with('status', $this->text('messages.scheduled'));
    }

    public function run(Request $request, string $providerId, string $runRef): RedirectResponse
    {
        $validated = $request->validate(['expected_state' => ['required', 'string', 'in:scheduled']]);
        try { $this->dispatcher->run($providerId, $runRef, $this->actor($request), (string) $validated['expected_state']); }
        catch (Throwable $exception) { return $this->rejected($exception); }
        return $this->redirector->route('larena.search.admin.index')->with('status', $this->text('messages.running'));
    }

    public function resume(Request $request, string $providerId, string $runRef): RedirectResponse
    {
        $validated = $request->validate(['expected_state' => ['required', 'string', 'in:running']]);
        try {
            $this->dispatcher->resume($providerId, $runRef, $this->actor($request), (string) $validated['expected_state']);
        } catch (Throwable $exception) {
            return $this->rejected($exception);
        }

        return $this->redirector->route('larena.search.admin.index')->with('status', $this->text('messages.resumed'));
    }

    public function retry(Request $request, string $providerId, string $runRef): RedirectResponse
    {
        $validated = $request->validate(['expected_state' => ['required', 'string', 'in:failed']]);
        try { $this->dispatcher->retry($providerId, $runRef, $this->actor($request), (string) $validated['expected_state']); }
        catch (Throwable $exception) { return $this->rejected($exception); }
        return $this->redirector->route('larena.search.admin.index')->with('status', $this->text('messages.retried'));
    }

    private function actor(Request $request): string
    {
        return (string) $request->attributes->get('larena_access_actor');
    }

    private function correlation(Request $request): string
    {
        $value = $request->headers->get('X-Correlation-ID');

        return is_string($value) && $value !== '' ? $value : 'http:' . Str::uuid();
    }

    private function rejected(Throwable $exception): RedirectResponse
    {
        $reason = $exception instanceof SearchReindexRejected ? $exception->reasonCode : 'search_reindex_failed';

        return $this->redirector->back()->withErrors(['reindex' => $this->text('messages.rejected', ['reason' => $reason])]);
    }

    /** @param array<string, scalar|null> $replace */
    private function text(string $key, array $replace = []): string
    {
        return (string) $this->translator->get('larena-search::admin.' . $key, $replace);
    }
}
