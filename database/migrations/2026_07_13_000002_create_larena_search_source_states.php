<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larena_search_source_states', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('provider_id', 120);
            $table->string('source_ref', 191);
            $table->unsignedBigInteger('source_revision');
            $table->string('state', 16);
            $table->char('projection_hash', 64)->nullable();
            $table->string('generation_ref', 64)->nullable();
            $table->timestamp('updated_at');

            $table->unique(['provider_id', 'source_ref'], 'larena_search_source_state_unique');
            $table->index(['provider_id', 'state'], 'larena_search_source_state_provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_search_source_states');
    }
};
