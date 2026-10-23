<?php

use App\Modules\Core\Support\LockTimeout;
use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expand only (VAT PR 3): which tax rules an invoice used, and which of its two tax levels are Nepal VAT. All columns
 * are nullable, so the previous release keeps working. Invoices numbered before this keep NULL flags until
 * `billing:fiscal-vat-split` fills them; that is not done here (architecture rule 1).
 *
 * The partial index lists taxed, numbered invoices without a classification in id order. It serves that command and
 * the enable check, and holds nothing once the command has run. Built CONCURRENTLY on PostgreSQL.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public const INDEX = 'invoices_vat_split_pending_index';

    public function up(): void
    {
        LockTimeout::run('invoices', function (): void {
            if (Schema::hasColumn('invoices', 'tax1_rule_id')) {
                return;
            }

            Schema::table('invoices', function (Blueprint $table): void {
                $table->unsignedBigInteger('tax1_rule_id')->nullable();
                $table->unsignedBigInteger('tax2_rule_id')->nullable();
                $table->boolean('fiscal_tax1_is_vat')->nullable();
                $table->boolean('fiscal_tax2_is_vat')->nullable();
            });
        });

        OnlineIndex::create('invoices', ['id'], self::INDEX, where: "numbered_at is not null and fiscal_tax1_is_vat is null and kind <> 'add_funds' and (tax1_amount <> 0 or tax2_amount <> 0)");
    }

    public function down(): void
    {
        OnlineIndex::drop('invoices', self::INDEX);

        LockTimeout::run('invoices', function (): void {
            if (! Schema::hasColumn('invoices', 'tax1_rule_id')) {
                return;
            }

            Schema::table('invoices', function (Blueprint $table): void {
                $table->dropColumn(['tax1_rule_id', 'tax2_rule_id', 'fiscal_tax1_is_vat', 'fiscal_tax2_is_vat']);
            });
        });
    }
};
