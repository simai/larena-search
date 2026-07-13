<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
        $timestamp = gmdate('Y-m-d H:i:s');
        $activeRuns = $connection->table('larena_search_reindex_runs')
            ->whereNotNull('active_provider_id')
            ->orderBy('id')
            ->get(['active_provider_id', 'run_ref', 'generation_ref']);
        foreach ($activeRuns as $run) {
            $connection->table('larena_search_provider_states')->insert([
                'provider_id' => (string) $run->active_provider_id,
                'active_run_ref' => (string) $run->run_ref,
                'active_generation_ref' => (string) $run->generation_ref,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_search_provider_states');
    }
};
