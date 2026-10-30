<?php

declare(strict_types=1);

// Boots the provider in a process of its own and prints what it composed. argv[1] says which optional packages can be
// loaded: "none" (a node with neither larena/admin nor larena/ui), "ui" (the public page, no admin) or "admin" (the admin
// with its registry bound, and its ui). The admin's routes are switched on in every case, to show what gates them.

use Composer\Autoload\ClassLoader;
use Illuminate\Console\Application as Artisan;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Facade;
use Larena\Admin\Navigation\AdminNavigationRegistry;
use Larena\Search\Providers\SearchServiceProvider;

// This package's development install has no framework helpers; the route files only read the config.
function config(string $key, mixed $default = null): mixed
{
    return Container::getInstance()->make('config')->get($key, $default);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/AdminPresenceHost.php';

$mode = $argv[1] ?? '';
foreach (ClassLoader::getRegisteredLoaders() as $loader) {
    if ($mode !== 'admin') {
        $loader->setPsr4('Larena\\Admin\\', []);
    }
    if ($mode === 'none') {
        $loader->setPsr4('Larena\\Ui\\', []);
    }
}

$app = new AdminPresenceHost();
Container::setInstance($app);
$config = new class implements ConfigContract {
    /** @var array<string, mixed> */
    private array $items = [];

    public function has($key): bool
    {
        return Arr::has($this->items, $key);
    }

    public function get($key, $default = null): mixed
    {
        return Arr::get($this->items, $key, $default);
    }

    public function all(): array
    {
        return $this->items;
    }

    public function set($key, $value = null): void
    {
        Arr::set($this->items, $key, $value);
    }

    public function prepend($key, $value): void
    {
    }

    public function push($key, $value): void
    {
    }
};
$app->instance('config', $config);
$app->instance(ConfigContract::class, $config);
$app->instance('router', new Router(new Dispatcher($app), $app));
Facade::setFacadeApplication($app);
$navigation = null;
if ($mode === 'admin') {
    $navigation = new AdminNavigationRegistry();
    $app->instance(AdminNavigationRegistry::class, $navigation);
}
$provider = new SearchServiceProvider($app);
$provider->register();
$config->set('larena-search.admin.enabled', true);
$app->call([$provider, 'boot']);

$routes = [];
foreach ($app->make('router')->getRoutes()->getRoutes() as $route) {
    $routes[(string) $route->getName()] = $route->uri();
}
$artisan = new class {
    /** @var list<string> */
    public array $commands = [];

    /** @param list<string> $commands */
    public function resolveCommands(array $commands): void
    {
        $this->commands = [...$this->commands, ...$commands];
    }
};
foreach ((new ReflectionProperty(Artisan::class, 'bootstrappers'))->getValue() as $bootstrapper) {
    $bootstrapper($artisan);
}

echo json_encode([
    'admin_loadable' => class_exists('Larena\\Admin\\Navigation\\AdminNavigationRegistry'),
    'ui_loadable' => class_exists('Larena\\Ui\\Frontend\\FrontendRuntimeLock'),
    'routes' => $routes,
    'navigation' => $navigation === null ? [] : array_map(static fn ($descriptor): string => $descriptor->id, $navigation->descriptors()),
    'commands' => $artisan->commands,
], JSON_THROW_ON_ERROR);
