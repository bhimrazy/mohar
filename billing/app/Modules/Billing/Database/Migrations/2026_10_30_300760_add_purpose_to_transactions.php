<?php

use App\Modules\Core\Support\LockTimeout;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expand only (VAT PR 4c, ADR 0024 §5a): what a refund row is for. Written only by RefundAccountCredit (`credit_refund`),
 * so a refund of account credit is never mistaken for a refund of an invoice. Nullable, so the previous release keeps
 * working; existing rows stay null.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        LockTimeout::run('transactions', function (): void {
            if (! Schema::hasColumn('transactions', 'purpose')) {
                Schema::table('transactions', fn (Blueprint $table) => $table->string('purpose', 30)->nullable());
            }
        });
    }

    public function down(): void
    {
        if (DB::table('transactions')->whereNotNull('purpose')->exists()) {
            throw new RuntimeException('Rolling back would lose which refunds paid out account credit. Restore a backup instead.');
        }

        LockTimeout::run('transactions', function (): void {
            if (Schema::hasColumn('transactions', 'purpose')) {
                Schema::table('transactions', fn (Blueprint $table) => $table->dropColumn('purpose'));
            }
        });
    }
};
