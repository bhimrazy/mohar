<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * jobs by (queue, id), the order the database queue pops in (database review 2026-10-31, M11). With only jobs_queue_index
 * the planner walks the primary key and filters by queue, so a backlog on one queue slows every pop on the others.
 * The framework's jobs table is changed here, as failed_jobs was (2026_10_24_000100). jobs_queue_index stays until
 * index usage on a real install shows it unused. Built concurrently on PostgreSQL.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        OnlineIndex::create('jobs', ['queue', 'id']);
    }

    public function down(): void
    {
        OnlineIndex::drop('jobs', ['queue', 'id']);
    }
};
