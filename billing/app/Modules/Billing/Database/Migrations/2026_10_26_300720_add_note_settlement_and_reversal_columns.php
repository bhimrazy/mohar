<?php

use App\Modules\Core\Support\LockTimeout;
use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expand only (VAT PR 2, vat-register §4.4, §5.5): the account credit a note posts or takes back carries the note's id,
 * and a reversing debit note names the credit note it reverses. Both nullable. One reversal per credit note is a unique
 * index. `transactions.tax_note_id` comes with refund settlement (PR 4).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        LockTimeout::run('credit_ledger', function (): void {
            if (! Schema::hasColumn('credit_ledger', 'tax_note_id')) {
                Schema::table('credit_ledger', fn (Blueprint $table) => $table->foreignId('tax_note_id')->nullable()->constrained('tax_notes')->restrictOnDelete());
            }
        });
        OnlineIndex::create('credit_ledger', ['tax_note_id'], 'credit_ledger_tax_note_id_set_index', where: 'tax_note_id is not null');

        LockTimeout::run('tax_notes', function (): void {
            if (! Schema::hasColumn('tax_notes', 'reverses_tax_note_id')) {
                Schema::table('tax_notes', function (Blueprint $table): void {
                    $table->foreignId('reverses_tax_note_id')->nullable()->unique()->constrained('tax_notes')->restrictOnDelete();
                });
            }
        });
    }

    public function down(): void
    {
        // Checked first, so a refused rollback leaves this migration and the earlier note migrations as they are.
        if (DB::table('tax_notes')->exists()) {
            throw new RuntimeException('Rolling back would drop which account credit each note posted and which note a debit note reverses, while notes exist. Restore a backup instead.');
        }

        OnlineIndex::drop('credit_ledger', 'credit_ledger_tax_note_id_set_index');

        LockTimeout::run('credit_ledger', function (): void {
            if (Schema::hasColumn('credit_ledger', 'tax_note_id')) {
                Schema::table('credit_ledger', fn (Blueprint $table) => $table->dropConstrainedForeignId('tax_note_id'));
            }
        });

        LockTimeout::run('tax_notes', function (): void {
            if (Schema::hasColumn('tax_notes', 'reverses_tax_note_id')) {
                Schema::table('tax_notes', function (Blueprint $table): void {
                    $table->dropUnique(['reverses_tax_note_id']);
                    $table->dropConstrainedForeignId('reverses_tax_note_id');
                });
            }
        });
    }
};
