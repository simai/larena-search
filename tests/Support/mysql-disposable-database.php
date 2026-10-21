<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

/**
 * A throwaway MySQL database for an opt-in test, on the local server named by the
 * root's ignored, owner-only .env.auth-mfa-mysql-test. The database name is random
 * and allowlisted, and it is dropped when the test ends, even on failure.
 */
function larena_search_mysql_expect(bool $condition, string $reason): void
{
    if (!$condition) {
        throw new RuntimeException($reason);
    }
}

/** @return array{host: string, port: int, username: string, password: string} */
function larena_search_mysql_credentials(): array
{
    $root = dirname(__DIR__, 5) . '/larena';
    $path = $root . '/.env.auth-mfa-mysql-test';
    larena_search_mysql_expect(realpath($path) === $path, 'search_mysql_env_path_invalid');
    $ignored = proc_close(proc_open(['git', 'check-ignore', '--quiet', '--', '.env.auth-mfa-mysql-test'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root));
    larena_search_mysql_expect($ignored === 0, 'search_mysql_env_not_ignored');
    $permissions = fileperms($path);
    larena_search_mysql_expect(is_int($permissions) && ($permissions & 0o077) === 0, 'search_mysql_env_permissions_unsafe');

    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\s*(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $matches) === 1) {
            $values[$matches[1]] = trim(trim($matches[2]), '"\'');
        }
    }
    foreach (['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD'] as $required) {
        larena_search_mysql_expect(array_key_exists($required, $values), 'search_mysql_env_incomplete');
    }
    larena_search_mysql_expect(in_array(strtolower($values['DB_HOST']), ['127.0.0.1', 'localhost', '::1'], true), 'search_mysql_host_not_local');
    larena_search_mysql_expect(ctype_digit($values['DB_PORT']), 'search_mysql_port_invalid');

    return ['host' => $values['DB_HOST'], 'port' => (int) $values['DB_PORT'], 'username' => $values['DB_USERNAME'], 'password' => $values['DB_PASSWORD']];
}

/**
 * Creates the database and returns its connection config and a drop callback.
 *
 * @return array{config: array<string, mixed>, drop: Closure(): void}
 */
function larena_search_mysql_disposable_database(string $prefix): array
{
    larena_search_mysql_expect(extension_loaded('pdo_mysql'), 'search_mysql_pdo_extension_missing');
    larena_search_mysql_expect(preg_match('/^larena_search_[a-z_]+_test_$/', $prefix) === 1, 'search_mysql_prefix_invalid');
    $credentials = larena_search_mysql_credentials();
    $database = $prefix . bin2hex(random_bytes(6));
    $server = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $credentials['host'], $credentials['port']),
        $credentials['username'],
        $credentials['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $dropped = false;
    $drop = static function () use ($server, $database, &$dropped): void {
        if (!$dropped) {
            $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
            $dropped = true;
        }
    };
    register_shutdown_function($drop);

    return [
        'config' => [
            'driver' => 'mysql',
            'host' => $credentials['host'],
            'port' => $credentials['port'],
            'database' => $database,
            'username' => $credentials['username'],
            'password' => $credentials['password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ],
        'drop' => $drop,
    ];
}

/** A fresh connection: a new PDO, as after a process restart. @param array<string, mixed> $config */
function larena_search_mysql_connect(array $config): Connection
{
    $container = new Container();
    $capsule = new Capsule($container);
    $capsule->addConnection($config);
    $capsule->setAsGlobal();
    $connection = $capsule->getConnection();
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.schema', $connection->getSchemaBuilder());
    Facade::clearResolvedInstances();
    Schema::swap($connection->getSchemaBuilder());

    return $connection;
}
