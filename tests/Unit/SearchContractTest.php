<?php

declare(strict_types=1);

use Larena\Search\Contracts\EngineProfile;
use Larena\Search\Contracts\IndexDocument;
use Larena\Search\Contracts\QueryContext;
use Larena\Search\Contracts\ReindexJob;
use Larena\Search\Contracts\ResultExposurePolicy;
use Larena\Search\Contracts\ScopedSearchResult;
use Larena\Search\Contracts\SourceProvider;
use Larena\Search\Enums\ResultExposureDecision;

require_once __DIR__ . '/../../vendor/autoload.php';

function search_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$provider = SourceProvider::declare('storage.records', 'larena/storage', ['title', 'summary'], 'storage.records.read');
$document = new IndexDocument(
    documentId: 'storage:1',
    sourceProvider: $provider,
    projection: ['title' => 'Example', 'summary' => 'Safe summary'],
    tokens: ['example', 'safe'],
);
$engine = EngineProfile::databaseBaseline();
$query = new QueryContext(
    query: 'example',
    surface: 'admin',
    actorReference: 'user:1',
    accessScope: 'storage.records.read',
);
$policy = new ResultExposurePolicy(accessScopeMatched: true, snippetAllowed: false);
$decision = $policy->decide($document, $query);
$result = new ScopedSearchResult($document, $decision, 'Example', '');
$job = new ReindexJob('reindex:1', $provider, $engine);

search_assert($provider->isValid(), 'Source provider should be valid.');
search_assert($document->isIndexable(), 'Index document should be indexable.');
search_assert($engine->canRun(), 'Database baseline engine should be runnable.');
search_assert($decision === ResultExposureDecision::Redacted, 'Snippet should be redacted when snippet policy denies it.');
search_assert($result->canReturnToCaller(), 'Redacted result can still be returned with safe title.');
search_assert(!$result->exposesSnippet(), 'Redacted result must not expose snippet.');
search_assert($job->canStart(), 'Reindex job should be startable with valid provider and engine.');
search_assert($job->hasSafeDiagnostics(), 'Reindex diagnostics should be safe.');
