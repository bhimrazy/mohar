<?php

use App\Modules\Core\Support\LockTimeout;
use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expand only (VAT PR 4, ADR 0024 §5): the credit note a refund row became, set when the provider has paid and the
 * reservation is finalised. Nullable, so the previous release keeps working. Nothing is backfilled.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        LockTimeout::run('transactions', function (): void {
            if (! Schema::hasColumn('transactions', 'tax_note_id')) {
                Schema::table('transactions', fn (Blueprint $table) => $table->foreignId('tax_note_id')->nullable()->constrained('tax_notes')->restrictOnDelete());
            }
        });
        OnlineIndex::create('transactions', ['tax_note_id'], 'transactions_tax_note_id_set_index', where: 'tax_note_id is not null');
    }

    public function down(): void
    {
        if (DB::table('tax_notes')->exists()) {
            throw new RuntimeException('Rolling back would drop which note each refund became, while notes exist. Restore a backup instead.');
        }

        OnlineIndex::drop('transactions', 'transactions_tax_note_id_set_index');

        LockTimeout::run('transactions', function (): void {
            if (Schema::hasColumn('transactions', 'tax_note_id')) {
                Schema::table('transactions', fn (Blueprint $table) => $table->dropConstrainedForeignId('tax_note_id'));
            }
        });
    }
};
