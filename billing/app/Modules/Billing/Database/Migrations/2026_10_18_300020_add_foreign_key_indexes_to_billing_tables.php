<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * Database audit 2026-10-18 (docs/database/audit-2026-10-18.md): foreign keys that deletes cascade or null
 * through, and the payment-attempt lookups by batch and settled transaction, were unindexed. The admin
 * transaction list and the recent-payments widget sort all transactions by (occurred_at, id).
 * Built CONCURRENTLY on PostgreSQL (no write lock) and skipped when an index of the same name exists.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var list<array{0: string, 1: list<string>}> */
    private const INDEXES = [
        ['transactions', ['occurred_at', 'id']],
        ['payment_attempts', ['payment_batch_id']],
        ['payment_attempts', ['transaction_id']],
        ['quotes', ['invoice_id']],
        ['invoice_adjustments', ['created_by']],
        ['transactions', ['created_by']],
        ['credit_ledger', ['staff_id']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as [$table, $columns]) {
            OnlineIndex::create($table, $columns);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::INDEXES) as [$table, $columns]) {
            OnlineIndex::drop($table, $columns);
        }
    }
};
