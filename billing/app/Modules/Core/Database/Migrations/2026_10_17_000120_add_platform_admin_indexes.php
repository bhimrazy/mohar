<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foreign keys get no automatic index on PostgreSQL/SQLite; these back the client profile activity
 * query (client_id ... order by id) and the merge tombstone lookups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->index(['client_id', 'id']);
            $table->index('impersonator_id');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->index('merged_into_client_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['merged_into_client_id']);
        });

        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['impersonator_id']);
            $table->dropIndex(['client_id', 'id']);
        });
    }
};
