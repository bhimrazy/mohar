<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * Live services by id (database review 2026-10-31, H4), for the renewal run: it takes services in id chunks, and
 * without this index the planner walks the primary key and filters out every terminated, cancelled and deleted
 * service. The predicate is ServiceStatus::billable() plus the soft-delete scope, the widest set the service tasks
 * query (renewals include pending), so the index can also serve suspension, termination, fixed-term completion and
 * period-end cancellation; with real statistics those keep their next_due_date indexes. Built concurrently on
 * PostgreSQL.
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
