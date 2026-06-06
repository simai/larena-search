<?php

declare(strict_types=1);

$requiredFiles = [
    '.gitignore',
    '.env.example',
    '.github/workflows/larena-package-ci.yml',
    '.githooks/pre-commit',
    '.githooks/pre-push',
    'composer.json',
    'module.yaml',
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
} else {
    foreach (['src', 'config', 'database', 'routes', 'resources', 'tests', 'lang'] as $runtimePath) {
        if (is_dir($runtimePath)) {
            $errors[] = "{$runtimePath}/ is not allowed in this clean pre-codegen baseline commit.";
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
