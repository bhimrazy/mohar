<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client import (client-import spec §4, C5): when a client was imported and the columns kept as imported fields
 * (label, value, source; at most 50), plus the append-only marketing consent history with its evidence. The current
 * state is the latest row. `migration_run_id` is plain (no foreign key): the Migration module is optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->timestamp('imported_at')->nullable();
            $table->json('imported_fields')->nullable();
        });

        Schema::create('client_marketing_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('state', 16);
            $table->string('channel', 16)->default('email');
            $table->string('source', 24);
            $table->string('evidence', 500)->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('recorded_at');
            $table->foreignId('recorded_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('migration_run_id')->nullable()->index();
            $table->index(['client_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_marketing_consents');
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn(['imported_at', 'imported_fields']));
    }
};
