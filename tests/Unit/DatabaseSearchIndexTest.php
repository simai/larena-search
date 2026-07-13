<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Larena\Search\Contracts\SearchProjection;
use Larena\Search\Contracts\SearchQuery;
use Larena\Search\Exceptions\SearchPersistenceFailed;
use Larena\Search\Exceptions\SearchRevisionConflict;
use Larena\Search\Persistence\DatabaseSearchIndex;
use Larena\Search\Tests\Support\SearchTestDatabase;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function search_index_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$database = SearchTestDatabase::create();

try {
    $index = new DatabaseSearchIndex($database->connection());
    $first = new SearchProjection(
        providerId: 'docara.pages',
        sourceRef: 'page:welcome',
        sourceRevision: 1,
        title: 'Welcome Page',
        locator: '/docs/welcome',
        snippet: 'Public introduction',
        locale: 'en',
        accessScope: 'public',
        searchableText: 'Literal 100% underscore_value and durable content',
        payload: ['slug' => 'welcome', 'status' => 'published'],
    );
    search_index_assert($index->upsert($first)->changed, 'First projection must be indexed.');
    search_index_assert(count($index->query(new SearchQuery('WELCOME'))) === 1, 'Query must be case-insensitive.');
    search_index_assert(count($index->query(new SearchQuery('100%'))) === 1, 'Percent must be matched literally.');
    search_index_assert(count($index->query(new SearchQuery('underscore_'))) === 1, 'Underscore must be matched literally.');
    search_index_assert(count($index->query(new SearchQuery('welcome', providerId: 'other.pages'))) === 0, 'Provider filter must fail closed.');
    search_index_assert(count($index->query(new SearchQuery('welcome', locale: 'ru'))) === 0, 'Locale filter must fail closed.');
    search_index_assert(count($index->query(new SearchQuery('welcome', accessScopes: ['staff']))) === 0, 'Access scope filter must fail closed.');

    $second = new SearchProjection(
        providerId: 'docara.pages', sourceRef: 'page:welcome', sourceRevision: 2,
        title: 'Welcome Updated', locator: '/docs/welcome', snippet: 'Updated', locale: 'en',
        accessScope: 'public', searchableText: 'Second immutable public revision', payload: ['slug' => 'welcome'],
    );
    search_index_assert($index->upsert($second)->status === 'indexed', 'Higher revision must replace the current document.');
    search_index_assert($index->upsert($first)->status === 'stale_revision', 'Lower revision must be ignored.');

    $conflicting = new SearchProjection(
        providerId: 'docara.pages', sourceRef: 'page:welcome', sourceRevision: 2,
        title: 'Conflicting title', locator: '/docs/welcome', searchableText: 'different hash',
    );
    $conflictRejected = false;
    try {
        $index->upsert($conflicting);
    } catch (SearchRevisionConflict $exception) {
        $conflictRejected = $exception->getMessage() === 'search_revision_conflict';
    }
    search_index_assert($conflictRejected, 'Equal revision with a different projection must fail closed.');

    search_index_assert($index->remove('docara.pages', 'page:welcome', 2)->status === 'removed', 'Equal-revision tombstone must win.');
    search_index_assert($index->upsert($second)->status === 'tombstone_wins', 'Equal-revision upsert must not resurrect a tombstone.');
    $third = new SearchProjection(
        providerId: 'docara.pages', sourceRef: 'page:welcome', sourceRevision: 3,
        title: 'Welcome Republished', locator: '/docs/new-welcome', searchableText: 'Third public revision',
    );
    $index->upsert($third);
    $database->connection()->table('larena_search_documents')->where('source_ref', 'page:welcome')->delete();
    search_index_assert($index->upsert($third)->status === 'repaired', 'Same-revision retry must self-heal a missing document row.');

    $reconnected = $database->reconnect();
    $restartedIndex = new DatabaseSearchIndex($reconnected);
    $hits = $restartedIndex->query(new SearchQuery('republished'));
    search_index_assert(count($hits) === 1 && $hits[0]->locator === '/docs/new-welcome', 'Index state must survive a fresh connection.');
    $restartedFence = $reconnected->table('larena_search_provider_states')->where('provider_id', 'docara.pages')->first();
    search_index_assert(
        $restartedFence !== null
        && $restartedFence->active_run_ref === null
        && $restartedFence->active_generation_ref === null,
        'The permanent provider fence row must survive a fresh connection.',
    );

    $database->rollback();
    search_index_assert(!Schema::hasTable('larena_search_documents'), 'Rollback must remove Search documents.');
    search_index_assert(!Schema::hasTable('larena_search_source_states'), 'Rollback must remove source-state tombstones.');
    search_index_assert(!Schema::hasTable('larena_search_reindex_runs'), 'Rollback must remove reindex runs.');
    search_index_assert(!Schema::hasTable('larena_search_provider_states'), 'Rollback must remove durable provider fences.');
    $persistenceFailed = false;
    try {
        $restartedIndex->query(new SearchQuery('republished'));
    } catch (SearchPersistenceFailed $exception) {
        $persistenceFailed = $exception->getMessage() === 'search_persistence_failed'
            && !str_contains($exception->getMessage(), 'SQL');
    }
    search_index_assert($persistenceFailed, 'Database errors must cross the public boundary only as a sanitized Search exception.');

    $database->reapply();
    search_index_assert(Schema::hasTable('larena_search_documents'), 'Reapply must recreate Search documents.');
    search_index_assert(Schema::hasTable('larena_search_provider_states'), 'Reapply must recreate durable provider fences.');

    $fenceMigration = require dirname(__DIR__, 2) . '/database/migrations/2026_07_13_000004_create_larena_search_provider_states.php';
    $fenceMigration->down();
    $timestamp = gmdate('Y-m-d H:i:s');
    $database->connection()->table('larena_search_reindex_runs')->insert([
        'run_ref' => 'upgrade-active-run',
        'provider_id' => 'upgrade.pages',
        'active_provider_id' => 'upgrade.pages',
        'generation_ref' => 'upgrade-generation',
        'state' => 'failed',
        'cursor' => 'page:10',
        'processed_count' => 10,
        'batch_count' => 2,
        'requested_by' => 'user:upgrade',
        'correlation_id' => 'upgrade-active-run',
        'error_code' => 'search_reindex_source_failed',
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    $fenceMigration->up();
    $backfilledFence = $database->connection()->table('larena_search_provider_states')
        ->where('provider_id', 'upgrade.pages')
        ->first();
    search_index_assert(
        $backfilledFence !== null
        && (string) $backfilledFence->active_run_ref === 'upgrade-active-run'
        && (string) $backfilledFence->active_generation_ref === 'upgrade-generation',
        'The additive provider-fence migration must preserve active failed/resumable runs during upgrade.',
    );
} finally {
    $database->close();
}

echo "DatabaseSearchIndexTest passed.\n";
