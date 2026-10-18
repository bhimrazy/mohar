<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * Dashboard reads: invoices paid since a date (receivable history, tax collected this month) filter on status and
 * paid_at together. Built CONCURRENTLY on PostgreSQL (no write lock on invoices); an existing identical index is
 * reused, so re-running is safe.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        OnlineIndex::create('invoices', ['status', 'paid_at']);
    }

    public function down(): void
    {
        OnlineIndex::drop('invoices', ['status', 'paid_at']);
    }
};
