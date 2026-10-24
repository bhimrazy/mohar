<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * failed_jobs by failed_at alone: the retention task and the admin dashboard's failed-jobs count (#49). The table's
 * (connection, queue, failed_at) index cannot serve either. Built concurrently on PostgreSQL; deletes nothing.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        OnlineIndex::create('failed_jobs', ['failed_at']);
    }

    public function down(): void
    {
        OnlineIndex::drop('failed_jobs', ['failed_at']);
    }
};
