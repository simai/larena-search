<?php

declare(strict_types=1);

namespace Larena\Search\Persistence;

use Illuminate\Database\ConnectionInterface;
use LogicException;
use RuntimeException;
use stdClass;

final readonly class ProviderGenerationFence
{
    public function __construct(private ConnectionInterface $database)
    {
    }

    public function lock(string $providerId): LockedProviderState
    {
        $this->assertTransaction();
        $timestamp = $this->timestamp();
        $this->database->table('larena_search_provider_states')->insertOrIgnore([
            'provider_id' => $providerId,
            'active_run_ref' => null,
            'active_generation_ref' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $row = $this->database->table('larena_search_provider_states')
            ->where('provider_id', $providerId)
            ->lockForUpdate()
            ->first();
        if (!$row instanceof stdClass) {
            throw new RuntimeException('search_provider_fence_missing');
        }

        $activeRunRef = $this->nullableReference($row->active_run_ref ?? null);
        $activeGenerationRef = $this->nullableReference($row->active_generation_ref ?? null);
        if (($activeRunRef === null) !== ($activeGenerationRef === null)) {
            throw new RuntimeException('search_provider_fence_corrupt');
        }

        return new LockedProviderState($providerId, $activeRunRef, $activeGenerationRef);
    }

    public function activate(LockedProviderState $state, string $runRef, string $generationRef): LockedProviderState
    {
        $this->assertTransaction();
        if ($state->isActive()) {
            throw new LogicException('search_provider_fence_already_active');
        }

        $affected = $this->database->table('larena_search_provider_states')
            ->where('provider_id', $state->providerId)
            ->whereNull('active_run_ref')
            ->whereNull('active_generation_ref')
            ->update([
                'active_run_ref' => $runRef,
                'active_generation_ref' => $generationRef,
                'updated_at' => $this->timestamp(),
            ]);
        if ($affected !== 1) {
            throw new RuntimeException('search_provider_fence_claim_failed');
        }

        return new LockedProviderState($state->providerId, $runRef, $generationRef);
    }

    public function assertActive(LockedProviderState $state, string $runRef, string $generationRef): void
    {
        $this->assertTransaction();
        if ($state->activeRunRef !== $runRef || $state->activeGenerationRef !== $generationRef) {
            throw new RuntimeException('search_provider_fence_mismatch');
        }
    }

    public function generationForWrite(LockedProviderState $state, ?string $requestedGenerationRef): ?string
    {
        $this->assertTransaction();
        if ($requestedGenerationRef === null) {
            return $state->activeGenerationRef;
        }
        if ($state->activeGenerationRef !== $requestedGenerationRef) {
            throw new RuntimeException('search_provider_generation_inactive');
        }

        return $requestedGenerationRef;
    }

    public function clear(LockedProviderState $state, string $runRef, string $generationRef): void
    {
        $this->assertTransaction();
        $this->assertActive($state, $runRef, $generationRef);

        $affected = $this->database->table('larena_search_provider_states')
            ->where('provider_id', $state->providerId)
            ->where('active_run_ref', $runRef)
            ->where('active_generation_ref', $generationRef)
            ->update([
                'active_run_ref' => null,
                'active_generation_ref' => null,
                'updated_at' => $this->timestamp(),
            ]);
        if ($affected !== 1) {
            throw new RuntimeException('search_provider_fence_clear_failed');
        }
    }

    private function assertTransaction(): void
    {
        if ($this->database->transactionLevel() < 1) {
            throw new LogicException('search_provider_fence_requires_transaction');
        }
    }

    private function nullableReference(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('search_provider_fence_corrupt');
        }

        return $value;
    }

    private function timestamp(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
