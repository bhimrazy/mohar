<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * Live services by id (database review 2026-10-31, H4): renewals, suspension, termination, fixed-term completion and
 * period-end cancellation take services in id chunks; without it the planner walks the primary key and filters out
 * every terminated, cancelled and deleted service. The predicate is ServiceStatus::billable() plus the soft-delete
 * scope, the widest set those tasks query (renewals include pending). Built concurrently on PostgreSQL.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        OnlineIndex::create('services', ['id'], 'services_live_id_index', where: "status in ('pending', 'active', 'suspended') and deleted_at is null");
    }

    public function down(): void
    {
        OnlineIndex::drop('services', 'services_live_id_index');
    }
};
