<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * The (run, outcome) index serves the report tabs and the apply counts. Built CONCURRENTLY on PostgreSQL, outside a
 * transaction, in its own migration: a failed build re-runs alone, without re-adding the 980200 columns.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        OnlineIndex::create('migration_records', ['migration_run_id', 'outcome']);
    }

    public function down(): void
    {
        OnlineIndex::drop('migration_records', ['migration_run_id', 'outcome']);
    }
};
