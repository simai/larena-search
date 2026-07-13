<?php

declare(strict_types=1);

namespace Larena\Search\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Runtime\AccessOperationRegistry;
use Larena\Access\ValueObjects\AccessOperationDescriptor;
use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Search\Commands\ReindexSearchCommand;
use Larena\Search\Persistence\DatabaseSearchIndex;
use Larena\Search\Reindex\SearchReindexService;
use Larena\Search\Runtime\SearchSourceRegistry;

final class SearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SearchSourceRegistry::class, static fn (): SearchSourceRegistry => new SearchSourceRegistry());
        $this->app->bind(DatabaseSearchIndex::class, static function (Application $app): DatabaseSearchIndex {
            return new DatabaseSearchIndex($app->make(DatabaseManager::class)->connection());
        });
        $this->app->bind(SearchReindexService::class, static function (Application $app): SearchReindexService {
            /** @var DatabaseManager $database */
            $database = $app->make(DatabaseManager::class);
            $connection = $database->connection();

            return new SearchReindexService(
                $connection,
                new DatabaseSearchIndex($connection),
                $app->make(SearchSourceRegistry::class),
                $app->make(ActorOperationAuthorizer::class),
                $app->make(AuditEventPipeline::class),
            );
        });

        $this->app->afterResolving(
            AccessOperationRegistry::class,
            static fn (AccessOperationRegistry $registry): bool => self::registerAccessOperations($registry),
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        $this->loadTranslationsFrom(__DIR__ . '/../../resources/lang', 'larena-search');

        if ($this->app->bound(AccessOperationRegistry::class)) {
            self::registerAccessOperations($this->app->make(AccessOperationRegistry::class));
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ReindexSearchCommand::class]);
        }
    }

    private static function registerAccessOperations(AccessOperationRegistry $registry): bool
    {
        $registered = false;
        foreach ([
            ['search.reindex.schedule', 'reindex_schedule', 'schedule'],
            ['search.reindex.run', 'reindex_run', 'run'],
            ['search.reindex.resume', 'reindex_resume', 'resume'],
        ] as [$code, $label, $grant]) {
            $registered = $registry->register(new AccessOperationDescriptor(
                code: $code,
                ownerPackage: 'larena/search',
                labelKey: 'larena-search::operations.' . $label,
                target: 'search.reindex:all',
                requiredGrant: $grant,
                risk: 'critical',
                auditDenials: true,
            )) || $registered;
        }

        return $registered;
    }
}
