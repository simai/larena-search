<?php

declare(strict_types=1);

$requiredFiles = [
    '.gitignore',
    '.env.example',
    '.github/workflows/larena-package-ci.yml',
    '.githooks/pre-commit',
    '.githooks/pre-push',
    'composer.json',
    'composer.lock',
    'module.yaml',
    'access.yaml',
    'audit.yaml',
    'README.md',
    'CHANGELOG.md',
    'phpstan.neon.dist',
    '.larena/spec-ref.json',
    '.larena/launch-context.json',
    'tools/larena-scope-check.php',
];
$contractFiles = [
    'src/Contracts/EngineProfile.php',
    'src/Contracts/IndexDocument.php',
    'src/Contracts/QueryContext.php',
    'src/Contracts/ReindexJob.php',
    'src/Contracts/ResultExposurePolicy.php',
    'src/Contracts/ScopedSearchResult.php',
    'src/Contracts/SearchRuntime.php',
    'src/Contracts/SourceProvider.php',
    'src/Enums/EngineProfileType.php',
    'src/Enums/ReindexJobStatus.php',
    'src/Enums/ResultExposureDecision.php',
    'tests/Unit/SearchContractTest.php',
    'tests/Unit/SearchFailsClosedTest.php',
];
$runtimeFiles = [
    'src/Runtime/InMemorySearchRuntime.php',
    'tests/Unit/InMemorySearchRuntimeTest.php',
    'tests/Unit/InMemorySearchRuntimeFailsClosedTest.php',
];
$persistentFiles = [
    'src/Contracts/ReindexBatch.php',
    'src/Contracts/ReindexRun.php',
    'src/Contracts/ReindexSource.php',
    'src/Contracts/ReindexSourceFactory.php',
    'src/Contracts/SearchHit.php',
    'src/Contracts/SearchProjection.php',
    'src/Contracts/SearchQuery.php',
    'src/Contracts/SearchWriteResult.php',
    'src/Persistence/DatabaseSearchIndex.php',
    'src/Persistence/LockedProviderState.php',
    'src/Persistence/ProviderGenerationFence.php',
    'src/Runtime/SearchSourceRegistry.php',
    'src/Runtime/StaticReindexSourceFactory.php',
    'src/Reindex/SearchReindexService.php',
    'src/Providers/SearchServiceProvider.php',
    'src/Commands/ReindexSearchCommand.php',
    'src/Audit/SearchReindexAuditEventDescriptor.php',
    'database/migrations/2026_07_13_000001_create_larena_search_documents.php',
    'database/migrations/2026_07_13_000002_create_larena_search_source_states.php',
    'database/migrations/2026_07_13_000003_create_larena_search_reindex_runs.php',
    'database/migrations/2026_07_13_000004_create_larena_search_provider_states.php',
    'tests/Unit/DatabaseSearchIndexTest.php',
    'tests/Unit/SearchReindexServiceTest.php',
    'tests/Unit/SearchSourceRegistryTest.php',
    'tests/Unit/SearchLaravelPackageContractTest.php',
];
$errors = [];
foreach ($requiredFiles as $file) {
    if (!is_file($file)) {
        $errors[] = "Missing required enforcement file: {$file}";
    }
}
$specRef = is_file('.larena/spec-ref.json')
    ? json_decode((string) file_get_contents('.larena/spec-ref.json'), true, 512, JSON_THROW_ON_ERROR)
    : [];
$launchContext = is_file('.larena/launch-context.json')
    ? json_decode((string) file_get_contents('.larena/launch-context.json'), true, 512, JSON_THROW_ON_ERROR)
    : [];
