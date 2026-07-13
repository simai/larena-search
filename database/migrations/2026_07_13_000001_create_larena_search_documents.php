<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larena_search_documents', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('provider_id', 120);
            $table->string('source_ref', 191);
            $table->unsignedBigInteger('source_revision');
            $table->string('title', 500);
            $table->text('locator');
            $table->text('snippet')->nullable();
            $table->string('locale', 32)->nullable();
            $table->string('access_scope', 120);
            $table->longText('searchable_text');
            $table->longText('payload');
            $table->char('projection_hash', 64);
            $table->string('generation_ref', 64)->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'source_ref'], 'larena_search_document_source_unique');
            $table->index(['provider_id', 'locale'], 'larena_search_document_provider_locale');
            $table->index(['provider_id', 'generation_ref'], 'larena_search_document_generation');
            $table->index(['access_scope', 'provider_id'], 'larena_search_document_access');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_search_documents');
    }
};
