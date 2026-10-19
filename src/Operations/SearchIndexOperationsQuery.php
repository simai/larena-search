<?php

declare(strict_types=1);

namespace Larena\Search\Operations;

use Illuminate\Database\Connection;
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
                // The generation the latest run writes or wrote; the fence holds one only while a run is active.
                'generation_ref' => $latest->generation_ref ?? $state?->active_generation_ref,
                'waiting' => $latest !== null && $this->waiting((string) $latest->run_ref),
                'processed_count' => $latest?->processed_count === null ? 0 : (int) $latest->processed_count,
                'batch_count' => $latest?->batch_count === null ? 0 : (int) $latest->batch_count,
                'error_code' => $latest?->error_code,
                'updated_at' => $latest?->updated_at,
            ];
        }

        return $rows;
    }

    /**
     * Whether a step of the run is in the queue and no worker has taken it yet: the page then says it waits
     * for the background worker rather than looking stuck.
     */
    private function waiting(string $runRef): bool
    {
        if (!$this->database instanceof Connection || !$this->database->getSchemaBuilder()->hasTable('larena_queue_jobs')) {
            return false;
        }

        return $this->database->table('larena_queue_jobs')
            ->where('job_type', 'search.reindex.process')
            ->where('idempotency_key', 'like', 'search-reindex:'.$runRef.':%')
            ->whereIn('status', ['created', 'queued', 'deferred', 'retrying'])
            ->exists();
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
