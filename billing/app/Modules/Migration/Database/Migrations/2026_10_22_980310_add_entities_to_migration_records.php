<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-entity outcomes of an applied row (ADR 0019 addendum): which importer (client, user, credit_balance) did what, so
 * undo hands each importer its own outcome and the run counts every entity. Action, target id and field names only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migration_records', function (Blueprint $table): void {
            $table->json('entities')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('migration_records', function (Blueprint $table): void {
            $table->dropColumn('entities');
        });
    }
};
