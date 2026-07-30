<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Larena\Search\Http\Controllers\SearchAdminController;

Route::prefix((string) config('larena-search.admin.prefix', 'admin/search'))
    ->middleware((array) config('larena-search.admin.middleware', []))
    ->name('larena.search.admin.')
    ->group(static function (): void {
        Route::get('/', [SearchAdminController::class, 'index'])
            ->middleware((array) config('larena-search.admin.read_middleware', []))->name('index');
        Route::post('/providers/{providerId}/schedule', [SearchAdminController::class, 'schedule'])
            ->where('providerId', '[a-z][a-z0-9_.-]{1,119}')
            ->middleware((array) config('larena-search.admin.schedule_middleware', []))->name('schedule');
        Route::post('/providers/{providerId}/runs/{runRef}/run', [SearchAdminController::class, 'run'])
            ->where('providerId', '[a-z][a-z0-9_.-]{1,119}')->where('runRef', '[a-zA-Z0-9_.:-]{1,64}')
            ->middleware((array) config('larena-search.admin.run_middleware', []))->name('run');
        Route::post('/providers/{providerId}/runs/{runRef}/resume', [SearchAdminController::class, 'resume'])
            ->where('providerId', '[a-z][a-z0-9_.-]{1,119}')->where('runRef', '[a-zA-Z0-9_.:-]{1,64}')
            ->middleware((array) config('larena-search.admin.resume_middleware', []))->name('resume');
        Route::post('/providers/{providerId}/runs/{runRef}/retry', [SearchAdminController::class, 'retry'])
            ->where('providerId', '[a-z][a-z0-9_.-]{1,119}')->where('runRef', '[a-zA-Z0-9_.:-]{1,64}')
            ->middleware((array) config('larena-search.admin.retry_middleware', []))->name('retry');
    });
