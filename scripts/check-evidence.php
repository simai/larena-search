<?php

declare(strict_types=1);

$context = json_decode((string) file_get_contents('.larena/launch-context.json'), true, 512, JSON_THROW_ON_ERROR);
$evidencePath = rtrim((string) $context['evidence_path'], '/') . '/';
$proposalPath = (string) $context['graph_sync_proposal_path'];
$errors = [];
foreach ([
    'README.md',
    'implementation-summary.md',
    'independent-review.md',
    'tests.md',
    'smoke.md',
    'migration-rollback.md',
    'restart-proof.json',
    'mysql-proof.json',
    'revision-map.json',
    'file-map.json',
    'deviations.json',
    'graph-sync-proposal.json',
] as $required) {
    if (!is_file($evidencePath . $required)) {
        $errors[] = "Missing evidence file: {$evidencePath}{$required}";
    }
}
$restartProof = is_file($evidencePath . 'restart-proof.json')
    ? json_decode((string) file_get_contents($evidencePath . 'restart-proof.json'), true, 512, JSON_THROW_ON_ERROR)
    : [];
if (($restartProof['status'] ?? null) !== 'passed') {
    $errors[] = 'restart-proof status must be passed';
}
$mysqlProof = is_file($evidencePath . 'mysql-proof.json')
    ? json_decode((string) file_get_contents($evidencePath . 'mysql-proof.json'), true, 512, JSON_THROW_ON_ERROR)
    : [];
if (!in_array($mysqlProof['status'] ?? null, ['pending_root_integration', 'passed', 'passed_root_disposable_acceptance'], true)) {
    $errors[] = 'mysql-proof status is not recognized';
}
foreach ([
    'schedule_first_vs_first_realtime_upsert_barrier',
    'publish_first_vs_schedule_barrier',
    'concurrent_generation_safety_and_no_equal_revision_tombstone',
] as $requiredMySqlProof) {
    if (!in_array($requiredMySqlProof, $mysqlProof['required'] ?? [], true)) {
        $errors[] = "mysql-proof must require {$requiredMySqlProof}";
    }
}
$revisionMap = is_file($evidencePath . 'revision-map.json')
    ? json_decode((string) file_get_contents($evidencePath . 'revision-map.json'), true, 512, JSON_THROW_ON_ERROR)
    : [];
foreach ([
    'larena/access' => 'af45111b2a620d1b46331e2fe009a571f22230ad',
    'larena/audit' => 'b5f6d215fb020f7b8b071cc40b7dde4e2ed2cea1',
] as $package => $revision) {
    if (($revisionMap['dependencies'][$package] ?? null) !== $revision) {
        $errors[] = "revision-map must pin {$package} to {$revision}";
    }
}
if (!is_file($proposalPath)) {
    $errors[] = "Missing graph sync proposal: {$proposalPath}";
} else {
    $proposal = json_decode((string) file_get_contents($proposalPath), true, 512, JSON_THROW_ON_ERROR);
    if (($proposal['canonical_update_allowed'] ?? null) !== false) {
        $errors[] = 'graph-sync-proposal must keep canonical_update_allowed=false';
    }
}
if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . PHP_EOL);
    }
    exit(1);
}
echo "Evidence contract is valid for the current repository state.\n";
