<?php

declare(strict_types=1);

namespace Larena\Search\Operations;

use Illuminate\Database\ConnectionInterface;
use Larena\Search\Runtime\SearchSourceRegistry;
use stdClass;

final readonly class SearchIndexOperationsQuery
{
    public function __construct(private ConnectionInterface $database, private SearchSourceRegistry $sources)
    {
    }

    /** @return list<array<string, scalar|null>> */
    public function providers(): array
    {
        $rows = [];
        foreach ($this->sources->providerIds() as $providerId) {
            $state = $this->database->table('larena_search_provider_states')->where('provider_id', $providerId)->first();
            $latest = $this->database->table('larena_search_reindex_runs')->where('provider_id', $providerId)->orderByDesc('id')->first();
            $latest = $latest instanceof stdClass ? $latest : null;
            $state = $state instanceof stdClass ? $state : null;
            $rows[] = [
                'provider_id' => $providerId,
                'state' => $latest === null ? 'idle' : (string) $latest->state,
                'run_ref' => $latest?->run_ref,
                'generation_ref' => $state?->active_generation_ref,
                'processed_count' => $latest?->processed_count === null ? 0 : (int) $latest->processed_count,
                'batch_count' => $latest?->batch_count === null ? 0 : (int) $latest->batch_count,
                'error_code' => $latest?->error_code,
                'updated_at' => $latest?->updated_at,
            ];
        }

        return $rows;
    }

    /** @return array<string, scalar|null>|null */
    public function provider(string $providerId): ?array
    {
        foreach ($this->providers() as $provider) {
            if ($provider['provider_id'] === $providerId) {
                return $provider;
            }
        }

        return null;
    }
}
