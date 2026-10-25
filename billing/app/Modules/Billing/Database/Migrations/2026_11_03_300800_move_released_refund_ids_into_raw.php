<?php

use App\Modules\Billing\Models\Transaction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Refund rows released before #497 still hold the provider refund id in `gateway_txn_id`, so a later report that the
 * refund was paid is answered "duplicate" and booked nowhere (#499). As `RefundTransaction::releaseLocked()` does now,
 * the id moves to `raw.mohar.released_provider_refund_id` and `gateway_txn_id` is cleared. Only a release writes a
 * failed refund row, so every such row with an id qualifies.
 *
 * Data only, no DDL. Each row is its own short statement guarded by the id it read, so it never overwrites a change
 * made meanwhile, and a second run finds nothing to do. The previous release reads both shapes.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const BATCH = 500;

    public function up(): void
    {
        // Few rows qualify (drivers that answer ERROR with a refund id, then released); `type` leads the
        // transactions (type, occurred_at) index.
        DB::table('transactions')->where('type', 'refund')->where('status', 'failed')->whereNotNull('gateway_txn_id')
            ->select(['id', 'gateway_txn_id', 'raw'])->chunkById(self::BATCH, function ($rows): void {
                foreach ($rows as $row) {
                    $raw = is_string($row->raw) ? json_decode($row->raw, true) : null;
                    $raw = is_array($raw) ? $raw : [];
                    $state = is_array($raw[Transaction::REFUND_STATE] ?? null) ? $raw[Transaction::REFUND_STATE] : [];
                    $state[Transaction::RELEASED_REFUND_ID] = $row->gateway_txn_id;
                    $raw[Transaction::REFUND_STATE] = $state;

                    DB::table('transactions')->where('id', $row->id)->where('status', 'failed')->where('gateway_txn_id', $row->gateway_txn_id)
                        ->update(['gateway_txn_id' => null, 'raw' => json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
                }
            });
    }

    public function down(): void
    {
        // Not reversed: the previous release reads a released id from either place.
    }
};
