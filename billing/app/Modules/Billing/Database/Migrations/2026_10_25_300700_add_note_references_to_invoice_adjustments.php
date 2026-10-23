<?php

use App\Modules\Core\Support\LockTimeout;
use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expand only (VAT PR 1b, vat-register §4.4): the value adjustment a note writes carries the note's id, and
 * `invoice_item_id` names the line an adjustment belongs to. Nullable; almost every row is null, so the indexes are
 * partial, built CONCURRENTLY on PostgreSQL. The same column on `transactions` and `credit_ledger` comes with settlement.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const COLUMNS = ['tax_note_id' => 'tax_notes', 'invoice_item_id' => 'invoice_items'];

    public function up(): void
    {
        LockTimeout::run('invoice_adjustments', function (): void {
            foreach (self::COLUMNS as $column => $references) {
                if (! Schema::hasColumn('invoice_adjustments', $column)) {
                    Schema::table('invoice_adjustments', fn (Blueprint $table) => $table->foreignId($column)->nullable()->constrained($references)->restrictOnDelete());
                }
            }
        });

        foreach (array_keys(self::COLUMNS) as $column) {
            OnlineIndex::create('invoice_adjustments', [$column], "invoice_adjustments_{$column}_set_index", where: "{$column} is not null");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::COLUMNS) as $column) {
            OnlineIndex::drop('invoice_adjustments', "invoice_adjustments_{$column}_set_index");
        }

        LockTimeout::run('invoice_adjustments', function (): void {
            foreach (array_keys(self::COLUMNS) as $column) {
                if (Schema::hasColumn('invoice_adjustments', $column)) {
                    Schema::table('invoice_adjustments', fn (Blueprint $table) => $table->dropConstrainedForeignId($column));
                }
            }
        });
    }
};
