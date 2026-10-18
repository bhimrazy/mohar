<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * Database audit 2026-10-18 (docs/database/audit-2026-10-18.md): the last foreign key without an index
 * (PostgreSQL does not index foreign keys; deleting a user scanned the table). Built CONCURRENTLY on PostgreSQL.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        OnlineIndex::create('themes', ['created_by']);
    }

    public function down(): void
    {
        OnlineIndex::drop('themes', ['created_by']);
    }
};
