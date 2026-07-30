<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Larena\Search\Http\Controllers\PublicSearchController;

Route::middleware((array) config('larena-search.public.middleware', ['web']))
    ->get((string) config('larena-search.public.path', 'search'), PublicSearchController::class)
    ->name('larena.search.public');
