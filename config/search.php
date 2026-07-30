<?php

declare(strict_types=1);

return [
    'public' => [
        'enabled' => true,
        'path' => 'search',
        'middleware' => ['web'],
        'locales' => ['ru', 'en'],
        'default_locale' => 'ru',
        'per_page' => 20,
        'maximum_page' => 500,
        'minimum_term_length' => 2,
    ],
    'admin' => [
        'enabled' => filter_var(getenv('LARENA_SEARCH_ADMIN_ROUTES') ?: false, FILTER_VALIDATE_BOOL),
        'allowed_environments' => ['local', 'testing'],
        'prefix' => 'admin/search',
        'middleware' => ['web', 'larena-auth.entry', 'larena-auth.admin-required', 'larena-admin.locale'],
        'read_middleware' => ['access:search.reindex.read'],
        'schedule_middleware' => ['access:search.reindex.schedule'],
        'run_middleware' => ['access:search.reindex.run'],
        'resume_middleware' => ['access:search.reindex.resume'],
        'retry_middleware' => ['access:search.reindex.retry'],
    ],
    'reindex' => [
        'batch_size' => max(1, min(1000, (int) (getenv('LARENA_SEARCH_REINDEX_BATCH_SIZE') ?: 100))),
    ],
];
