<?php

declare(strict_types=1);

namespace Larena\Search\Http\Controllers;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Str;
use Larena\Access\Runtime\AccessOperationAuthorizer;
use Larena\Admin\Assets\AdminProductAssetManifest;
use Larena\Admin\Runtime\AdminDataviewPage;
use Larena\Admin\Runtime\AdminDataviewPagePresenter;
use Larena\Admin\Contracts\PropertyViewHostPortGateway;
use Larena\Admin\Runtime\AdminRecordPanel;
use Larena\Admin\Runtime\AdminRecordPanelPresenter;
use Larena\Search\Admin\SearchProviderPropertyViewPort;
use Larena\Search\Admin\SearchProviderRows;
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
        private AdminDataviewPagePresenter $dataviews,
        private AdminRecordPanelPresenter $panels,
        private SearchProviderPropertyViewPort $records,
        private \Illuminate\Contracts\Routing\UrlGenerator $urls,
    ) {
    }

    public function index(Request $request): mixed
    {
        $providers = $this->operations->providers();
        $panel = $this->panel($request);
        // The list asks for the record panel alone when a provider opens over it (X-Larena-Fragment: record-panel).
        if ($request->headers->get('X-Larena-Fragment') === 'record-panel') {
            return new JsonResponse(['html' => $panel ?? '', 'asset_tags' => [], 'mode' => 'view', 'notice_html' => '', 'notice' => null],
                $panel === null ? 404 : 200, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        }

        return $this->views->make('larena-search::admin.index', [
            'providers' => $providers,
            'providersView' => $this->providersView($providers),
            'panel' => $panel ?? '',
        ]);
    }

    /**
     * The providers as a shared admin data view over the page's host port (admin page standard): a provider's name
     * opens it in the record panel, the reindex steps its state allows are its row's actions.
     *
     * @param list<array<string, mixed>> $providers
     * @return array<string, mixed>|null
     */
    private function providersView(array $providers): ?array
    {
        if ($providers === []) {
            return null;
        }
        try {
            return $this->dataviews->present(new AdminDataviewPage(
                instanceId: 'search-providers',
                title: $this->text('heading'),
                columns: $this->columns(),
                total: count($providers),
                pageId: 'page.admin.search-index',
                portUrl: $this->urls->route('larena.search.admin.port', [], false),
                portKind: 'host',
                portCapabilities: ['row-actions'],
                hostPanels: ['view' => '?record_id=__ID__&record_mode=view'],
                countLabel: (string) $this->translator->get('larena-admin::admin.minimal_cms.record_count'),
                tableViewLabel: (string) $this->translator->get('larena-admin::admin.minimal_cms.table_view'),
            ), AdminProductAssetManifest::dataviewActivation());
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<array<string, mixed>> */
    private function columns(): array
    {
        return array_map(fn (string $key): array => ['key' => $key, 'label' => $this->text($key)] + ($key === 'provider' ? ['role' => 'title'] : []),
            ['provider', 'state', 'progress', 'generation', 'error']);
    }

    /**
     * The list's host port (simai.composite-host-port): the providers, and a reindex step from a row's menu. A step
     * is taken at the state the row showed; the dispatcher refuses it when the state moved meanwhile.
     */
    public function port(Request $request): JsonResponse
    {
        $intent = $request->json('intent');
        $payload = $request->json('payload');
        $payload = is_array($payload) ? $payload : [];
        $ids = array_values(array_filter(is_array($payload['record_ids'] ?? null) ? $payload['record_ids'] : [], 'is_string'));

        return match ($intent) {
            'query.change' => $this->query($request, $payload),
            'record.open' => self::answer(count($ids) === 1 && $this->operations->provider($ids[0]) !== null
                ? ['answer' => 'applied'] : ['answer' => 'refused', 'reason' => 'record_unknown']),
            'record.mutate' => $this->step($request, $payload, $ids),
            default => self::answer(['answer' => 'unavailable', 'reason' => 'capability-not-provided']),
        };
    }

    /** @param array<string, mixed> $payload */
    private function query(Request $request, array $payload): JsonResponse
    {
        $sequence = $payload['sequence'] ?? null;
        if (!is_int($sequence) || $sequence < 1) {
            return self::answer(['answer' => 'refused', 'reason' => 'sequence_invalid']);
        }
        $query = is_array($payload['query'] ?? null) ? $payload['query'] : [];
        $search = is_string($query['search'] ?? null) ? mb_strtolower(trim($query['search'])) : '';
        $allowed = $this->allowedSteps($request);
        $records = [];
        foreach ($this->operations->providers() as $provider) {
            if ($search !== '' && !str_contains(mb_strtolower((string) $provider['provider_id']), $search)) {
                continue;
            }
            $records[] = $this->row($provider, $allowed);
        }

        return self::answer(['answer' => 'applied', 'sequence' => $sequence, 'data' => [
            'columns' => $this->columns(),
            'records' => $records,
            // Rights travel with the data: only the steps this person may take are offered.
            'row_actions' => array_map(fn (string $step): array => ['id' => $step, 'label' => $this->text($step), 'icon' => 'refresh'],
                array_values(array_filter(array_keys(SearchProviderRows::STEPS), static fn (string $step): bool => $allowed[$step]))),
            'total' => count($records),
            'echo' => ['search' => $search, 'filters' => [], 'sort' => [], 'page' => 1, 'per_page' => max(20, count($records))],
        ]]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $ids
     */
    private function step(Request $request, array $payload, array $ids): JsonResponse
    {
        $step = $payload['action_id'] ?? null;
        $provider = count($ids) === 1 ? $this->operations->provider($ids[0]) : null;
        if (!is_string($step) || !isset(SearchProviderRows::STEPS[$step]) || $provider === null) {
            return self::answer(['answer' => 'refused', 'reason' => 'action-unknown']);
        }
        $allowed = $this->allowedSteps($request);
        if (!$allowed[$step]) {
            return self::answer(['answer' => 'refused', 'reason' => 'access_denied']);
        }
        if (!in_array($step, SearchProviderRows::steps($provider), true)) {
            return self::answer(['answer' => 'conflict', 'reason' => 'search_provider_state_moved']);
        }
        $id = (string) $provider['provider_id'];
        $state = (string) $provider['state'];
        $run = (string) ($provider['run_ref'] ?? '');
        try {
            match ($step) {
                'schedule' => $this->dispatcher->schedule($id, $this->actor($request), $state, $this->correlation($request)),
                'run' => $this->dispatcher->run($id, $run, $this->actor($request), $state),
                'resume' => $this->dispatcher->resume($id, $run, $this->actor($request), $state),
                'retry' => $this->dispatcher->retry($id, $run, $this->actor($request), $state),
            };
        } catch (Throwable $exception) {
            return self::answer(['answer' => 'refused', 'reason' => $exception instanceof SearchReindexRejected ? $exception->reasonCode : 'search_reindex_failed']);
        }
        $now = $this->operations->provider($id);

        return self::answer(['answer' => 'applied', 'data' => ['records' => $now === null ? [] : [$this->row($now, $allowed)]]]);
    }

    /**
     * @param array<string, scalar|null> $provider
     * @param array<string, bool> $allowed
     * @return array<string, mixed>
     */
    private function row(array $provider, array $allowed): array
    {
        $text = fn (string $key): string => $this->text($key);

        return ['id' => (string) $provider['provider_id']] + SearchProviderRows::cells($provider, $text)
            + ['actions' => ['view', ...array_values(array_filter(SearchProviderRows::steps($provider), static fn (string $step): bool => $allowed[$step]))]];
    }

    /** @return array<string, bool> */
    private function allowedSteps(Request $request): array
    {
        $allowed = [];
        foreach (array_keys(SearchProviderRows::STEPS) as $step) {
            $allowed[$step] = $this->access->authorize($request, 'search.reindex.'.$step)->isAllowed();
        }

        return $allowed;
    }

    /** The provider's record in the record panel, over its property-view port. */
    private function panel(Request $request): ?string
    {
        $id = $request->query('record_id');
        $provider = $request->query('record_mode') === 'view' && is_string($id) ? $this->operations->provider($id) : null;
        if ($provider === null) {
            return null;
        }
        $base = $this->urls->route('larena.search.admin.index', [], false);
        try {
            return $this->panels->present(new AdminRecordPanel(
                mode: 'view',
                title: (string) $provider['provider_id'],
                fields: [],
                values: [],
                closeUrl: $base,
                writeUrl: $base,
                writeFields: [],
                recordId: (string) $provider['provider_id'],
                revision: 1,
                heading: (string) $provider['provider_id'],
                context: ['locale' => (string) $this->translator->getLocale(), 'structure_id' => SearchProviderPropertyViewPort::COLLECTION,
                    'scope_ref' => 'scope:search', 'property_port' => $this->urls->route('larena.search.admin.record-port', [], false)],
            ), AdminProductAssetManifest::dataviewActivation())['html'];
        } catch (Throwable) {
            return null;
        }
    }

    /** The provider's record port (simai.property-view-port), read only. */
    public function recordPort(Request $request): JsonResponse
    {
        $intent = $request->input('intent');
        $payload = $request->input('payload');
        if (!$request->isJson() || strlen($request->getContent()) > 262_144
            || !is_string($intent) || !in_array($intent, PropertyViewHostPortGateway::INTENTS, true)
            || !is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            return new JsonResponse(['answer' => 'refused', 'reason' => 'port_request_invalid'], 422);
        }
        try {
            $answer = $this->records->raise($this->actor($request), 'scope:search', $intent, $payload);
        } catch (Throwable) {
            $answer = ['answer' => 'unavailable', 'reason' => 'port_failed'];
        }

        return self::answer($answer);
    }

    /** @param array<string, mixed> $answer */
    private static function answer(array $answer): JsonResponse
    {
        $status = match ($answer['answer'] ?? null) {'applied' => 200, 'conflict' => 409, 'refused' => 422, default => 503};

        return new JsonResponse($answer, $status, ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function schedule(Request $request, string $providerId): RedirectResponse
    {
        $validated = $request->validate(['expected_state' => ['required', 'string', 'in:idle,completed']]);
        try {
            $this->dispatcher->schedule($providerId, $this->actor($request), (string) $validated['expected_state'], $this->correlation($request));
        } catch (Throwable $exception) {
            return $this->rejected($exception);
        }

        return $this->redirector->$this->urls->route('larena.search.admin.index')->with('status', $this->text('messages.scheduled'));
    }

    public function run(Request $request, string $providerId, string $runRef): RedirectResponse
    {
        $validated = $request->validate(['expected_state' => ['required', 'string', 'in:scheduled']]);
        try { $this->dispatcher->run($providerId, $runRef, $this->actor($request), (string) $validated['expected_state']); }
        catch (Throwable $exception) { return $this->rejected($exception); }
        return $this->redirector->$this->urls->route('larena.search.admin.index')->with('status', $this->text('messages.running'));
    }

    public function resume(Request $request, string $providerId, string $runRef): RedirectResponse
    {
        $validated = $request->validate(['expected_state' => ['required', 'string', 'in:running']]);
        try {
            $this->dispatcher->resume($providerId, $runRef, $this->actor($request), (string) $validated['expected_state']);
        } catch (Throwable $exception) {
            return $this->rejected($exception);
        }

        return $this->redirector->$this->urls->route('larena.search.admin.index')->with('status', $this->text('messages.resumed'));
    }

    public function retry(Request $request, string $providerId, string $runRef): RedirectResponse
    {
        $validated = $request->validate(['expected_state' => ['required', 'string', 'in:failed']]);
        try { $this->dispatcher->retry($providerId, $runRef, $this->actor($request), (string) $validated['expected_state']); }
        catch (Throwable $exception) { return $this->rejected($exception); }
        return $this->redirector->$this->urls->route('larena.search.admin.index')->with('status', $this->text('messages.retried'));
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
