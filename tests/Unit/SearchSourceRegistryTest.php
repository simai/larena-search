<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Larena\Search\Contracts\ReindexBatch;
use Larena\Search\Contracts\ReindexSource;
use Larena\Search\Contracts\ReindexSourceFactory;
use Larena\Search\Exceptions\SearchReindexRejected;
use Larena\Search\Runtime\SearchSourceRegistry;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function search_source_registry_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class SearchSourceRegistryTestSource implements ReindexSource
{
    public function __construct(
        private readonly string $id,
        public readonly object $connectionGraph,
    ) {
    }

    public function providerId(): string
    {
        return $this->id;
    }

    public function readBatch(?string $afterCursor, int $limit): ReindexBatch
    {
        return new ReindexBatch([], $afterCursor, false);
    }
}

final class SearchSourceRegistryTestFactory implements ReindexSourceFactory
{
    public int $createCount = 0;

    /** @param Closure(): ReindexSource $creator */
    public function __construct(
        public string $id,
        private readonly Closure $creator,
    ) {
    }

    public function providerId(): string
    {
        return $this->id;
    }

    public function create(): ReindexSource
    {
        $this->createCount++;

        return ($this->creator)();
    }
}

$container = new Container();
$scopeGeneration = 0;
$container->scoped(
    SearchSourceRegistryTestSource::class,
    static function () use (&$scopeGeneration): SearchSourceRegistryTestSource {
        $scopeGeneration++;

        return new SearchSourceRegistryTestSource(
            'content.items',
            (object) ['scope_generation' => $scopeGeneration],
        );
    },
);

$registry = new SearchSourceRegistry();
$contentFactory = new SearchSourceRegistryTestFactory(
    'content.items',
    static fn (): SearchSourceRegistryTestSource => $container->make(SearchSourceRegistryTestSource::class),
);
search_source_registry_assert($registry->registerFactory($contentFactory), 'Factory registration must succeed once.');
search_source_registry_assert($contentFactory->createCount === 0, 'Factory registration must not construct a source.');
search_source_registry_assert($registry->has('content.items'), 'has() must see a registered factory.');
search_source_registry_assert(!$registry->has('content.unknown'), 'has() must reject an unknown provider.');
search_source_registry_assert($contentFactory->createCount === 0, 'has() must not construct a source.');
search_source_registry_assert($registry->providerIds() === ['content.items'], 'Provider IDs must be deterministic.');
search_source_registry_assert($contentFactory->createCount === 0, 'Provider ID enumeration must not construct a source.');

$duplicateFactory = new SearchSourceRegistryTestFactory(
    'content.items',
    static fn (): SearchSourceRegistryTestSource => new SearchSourceRegistryTestSource('content.items', new stdClass()),
);
search_source_registry_assert(!$registry->registerFactory($duplicateFactory), 'Duplicate factory registration must be idempotent.');
search_source_registry_assert($duplicateFactory->createCount === 0, 'Duplicate registration must not construct a source.');

$first = $registry->get('content.items');
$second = $registry->get('content.items');
if (!$first instanceof SearchSourceRegistryTestSource || !$second instanceof SearchSourceRegistryTestSource) {
    throw new RuntimeException('Registered factory must resolve its source.');
}
search_source_registry_assert($first === $second, 'The same container scope must return the same source.');
search_source_registry_assert($first->connectionGraph === $second->connectionGraph, 'The same scope must retain one connection graph.');
search_source_registry_assert($contentFactory->createCount === 2, 'Registry must delegate every get() to the factory without caching a source.');

$container->forgetScopedInstances();
$third = $registry->get('content.items');
if (!$third instanceof SearchSourceRegistryTestSource) {
    throw new RuntimeException('A cleared scope must resolve a source.');
}
search_source_registry_assert($third !== $first, 'A cleared container scope must return a new source.');
search_source_registry_assert($third->connectionGraph !== $first->connectionGraph, 'A cleared scope must not retain the old connection graph.');
search_source_registry_assert($contentFactory->createCount === 3, 'Resolution after scope clearing must call the factory again.');

$staticSource = new SearchSourceRegistryTestSource('docara.pages', new stdClass());
search_source_registry_assert($registry->register($staticSource), 'Legacy static source registration must remain supported.');
search_source_registry_assert($registry->get('docara.pages') === $staticSource, 'Legacy registration must resolve the original static source.');
search_source_registry_assert(
    $registry->providerIds() === ['content.items', 'docara.pages'],
    'Provider ID enumeration must remain sorted.',
);
search_source_registry_assert(
    array_map(static fn (ReindexSource $source): string => $source->providerId(), $registry->all()) === ['content.items', 'docara.pages'],
    'Legacy all() enumeration must remain compatible and sorted.',
);

$invalidFactory = new SearchSourceRegistryTestFactory(
    'x',
    static fn (): SearchSourceRegistryTestSource => new SearchSourceRegistryTestSource('x', new stdClass()),
);
$invalidRejected = false;
try {
    $registry->registerFactory($invalidFactory);
} catch (InvalidArgumentException $exception) {
    $invalidRejected = $exception->getMessage() === 'search_provider_id_invalid';
}
search_source_registry_assert($invalidRejected, 'Invalid factory provider IDs must fail closed at registration.');
search_source_registry_assert($invalidFactory->createCount === 0, 'Invalid factory registration must not construct a source.');

$mismatchRegistry = new SearchSourceRegistry();
$mismatchFactory = new SearchSourceRegistryTestFactory(
    'content.expected',
    static fn (): SearchSourceRegistryTestSource => new SearchSourceRegistryTestSource('content.actual', new stdClass()),
);
$mismatchRegistry->registerFactory($mismatchFactory);
$mismatchRejected = false;
try {
    $mismatchRegistry->get('content.expected');
} catch (SearchReindexRejected $exception) {
    $mismatchRejected = $exception->reasonCode === 'search_reindex_source_provider_mismatch';
}
search_source_registry_assert($mismatchRejected, 'Factory/source provider mismatch must fail closed.');

$forgedReasonRegistry = new SearchSourceRegistry();
$forgedReasonFactory = new SearchSourceRegistryTestFactory(
    'content.forged',
    static function (): ReindexSource {
        throw new SearchReindexRejected('raw_sensitive_factory_detail');
    },
);
$forgedReasonRegistry->registerFactory($forgedReasonFactory);
$forgedReasonSanitized = false;
try {
    $forgedReasonRegistry->get('content.forged');
} catch (SearchReindexRejected $exception) {
    $forgedReasonSanitized = $exception->reasonCode === 'search_reindex_source_failed'
        && !str_contains($exception->getMessage(), 'raw_sensitive_factory_detail');
}
search_source_registry_assert($forgedReasonSanitized, 'A factory must not forge a trusted Search rejection reason.');

$driftRegistry = new SearchSourceRegistry();
$driftFactory = new SearchSourceRegistryTestFactory(
    'content.stable',
    static fn (): SearchSourceRegistryTestSource => new SearchSourceRegistryTestSource('content.stable', new stdClass()),
);
$driftRegistry->registerFactory($driftFactory);
$driftFactory->id = 'content.drifted';
$driftRejected = false;
try {
    $driftRegistry->get('content.stable');
} catch (SearchReindexRejected $exception) {
    $driftRejected = $exception->reasonCode === 'search_reindex_source_provider_mismatch';
}
search_source_registry_assert($driftRejected, 'A factory whose declared provider drifts after registration must fail closed.');
search_source_registry_assert($driftFactory->createCount === 0, 'Factory ID drift must fail before source construction.');

echo "SearchSourceRegistryTest passed.\n";
