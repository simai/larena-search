<?php

declare(strict_types=1);

namespace Larena\Search\Tests\Support;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class SearchTestDatabase
{
    /** @var list<object> */
    private array $migrations = [];

    private function __construct(
        private readonly Capsule $capsule,
        public readonly string $path,
    ) {
    }

    public static function create(bool $withAudit = true): self
    {
        $path = tempnam(sys_get_temp_dir(), 'larena-search-');
        if (!is_string($path)) {
            throw new RuntimeException('Could not allocate Search test database.');
        }

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Schema::swap($capsule->getConnection()->getSchemaBuilder());

        $database = new self($capsule, $path);
        $database->migrateUp($withAudit);

        return $database;
    }

    public function connection(): ConnectionInterface
    {
        return $this->capsule->getConnection();
    }

    public function reconnect(): ConnectionInterface
    {
        $this->capsule->getDatabaseManager()->purge();
        $connection = $this->capsule->getConnection();
        Schema::swap($connection->getSchemaBuilder());

        return $connection;
    }

    public function rollback(): void
    {
        foreach (array_reverse($this->migrations) as $migration) {
            $migration->down();
        }
        $this->migrations = [];
    }

    public function reapply(bool $withAudit = true): void
    {
        $this->migrateUp($withAudit);
    }

    public function close(): void
    {
        $this->capsule->getDatabaseManager()->disconnect();
        Schema::clearResolvedInstance('schema');
        foreach ([$this->path, $this->path . '-wal', $this->path . '-shm', $this->path . '-journal'] as $artifact) {
            if (is_file($artifact) && !unlink($artifact)) {
                throw new RuntimeException('Could not remove Search test database artifact.');
            }
            if (is_file($artifact)) {
                throw new RuntimeException('Search test database artifact remains after cleanup.');
            }
        }
    }

    private function migrateUp(bool $withAudit): void
    {
        $paths = [];
        if ($withAudit) {
            $paths[] = dirname(__DIR__, 2) . '/vendor/larena/audit/database/migrations/2026_07_09_000001_create_larena_audit_events_table.php';
        }
        array_push(
            $paths,
            dirname(__DIR__, 2) . '/database/migrations/2026_07_13_000001_create_larena_search_documents.php',
            dirname(__DIR__, 2) . '/database/migrations/2026_07_13_000002_create_larena_search_source_states.php',
            dirname(__DIR__, 2) . '/database/migrations/2026_07_13_000003_create_larena_search_reindex_runs.php',
            dirname(__DIR__, 2) . '/database/migrations/2026_07_13_000004_create_larena_search_provider_states.php',
        );

        foreach ($paths as $path) {
            $migration = require $path;
            if (!is_object($migration) || !method_exists($migration, 'up') || !method_exists($migration, 'down')) {
                throw new RuntimeException('Search test migration contract is invalid.');
            }
            $migration->up();
            $this->migrations[] = $migration;
        }
    }
}
