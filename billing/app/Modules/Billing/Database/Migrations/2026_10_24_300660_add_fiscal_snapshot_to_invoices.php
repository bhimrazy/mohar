<?php

use App\Modules\Core\Support\LockTimeout;
use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expand only (VAT PR 1a, ADR 0024 §2): the tax-invoice value snapshot an invoice stores when it gets its legal number,
 * and the moment of numbering. Every column is nullable or defaulted, so the previous release keeps working against
 * the new schema. The values of existing invoices are filled by the next migration, in chunks.
 *
 * The partial index lists the numbered invoices that still have no snapshot, in id order. It serves the backfill and
 * the "is anything left" check, and shrinks to nothing once the backfill is done. Built CONCURRENTLY on PostgreSQL.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public const INDEX = 'invoices_fiscal_pending_index';

    public function up(): void
    {
        LockTimeout::run('invoices', function (): void {
            if (Schema::hasColumn('invoices', 'numbered_at')) {
                return;
            }

            Schema::table('invoices', function (Blueprint $table): void {
                $table->timestamp('numbered_at')->nullable();
                $table->bigInteger('fiscal_taxable')->nullable();
                $table->bigInteger('fiscal_exempt')->nullable();
                $table->bigInteger('fiscal_tax1')->nullable();
                $table->bigInteger('fiscal_tax2')->nullable();
                $table->bigInteger('fiscal_total')->nullable();
                $table->char('report_currency', 3)->nullable();
                $table->decimal('report_rate', 24, 12)->nullable();
                $table->string('supply_class', 10)->nullable();
                $table->string('bs_issue_date', 10)->nullable();
                $table->string('bs_period', 7)->nullable();
                $table->string('bs_fiscal_year', 8)->nullable();
                $table->bigInteger('credited_total')->default(0);
                $table->bigInteger('debited_total')->default(0);
            });
        });

        OnlineIndex::create('invoices', ['id'], self::INDEX, where: "number is not null and numbered_at is null and kind <> 'add_funds'");
    }

    public function down(): void
    {
        OnlineIndex::drop('invoices', self::INDEX);

        LockTimeout::run('invoices', function (): void {
            if (! Schema::hasColumn('invoices', 'numbered_at')) {
                return;
            }

            Schema::table('invoices', function (Blueprint $table): void {
                $table->dropColumn(['numbered_at', 'fiscal_taxable', 'fiscal_exempt', 'fiscal_tax1', 'fiscal_tax2', 'fiscal_total',
                    'report_currency', 'report_rate', 'supply_class', 'bs_issue_date', 'bs_period', 'bs_fiscal_year',
                    'credited_total', 'debited_total']);
            });
        });
    }
};
