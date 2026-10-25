<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * Gateway refunds waiting for the provider (#334): the "Refunds waiting for the provider" to-do and its list read this
 * partial index, ordered by age. Few rows qualify at any time, so it stays tiny. Built CONCURRENTLY on PostgreSQL.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        OnlineIndex::create('transactions', ['created_at'], 'transactions_refund_pending_index', where: "type = 'refund' and status = 'pending'");
    }

    public function down(): void
    {
        OnlineIndex::drop('transactions', 'transactions_refund_pending_index');
    }
};
