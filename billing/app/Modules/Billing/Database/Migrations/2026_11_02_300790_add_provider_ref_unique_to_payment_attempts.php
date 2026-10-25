<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Expand only (payments-gateways-expansion §9.1). A provider reference is unique per gateway, so a return that carries
 * only the provider's reference (connectIPS TXNID) finds exactly one attempt. NULLs stay distinct on PostgreSQL and
 * SQLite. Built CONCURRENTLY on PostgreSQL (no write lock).
 *
 * Every shipped driver's reference is a provider session id or a random draw, so pairs are not expected; any that
 * exist are resolved first. Within a pair the succeeded attempt (else the oldest) keeps the reference; the others
 * lose it, keep it in raw.duplicate_provider_ref, and an open one goes to staff review, because a lookup without it
 * cannot reach the provider.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const NAME = 'payment_attempts_gateway_provider_ref_unique';

    public function up(): void
    {
        $pairs = DB::table('payment_attempts')->whereNotNull('provider_ref')
            ->select('gateway', 'provider_ref')->groupBy('gateway', 'provider_ref')->havingRaw('count(*) > 1')
            ->orderBy('gateway')->orderBy('provider_ref')->get();

        foreach ($pairs as $pair) {
            DB::transaction(function () use ($pair): void {
                $attempts = DB::table('payment_attempts')->where('gateway', $pair->gateway)->where('provider_ref', $pair->provider_ref)
                    ->lockForUpdate()->orderBy('id')->get(['id', 'status', 'transaction_id', 'raw']);
                $keep = $attempts->first(fn (object $attempt): bool => $attempt->status === 'succeeded' || $attempt->transaction_id !== null) ?? $attempts->first();

                foreach ($attempts as $attempt) {
                    if ($attempt->id === $keep->id) {
                        continue;
                    }

                    $raw = is_string($attempt->raw) ? (json_decode($attempt->raw, true) ?: []) : [];
                    $open = in_array($attempt->status, ['pending', 'ambiguous'], true);

                    DB::table('payment_attempts')->where('id', $attempt->id)->update(array_filter([
                        'provider_ref' => null,
                        'raw' => json_encode([...(is_array($raw) ? $raw : []), 'duplicate_provider_ref' => $pair->provider_ref]),
                        'status' => $open ? 'ambiguous' : null,
                        'review_reason' => $open ? 'Another payment had the same provider reference. Check this one at the provider.' : null,
                    ], fn (mixed $value, string $column): bool => $column === 'provider_ref' || $value !== null, ARRAY_FILTER_USE_BOTH));
                }
            });
        }

        // @compatible the pairs above are resolved first, and the previous release stores provider session ids or random draws
        OnlineIndex::create('payment_attempts', ['gateway', 'provider_ref'], self::NAME, unique: true);
    }

    public function down(): void
    {
        OnlineIndex::drop('payment_attempts', self::NAME, unique: true);
    }
};
