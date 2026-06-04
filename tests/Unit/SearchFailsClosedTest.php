<?php

declare(strict_types=1);

use Larena\Search\Contracts\EngineProfile;
use Larena\Search\Contracts\IndexDocument;
use Larena\Search\Contracts\QueryContext;
use Larena\Search\Contracts\ReindexJob;
use Larena\Search\Contracts\ResultExposurePolicy;
use Larena\Search\Contracts\SourceProvider;
use Larena\Search\Enums\EngineProfileType;
use Larena\Search\Enums\ResultExposureDecision;

require_once __DIR__ . '/../../vendor/autoload.php';

function search_fail_closed_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$invalidProvider = new SourceProvider('', '', [], '', true);
$privateDocument = new IndexDocument(
    documentId: 'secret:1',
    sourceProvider: $invalidProvider,
    projection: ['secret' => 'raw'],
    tokens: ['raw'],
    containsPrivatePayload: true,
);
$externalEngine = new EngineProfile(
    profileId: 'external',
    type: EngineProfileType::External,
    capabilityAllowed: false,
    engineAvailable: true,
);
$invalidQuery = new QueryContext('', 'public', 'anonymous', '', false);
$policy = ResultExposurePolicy::denyByDefault();
$decision = $policy->decide($privateDocument, $invalidQuery);
$job = new ReindexJob('', $invalidProvider, $externalEngine);

search_fail_closed_assert(!$invalidProvider->isValid(), 'Provider must fail closed without owner, projection and access scope.');
search_fail_closed_assert(!$privateDocument->isIndexable(), 'Document with private payload must not be indexable.');
search_fail_closed_assert(!$externalEngine->canRun(), 'Capability-gated external engine must fail closed without capability.');
search_fail_closed_assert($externalEngine->isDegraded(), 'Unavailable/gated engine should be degraded.');
search_fail_closed_assert(!$invalidQuery->isValid(), 'Query must fail closed without query and access scope.');
search_fail_closed_assert($decision === ResultExposureDecision::Denied, 'Invalid private result should be denied.');
search_fail_closed_assert(!$job->canStart(), 'Reindex job must not start without valid source and job id.');
