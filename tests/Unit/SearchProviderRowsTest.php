<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Larena\Search\Admin\SearchProviderRows;

$text = static fn (string $key): string => match ($key) {
    'states.scheduled' => 'Scheduled',
    'states.waiting' => 'Waiting for the worker',
    'states.completed' => 'Done',
    'providers.storage.site_pages' => 'Site pages',
    default => 'larena-search::admin.'.$key,
};
$provider = static fn (array $over): array => $over + ['provider_id' => 'storage.site_pages', 'state' => 'scheduled', 'run_ref' => 'search-run',
    'generation_ref' => 'generation-1', 'processed_count' => 0, 'batch_count' => 0, 'error_code' => null, 'waiting' => false];

// A source is named for a person; one without a name keeps its id.
assert(SearchProviderRows::cells($provider([]), $text)['provider'] === 'Site pages');
assert(SearchProviderRows::cells($provider(['provider_id' => 'other.source']), $text)['provider'] === 'other.source');
// A scheduled run may be run; once its step waits in the queue it reads as waiting and is not sent again.
assert(SearchProviderRows::steps($provider([])) === ['run']);
assert(SearchProviderRows::cells($provider(['waiting' => true]), $text)['state'] === 'Waiting for the worker');
assert(SearchProviderRows::steps($provider(['waiting' => true])) === []);
// A finished run shows its generation and may be scheduled again.
$done = $provider(['state' => 'completed']);
assert(SearchProviderRows::cells($done, $text)['state'] === 'Done' && SearchProviderRows::cells($done, $text)['generation'] === 'generation-1');
assert(SearchProviderRows::steps($done) === ['schedule']);

echo "SearchProviderRowsTest passed.\n";
