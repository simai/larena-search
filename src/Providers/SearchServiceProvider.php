<?php

declare(strict_types=1);

namespace Larena\Search\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Larena\Admin\Navigation\AdminNavigationRegistry;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Runtime\AccessOperationRegistry;
use Larena\Access\ValueObjects\AccessOperationDescriptor;
use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Queue\Enums\QueuePriority;
use Larena\Queue\Enums\RuntimeProfile;
use Larena\Queue\Runtime\ImmutableJobDescriptor;
use Larena\Queue\Runtime\JobTypeRegistry;
use Larena\Search\Commands\ReindexSearchCommand;
use Larena\Search\Navigation\SearchAdminNavigationContributor;
use Larena\Search\Operations\SearchIndexOperationsQuery;
use Larena\Search\Persistence\DatabaseSearchIndex;
use Larena\Search\Queue\ScheduleAllSearchProvidersJobHandler;
use Larena\Search\Queue\SearchReindexJobHandler;
use Larena\Search\Queue\SearchReindexDispatcher;
use Larena\Search\Queue\SearchReindexWorkerAttemptCodec;
use Larena\Search\Queue\SearchReindexWorkerDispatcher;
use Larena\Search\Reindex\SearchReindexExecutionEngine;
use Larena\Search\Reindex\SearchReindexService;
use Larena\Search\Runtime\SearchSourceRegistry;
use Larena\Search\Scheduler\SearchScheduledReindexHandler;
use Larena\Scheduler\Runtime\ScheduledOperationRegistry;

