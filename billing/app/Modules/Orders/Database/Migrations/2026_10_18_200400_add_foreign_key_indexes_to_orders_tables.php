<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * Database audit 2026-10-18 (docs/database/audit-2026-10-18.md): a client's orders (client area, checkout
 * rules, merge counts) and every foreign key that a delete cascades or nulls through were unindexed.
 * order_items.order_id is also added by the dashboard v2 branch under the same name; whichever runs second skips.
 * Built CONCURRENTLY on PostgreSQL (no write lock) and skipped when an index of the same name exists.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var list<array{0: string, 1: list<string>}> */
    private const INDEXES = [
        ['orders', ['client_id', 'id']],
        ['orders', ['promotion_id']],
        ['orders', ['placed_by_user_id']],
        ['orders', ['placed_by_staff_id']],
        ['order_items', ['order_id']],
        ['order_items', ['parent_id']],
        ['promotion_redemptions', ['client_id']],
        ['promotion_redemptions', ['order_id']],
        ['fraud_checks', ['order_id']],
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
