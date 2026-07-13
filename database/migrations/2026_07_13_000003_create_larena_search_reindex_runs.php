<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larena_search_reindex_runs', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('run_ref', 64)->unique();
            $table->string('provider_id', 120);
            $table->string('active_provider_id', 120)->nullable()->unique();
            $table->string('generation_ref', 64);
            $table->string('state', 16);
            $table->text('cursor')->nullable();
            $table->unsignedBigInteger('processed_count')->default(0);
            $table->unsignedBigInteger('batch_count')->default(0);
            $table->string('requested_by', 191);
            $table->string('correlation_id', 191);
            $table->string('error_code', 120)->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'state'], 'larena_search_reindex_provider_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_search_reindex_runs');
    }
};
