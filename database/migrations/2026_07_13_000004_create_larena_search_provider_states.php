<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Literal timestamp encoded by migration id 2026_07_13_000004.
    private const MIGRATION_TIMESTAMP = '2026-07-13 00:00:04';

    public function up(): void
    {
        Schema::create('larena_search_provider_states', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('provider_id', 120)->unique();
            $table->string('active_run_ref', 64)->nullable()->unique();
            $table->string('active_generation_ref', 64)->nullable();
            $table->timestamps();
        });

        $connection = Schema::getConnection();
        $activeRuns = $connection->table('larena_search_reindex_runs')
            ->whereNotNull('active_provider_id')
            ->orderBy('id')
            ->get(['active_provider_id', 'run_ref', 'generation_ref', 'created_at', 'updated_at']);
        foreach ($activeRuns as $run) {
            $createdAt = $this->canonicalTimestamp($run->created_at ?? null, $run->updated_at ?? null);
            $updatedAt = $this->canonicalTimestamp($run->updated_at ?? null, $run->created_at ?? null);
            $connection->table('larena_search_provider_states')->insert([
                'provider_id' => (string) $run->active_provider_id,
                'active_run_ref' => (string) $run->run_ref,
                'active_generation_ref' => (string) $run->generation_ref,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_search_provider_states');
    }

    private function canonicalTimestamp(mixed ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if ($candidate instanceof \DateTimeInterface) {
                return $candidate->format('Y-m-d H:i:s');
            }
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return self::MIGRATION_TIMESTAMP;
    }
};
