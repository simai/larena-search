<?php

declare(strict_types=1);

use Larena\Access\Runtime\AccessOperationRegistry;
use Larena\Search\Commands\ReindexSearchCommand;
use Larena\Search\Contracts\ReindexSourceFactory;
use Larena\Search\Providers\SearchServiceProvider;
use Larena\Search\Runtime\StaticReindexSourceFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function search_package_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
search_package_assert(
    ($composer['extra']['laravel']['providers'] ?? []) === [SearchServiceProvider::class],
    'Composer must auto-discover the Search service provider.',
);
foreach (['illuminate/console', 'illuminate/database', 'illuminate/http', 'illuminate/routing', 'illuminate/support', 'larena/access', 'larena/admin', 'larena/audit', 'larena/queue', 'larena/scheduler', 'larena/ui'] as $dependency) {
    search_package_assert(isset($composer['require'][$dependency]), "Missing runtime dependency {$dependency}.");
}
search_package_assert(class_exists(SearchServiceProvider::class), 'Search service provider must autoload.');
search_package_assert(class_exists(ReindexSearchCommand::class), 'Search reindex command must autoload.');
search_package_assert(interface_exists(ReindexSourceFactory::class), 'Lazy reindex source factory contract must autoload.');
search_package_assert(class_exists(StaticReindexSourceFactory::class), 'Legacy static source adapter must autoload.');

$providerSource = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Providers/SearchServiceProvider.php');
search_package_assert(
    str_contains($providerSource, 'singleton(SearchSourceRegistry::class')
        && str_contains($providerSource, 'bind(DatabaseSearchIndex::class'),
    'Registry must be singleton while DatabaseSearchIndex remains connection-fresh/transient.',
);
search_package_assert(str_contains($providerSource, 'afterResolving('), 'Access operation registration must be provider-order safe.');

$registry = new AccessOperationRegistry();
$registration = new ReflectionMethod(SearchServiceProvider::class, 'registerAccessOperations');
$registration->invoke(null, $registry);
$registration->invoke(null, $registry);
search_package_assert(count($registry->all()) === 4, 'Canonical Search Access operations must register idempotently.');
foreach (['search.reindex.read', 'search.reindex.schedule', 'search.reindex.run', 'search.reindex.resume'] as $operation) {
    search_package_assert($registry->get($operation) !== null, "Missing Access operation {$operation}.");
}
search_package_assert($registry->get('search.reindex.read')?->requiredGrant === 'read', 'Reader must receive only the Search diagnostics read grant.');

$commandSource = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Commands/ReindexSearchCommand.php');
search_package_assert(str_contains($commandSource, "search:reindex"), 'Reindex CLI signature must remain stable.');
search_package_assert(str_contains($commandSource, '{--actor='), 'CLI must require an explicit actor option with no implicit authority.');
search_package_assert(!str_contains($commandSource, '->find($runRef)'), 'CLI must not reveal run existence before resume authorization.');

echo "SearchLaravelPackageContractTest passed.\n";
