<?php

declare(strict_types=1);

use Larena\Search\Contracts\EngineProfile;
use Larena\Search\Contracts\QueryContext;
use Larena\Search\Contracts\ResultExposurePolicy;
use Larena\Search\Contracts\SourceProvider;
use Larena\Search\Enums\EngineProfileType;
use Larena\Search\Runtime\InMemorySearchRuntime;

require_once __DIR__ . '/../../vendor/autoload.php';

function search_runtime_fail_closed_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$runtime = new InMemorySearchRuntime();
$invalidProvider = new SourceProvider('', '', [], '', true);
$runtime->registerSource($invalidProvider);
search_runtime_fail_closed_assert($runtime->registeredSources() === [], 'Runtime must not store invalid providers.');

$privateProvider = new SourceProvider(
    providerId: 'filesystem.files',
    ownerPackage: 'larena/filesystem',
    projectionFields: ['title', 'raw_path'],
    accessScope: 'filesystem.files.read',
    includesPrivatePayload: false,
);
$privateDocument = $runtime->createDocument(
    sourceProvider: $privateProvider,
    projection: [
        'title' => 'Private File',
        'raw_path' => '/secret/path.pdf',
    ],
    tokens: ['private', 'file'],
);
search_runtime_fail_closed_assert(!$privateDocument->isIndexable(), 'Runtime must reject projection fields that look private.');
search_runtime_fail_closed_assert(!$runtime->ingest($privateDocument), 'Runtime must not ingest private documents.');

$safeProvider = SourceProvider::declare('storage.records', 'larena/storage', ['title', 'summary'], 'storage.records.read');
$safeDocument = $runtime->createDocument(
    sourceProvider: $safeProvider,
    projection: ['title' => 'Visible Title', 'summary' => 'Protected summary'],
    tokens: ['visible', 'protected'],
);
$runtime->ingest($safeDocument);

$wrongScopeQuery = new QueryContext(
    query: 'visible',
    surface: 'admin',
    actorReference: 'user:1',
    accessScope: 'other.scope',
);
$hiddenResults = $runtime->query(
    queryContext: $wrongScopeQuery,
    policy: new ResultExposurePolicy(accessScopeMatched: false, snippetAllowed: true),
);
search_runtime_fail_closed_assert($hiddenResults === [], 'Runtime must not expose results when access scope is not matched.');

$invalidQuery = new QueryContext('', 'admin', 'user:1', 'storage.records.read');
$invalidResults = $runtime->query(
    queryContext: $invalidQuery,
    policy: new ResultExposurePolicy(accessScopeMatched: true, snippetAllowed: true),
);
search_runtime_fail_closed_assert($invalidResults === [], 'Runtime must return no results for invalid query contexts.');

$gatedEngine = new EngineProfile(
    profileId: 'semantic',
    type: EngineProfileType::Semantic,
    capabilityAllowed: false,
    engineAvailable: true,
);
$job = $runtime->planReindex($safeProvider, $gatedEngine);
search_runtime_fail_closed_assert(!$job->canStart(), 'Runtime must not plan runnable reindex jobs for gated engines.');
