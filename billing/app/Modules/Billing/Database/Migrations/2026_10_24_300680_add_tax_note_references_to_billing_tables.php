<?php

use App\Modules\Core\Support\LockTimeout;
use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expand only (VAT PR 1a, vat-register §4.4): the rows a note writes carry its id, so settlement is read from them and
 * never from a field on the note. `invoice_adjustments.invoice_item_id` names the line a value adjustment belongs to.
 * All columns are nullable. Each index is partial (`where … is not null`), because almost every existing row is null,
 * and is built CONCURRENTLY on PostgreSQL.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** table => [column => referenced table] */
    private const COLUMNS = [
        'invoice_adjustments' => ['tax_note_id' => 'tax_notes', 'invoice_item_id' => 'invoice_items'],
        'transactions' => ['tax_note_id' => 'tax_notes'],
        'credit_ledger' => ['tax_note_id' => 'tax_notes'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            LockTimeout::run($table, function () use ($table, $columns): void {
                foreach ($columns as $column => $references) {
                    if (Schema::hasColumn($table, $column)) {
                        continue;
                    }

                    Schema::table($table, function (Blueprint $blueprint) use ($column, $references): void {
                        $blueprint->foreignId($column)->nullable()->constrained($references)->restrictOnDelete();
                    });
                }
            });

            foreach (array_keys($columns) as $column) {
                OnlineIndex::create($table, [$column], "{$table}_{$column}_set_index", where: "{$column} is not null");
            }
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach (array_keys($columns) as $column) {
                OnlineIndex::drop($table, "{$table}_{$column}_set_index");
            }

            LockTimeout::run($table, function () use ($table, $columns): void {
                foreach (array_keys($columns) as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                            $blueprint->dropConstrainedForeignId($column);
                        });
                    }
                }
            });
        }
    }
};
