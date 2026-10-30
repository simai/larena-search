<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;

/**
 * The application the provider boots on in its presence proof: this package's development install has no
 * laravel/framework. Only what a provider's boot asks for answers; the rest of the contract is never reached.
 */
final class AdminPresenceHost extends Container implements Application
{
    public function runningInConsole(): bool
    {
        return true;
    }

    public function runningUnitTests(): bool
    {
        return true;
    }

    /** @param string|list<string> ...$environments */
    public function environment(...$environments): bool
    {
        return in_array('testing', array_merge(...array_map(static fn ($names): array => (array) $names, $environments)), true);
    }

    public function version()
    {
        throw new LogicException(__METHOD__);
    }

    public function basePath($path = '')
    {
        throw new LogicException(__METHOD__);
    }

    public function bootstrapPath($path = '')
    {
        throw new LogicException(__METHOD__);
    }

    public function configPath($path = '')
    {
        throw new LogicException(__METHOD__);
    }

    public function databasePath($path = '')
    {
        throw new LogicException(__METHOD__);
    }

    public function langPath($path = '')
    {
        throw new LogicException(__METHOD__);
    }

    public function publicPath($path = '')
    {
        throw new LogicException(__METHOD__);
    }

    public function resourcePath($path = '')
    {
        throw new LogicException(__METHOD__);
    }

    public function storagePath($path = '')
    {
        throw new LogicException(__METHOD__);
    }

    public function hasDebugModeEnabled()
    {
        throw new LogicException(__METHOD__);
    }

    public function maintenanceMode()
    {
        throw new LogicException(__METHOD__);
    }

    public function isDownForMaintenance()
    {
        throw new LogicException(__METHOD__);
    }

    public function registerConfiguredProviders()
    {
        throw new LogicException(__METHOD__);
    }

    public function register($provider, $force = false)
    {
        throw new LogicException(__METHOD__);
    }

    public function registerDeferredProvider($provider, $service = null)
    {
        throw new LogicException(__METHOD__);
    }

    public function resolveProvider($provider)
    {
        throw new LogicException(__METHOD__);
    }

    public function boot()
    {
        throw new LogicException(__METHOD__);
    }

    public function booting($callback)
    {
        throw new LogicException(__METHOD__);
    }

    public function booted($callback)
    {
        throw new LogicException(__METHOD__);
    }

    public function bootstrapWith($bootstrappers)
    {
        throw new LogicException(__METHOD__);
    }

    public function getLocale()
    {
        throw new LogicException(__METHOD__);
    }

    public function getNamespace()
    {
        throw new LogicException(__METHOD__);
    }

    public function getProviders($provider)
    {
        throw new LogicException(__METHOD__);
    }

    public function hasBeenBootstrapped()
    {
        throw new LogicException(__METHOD__);
    }

    public function loadDeferredProviders()
    {
        throw new LogicException(__METHOD__);
    }

    public function setLocale($locale)
    {
        throw new LogicException(__METHOD__);
    }

    public function shouldSkipMiddleware()
    {
        throw new LogicException(__METHOD__);
    }

    public function terminating($callback)
    {
        throw new LogicException(__METHOD__);
    }

    public function terminate()
    {
        throw new LogicException(__METHOD__);
    }
}
