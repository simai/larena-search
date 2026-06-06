<?php

declare(strict_types=1);

use Larena\Search\Contracts\EngineProfile;
use Larena\Search\Contracts\QueryContext;
use Larena\Search\Contracts\ResultExposurePolicy;
use Larena\Search\Contracts\SourceProvider;
use Larena\Search\Runtime\InMemorySearchRuntime;

require_once __DIR__ . '/../../vendor/autoload.php';

function search_runtime_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$runtime = new InMemorySearchRuntime();
$provider = SourceProvider::declare(
    providerId: 'storage.records',
    ownerPackage: 'larena/storage',
    projectionFields: ['title', 'summary'],
    accessScope: 'storage.records.read',
);

$registered = $runtime->registerSource($provider);
$document = $runtime->createDocument(
    sourceProvider: $registered,
    projection: [
        'title' => 'Safe Storage Record',
        'summary' => 'Public metadata projection',
        'ignored_internal_field' => 'not indexed',
    ],
    tokens: ['safe', 'storage', 'record'],
);

$query = new QueryContext(
    query: 'storage',
    surface: 'admin',
    actorReference: 'user:1',
    accessScope: 'storage.records.read',
);
$policy = new ResultExposurePolicy(accessScopeMatched: true, snippetAllowed: true);
$job = $runtime->planReindex($provider, EngineProfile::databaseBaseline());

search_runtime_assert($registered->isValid(), 'Runtime should return valid registered provider.');
search_runtime_assert($runtime->registeredSources() === ['storage.records' => $provider], 'Runtime should store valid source providers.');
search_runtime_assert($document->isIndexable(), 'Runtime should create an indexable safe document.');
search_runtime_assert($document->projection === ['title' => 'Safe Storage Record', 'summary' => 'Public metadata projection'], 'Runtime should keep only declared projection fields.');
search_runtime_assert($runtime->ingest($document), 'Runtime should ingest safe indexable document.');

$results = $runtime->query($query, $policy);
search_runtime_assert(count($results) === 1, 'Runtime should return one access-scoped result.');
search_runtime_assert($results[0]->title === 'Safe Storage Record', 'Runtime should expose safe result title.');
search_runtime_assert($results[0]->snippet === 'Public metadata projection', 'Runtime should expose safe snippet when policy allows it.');
search_runtime_assert($results[0]->exposesSnippet(), 'Runtime should expose snippet only when policy allows it.');
search_runtime_assert($job->canStart(), 'Runtime should plan a resumable baseline reindex job descriptor.');
