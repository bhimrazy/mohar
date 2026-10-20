<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider reversals staff must review (#172): review_amount is the part of a chargeback or provider refund that
 * neither the payments nor the credit they placed could absorb (e.g. a dispute of a charge the provider had already
 * refunded); reviewed_at is when staff marked it reviewed. The open ones are the "Disputes to review"
 * to-do; negative credit balances are the "Clients owing account credit" to-do. Both counts read a partial index.
 * Nullable columns are a metadata-only change on PostgreSQL; the indexes are built CONCURRENTLY there.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->bigInteger('review_amount')->nullable();
            $table->timestamp('reviewed_at')->nullable();
        });

        OnlineIndex::create('transactions', ['id'], 'transactions_review_open_index', where: 'review_amount is not null and reviewed_at is null');
        OnlineIndex::create('credit_ledger', ['client_id', 'currency', 'id'], 'credit_ledger_negative_balance_index', where: 'balance_after < 0');
    }

    public function down(): void
    {
        OnlineIndex::drop('credit_ledger', 'credit_ledger_negative_balance_index');
        OnlineIndex::drop('transactions', 'transactions_review_open_index');

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['review_amount', 'reviewed_at']);
        });
    }
};
