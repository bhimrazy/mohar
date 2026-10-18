<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Values of a server's driver-declared settings (DriverMetadata::$serverFields, ADR 0009 amendment 1), stored as one
 * encrypted JSON document (`encrypted:array`): settings may hold secrets, and nothing queries inside them.
 * Nullable and without a default, so adding it rewrites no rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->text('settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn('settings');
        });
    }
};