final class SearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/search.php', 'larena-search');
        $this->app->singleton(SearchSourceRegistry::class, static fn (): SearchSourceRegistry => new SearchSourceRegistry());
        $this->app->bind(DatabaseSearchIndex::class, static function (Application $app): DatabaseSearchIndex {
            return new DatabaseSearchIndex($app->make(DatabaseManager::class)->connection());
        });
        $this->app->bind(SearchReindexService::class, static fn (Application $app): SearchReindexService => new SearchReindexService(
            self::engine($app),
        ));
        $this->app->scoped(SearchIndexOperationsQuery::class, static function (Application $app): SearchIndexOperationsQuery {
            return new SearchIndexOperationsQuery(
                $app->make(DatabaseManager::class)->connection(),
                $app->make(SearchSourceRegistry::class),
            );
        });
        $this->app->bind(SearchReindexDispatcher::class, static function (Application $app): SearchReindexDispatcher {
            /** @var Config $config */
            $config = $app->make(Config::class);

            return new SearchReindexDispatcher(
                $app->make(SearchReindexService::class),
                $app->make(\Larena\Queue\Runtime\DurableQueueDispatcher::class),
                $app->make(SearchIndexOperationsQuery::class),
                self::attemptCodec($app),
                max(1, min(1000, (int) $config->get('larena-search.reindex.batch_size', 100))),
            );
        });
        $this->app->bind(SearchReindexJobHandler::class, static function (Application $app): SearchReindexJobHandler {
            /** @var Config $config */
            $config = $app->make(Config::class);
            $codec = self::attemptCodec($app);
            $workerDispatcher = new SearchReindexWorkerDispatcher(
                $app->make(\Larena\Queue\Runtime\DurableQueueDispatcher::class),
                $codec,
                max(1, min(1000, (int) $config->get('larena-search.reindex.batch_size', 100))),
            );

            return new SearchReindexJobHandler(self::engine($app), $codec, $workerDispatcher);
        });

        $this->app->afterResolving(JobTypeRegistry::class, static function (JobTypeRegistry $registry, Application $app): void {
            self::registerQueueJobs($registry, $app);
        });
        $this->app->afterResolving(ScheduledOperationRegistry::class, static function (ScheduledOperationRegistry $registry, Application $app): void {
            if (!$registry->has(SearchScheduledReindexHandler::OPERATION_REF)) {
                $registry->register($app->make(SearchScheduledReindexHandler::class));
            }
        });

        $this->app->afterResolving(
            AccessOperationRegistry::class,
            static fn (AccessOperationRegistry $registry): bool => self::registerAccessOperations($registry),
        );
    }

    public function boot(Config $config): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'larena-search');
        $this->loadTranslationsFrom(__DIR__ . '/../../resources/lang', 'larena-search');

        if ((bool) $config->get('larena-search.public.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../../routes/public.php');
        }
        if ($this->app->environment((array) $config->get('larena-search.admin.allowed_environments', ['local', 'testing']))
            && (bool) $config->get('larena-search.admin.enabled', false)) {
            $this->loadRoutesFrom(__DIR__ . '/../../routes/admin.php');
        }
        if ($this->app->bound(AdminNavigationRegistry::class)) {
            $this->app->make(AdminNavigationRegistry::class)->registerContributor(new SearchAdminNavigationContributor());
        }
        if ($this->app->bound(JobTypeRegistry::class)) {
            self::registerQueueJobs($this->app->make(JobTypeRegistry::class), $this->app);
        }
        if ($this->app->bound(ScheduledOperationRegistry::class)) {
            $registry = $this->app->make(ScheduledOperationRegistry::class);
            if (!$registry->has(SearchScheduledReindexHandler::OPERATION_REF)) {
                $registry->register($this->app->make(SearchScheduledReindexHandler::class));
            }
        }

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
            ['search.reindex.read', 'reindex_read', 'read', 'normal'],
            ['search.reindex.schedule', 'reindex_schedule', 'schedule', 'critical'],
            ['search.reindex.run', 'reindex_run', 'run', 'critical'],
            ['search.reindex.resume', 'reindex_resume', 'resume', 'critical'],
            ['search.reindex.retry', 'reindex_retry', 'retry', 'critical'],
        ] as [$code, $label, $grant, $risk]) {
            $registered = $registry->register(new AccessOperationDescriptor(
                code: $code,
                ownerPackage: 'larena/search',
                labelKey: 'larena-search::operations.' . $label,
                target: 'search.reindex:all',
                requiredGrant: $grant,
                risk: $risk,
                auditDenials: true,
            )) || $registered;
        }

        return $registered;
    }

    private static function engine(Application $app): SearchReindexExecutionEngine
    {
        /** @var DatabaseManager $database */
        $database = $app->make(DatabaseManager::class);
        $connection = $database->connection();

        return new SearchReindexExecutionEngine(
            $connection,
            new DatabaseSearchIndex($connection),
            $app->make(SearchSourceRegistry::class),
            $app->make(ActorOperationAuthorizer::class),
            $app->make(AuditEventPipeline::class),
        );
    }

    private static function attemptCodec(Application $app): SearchReindexWorkerAttemptCodec
    {
        /** @var Config $config */
        $config = $app->make(Config::class);
        $applicationKey = (string) $config->get('app.key', '');
        if ($applicationKey === '') {
            throw new \InvalidArgumentException('search_reindex_worker_key_missing');
        }

        return new SearchReindexWorkerAttemptCodec(hash('sha256', 'larena/search-worker|' . $applicationKey, true));
    }

    private static function registerQueueJobs(JobTypeRegistry $registry, Application $app): void
    {
        if (!$registry->has(SearchReindexJobHandler::JOB_TYPE)) {
            $registry->register(new ImmutableJobDescriptor(
                SearchReindexJobHandler::JOB_TYPE, 'search.reindex.run', 'search.reindex.process.handler',
                300, 5, 15, 60, QueuePriority::Maintenance, 'sanitized_search_reindex',
                'larena.search.reindex.process.payload', [RuntimeProfile::LaravelWorker, RuntimeProfile::LarenaDispatcher],
            ), $app->make(SearchReindexJobHandler::class));
        }
        if (!$registry->has(SearchScheduledReindexHandler::JOB_TYPE)) {
            $registry->register(new ImmutableJobDescriptor(
                SearchScheduledReindexHandler::JOB_TYPE, 'search.reindex.schedule', 'search.reindex.schedule_all.handler',
                120, 3, 30, 60, QueuePriority::Maintenance, 'sanitized_search_reindex',
                'larena.search.reindex.schedule_all.payload', [RuntimeProfile::LaravelWorker, RuntimeProfile::LarenaDispatcher, RuntimeProfile::ArtisanTick],
            ), $app->make(ScheduleAllSearchProvidersJobHandler::class));
        }
    }
}