if (($specRef['canonical_update_allowed'] ?? null) !== false) {
    $errors[] = '.larena/spec-ref.json must keep canonical_update_allowed=false';
}
if (($launchContext['package'] ?? null) !== 'larena/search') {
    $errors[] = '.larena/launch-context.json package must be larena/search';
}
$codingStarted = ($launchContext['coding_started'] ?? null) === true;
$status = (string) ($launchContext['status'] ?? '');
$allowedStatuses = [
    'repository_prepared_pending_review',
    'coding_started',
    'contract_skeleton_review_passed',
];
if (!in_array($status, $allowedStatuses, true)) {
    $errors[] = 'launch-context status must be a known Larena package preparation/coding state.';
}
if (!$codingStarted && $status !== 'repository_prepared_pending_review') {
    $errors[] = 'coding_started=false is only valid for repository_prepared_pending_review.';
}
$launchRecordRef = (string) ($launchContext['launch_record_ref'] ?? '');
$knownCodingLaunchRecords = [
    'search-batch-1-contract-skeletons-current.json',
    'search-batch-2-in-memory-runtime-baseline.json',
    'published-page-search.json',
    'canonical-mysql-reproducibility.json',
    'content-model-administration-api-v1-search-lifetime.json',
    'larena-public-search-index-operations-v1.json',
];
if ($codingStarted) {
    $knownLaunchRecord = false;
    foreach ($knownCodingLaunchRecords as $knownCodingLaunchRecord) {
        if (str_contains($launchRecordRef, $knownCodingLaunchRecord)) {
            $knownLaunchRecord = true;
            break;
        }
    }
    if (!$knownLaunchRecord) {
        $errors[] = 'coding_started requires a known Search coding launch record.';
    }
}
if (!str_starts_with((string) ($launchContext['evidence_path'] ?? ''), 'docs/project-management/evidence/')) {
    $errors[] = 'launch-context evidence_path must start with docs/project-management/evidence/';
}
if (!str_starts_with((string) ($launchContext['graph_sync_proposal_path'] ?? ''), (string) ($launchContext['evidence_path'] ?? '__missing__'))) {
    $errors[] = 'graph_sync_proposal_path must be inside evidence_path';
}
if ($codingStarted) {
    foreach ($contractFiles as $file) {
        if (!is_file($file)) {
            $errors[] = "Missing required search contract skeleton file: {$file}";
        }
    }
    if (str_contains($launchRecordRef, 'search-batch-2-in-memory-runtime-baseline.json')) {
        foreach ($runtimeFiles as $file) {
            if (!is_file($file)) {
                $errors[] = "Missing required search in-memory runtime baseline file: {$file}";
            }
        }
    }
    if (str_contains($launchRecordRef, 'published-page-search.json')
        || str_contains($launchRecordRef, 'canonical-mysql-reproducibility.json')
        || str_contains($launchRecordRef, 'content-model-administration-api-v1-search-lifetime.json')) {
        foreach ($persistentFiles as $file) {
            if (!is_file($file)) {
                $errors[] = "Missing published-page Search runtime file: {$file}";
            }
        }
    }
} else {
    foreach (['src', 'config', 'database', 'routes', 'resources', 'tests', 'lang'] as $runtimePath) {
        if (is_dir($runtimePath)) {
            $errors[] = "{$runtimePath}/ is not allowed in this clean pre-codegen baseline commit.";
        }
    }
}

$composer = json_decode((string) file_get_contents('composer.json'), true, 512, JSON_THROW_ON_ERROR);
if (($composer['extra']['laravel']['providers'] ?? []) !== ['Larena\\Search\\Providers\\SearchServiceProvider']) {
    $errors[] = 'composer.json must auto-discover SearchServiceProvider.';
}
$lock = json_decode((string) file_get_contents('composer.lock'), true, 512, JSON_THROW_ON_ERROR);
$expectedRevisions = [
    'larena/access' => '28cae5ad9bb5b401dc95a4d79becaaeb8d8ea5ad',
    'larena/admin' => 'ee5706816d08b1e344e8b28499ec0ebd49e37b9f',
    'larena/audit' => 'cc6ba3ccf279eefdef3fa3973249629a3a100feb',
    'larena/queue' => 'e32f74dac5e40e19243a8d7bf416f5a3a5f59f53',
    'larena/scheduler' => 'b22bd2ac7d67a74d903c00a6551bdb9b14d6539f',
    'larena/ui' => 'bd181eda92f2de22130904884e18680587ee10db',
    'larena/dataview' => 'b84e964b4ed78e1ca08a46c88e7651b02744ee47',
];
$lockedRevisions = [];
foreach ($lock['packages'] ?? [] as $package) {
    $name = (string) ($package['name'] ?? '');
    if (isset($expectedRevisions[$name])) {
        $lockedRevisions[$name] = (string) ($package['dist']['reference'] ?? '');
    }
}
foreach ($expectedRevisions as $package => $revision) {
    if (($lockedRevisions[$package] ?? null) !== $revision) {
        $errors[] = "composer.lock must pin {$package} to {$revision}.";
    }
}
if (str_contains($launchRecordRef, 'larena-public-search-index-operations-v1.json')) {
    foreach (['routes/public.php', 'routes/admin.php', 'resources/views/public/search.blade.php', 'resources/views/admin/index.blade.php', 'src/Http/Controllers/PublicSearchController.php', 'src/Http/Controllers/SearchAdminController.php', 'src/Queue/SearchReindexJobHandler.php', 'src/Scheduler/SearchScheduledReindexHandler.php'] as $file) {
        if (!is_file($file)) {
            $errors[] = "Missing public Search/index operations runtime file: {$file}";
        }
    }
    $publicController = is_file('src/Http/Controllers/PublicSearchController.php') ? (string) file_get_contents('src/Http/Controllers/PublicSearchController.php') : '';
    if (!str_contains($publicController, "accessScopes: ['public']")) {
        $errors[] = 'Public Search controller must query only the public access scope.';
    }
    foreach (['resources/views/public/search.blade.php', 'resources/views/admin/index.blade.php'] as $view) {
        $source = is_file($view) ? (string) file_get_contents($view) : '';
        if (preg_match('/<style\\b|<script\\b(?![^>]*\\bsrc=)|@php\\b/i', $source) === 1) {
            $errors[] = "Search view must not contain inline style, script or PHP: {$view}";
        }
    }
}
if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . PHP_EOL);
    }
    exit(1);
}
echo $codingStarted
    ? "Larena Search coding launch context is valid.\n"
    : "Larena Search clean pre-codegen baseline is valid.\n";
