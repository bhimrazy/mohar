<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last server check of a "verify" item (ReconcilesCommands, ADR 0009 amendment 1): what the driver found and when.
 * Staff see it on the failed-actions queue, and "Create again" waits for a not-executed answer. Nullable and without
 * defaults, so adding them rewrites no rows; nothing filters on them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_queue', function (Blueprint $table): void {
            $table->string('lookup_status', 20)->nullable();
            $table->string('lookup_message', 255)->nullable();
            $table->timestamp('looked_up_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('module_queue', function (Blueprint $table): void {
            $table->dropColumn(['lookup_status', 'lookup_message', 'looked_up_at']);
        });
    }
};
