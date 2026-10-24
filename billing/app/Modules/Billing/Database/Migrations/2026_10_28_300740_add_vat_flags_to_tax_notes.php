<?php

use App\Modules\Core\Support\LockTimeout;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expand only: which tax levels of a note are Nepal VAT, copied from the invoice when the note is issued (ADR 0024,
 * Amendment #432). NULL means the invoice was not classified; the register then keeps the note's tax out of VAT and
 * warns. Nothing is backfilled here.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        LockTimeout::run('tax_notes', function (): void {
            if (Schema::hasColumn('tax_notes', 'tax1_is_vat')) {
                return;
            }

            Schema::table('tax_notes', function (Blueprint $table): void {
                $table->boolean('tax1_is_vat')->nullable();
                $table->boolean('tax2_is_vat')->nullable();
            });
        });
    }

    public function down(): void
    {
        LockTimeout::run('tax_notes', function (): void {
            if (! Schema::hasColumn('tax_notes', 'tax1_is_vat')) {
                return;
            }

            Schema::table('tax_notes', function (Blueprint $table): void {
                $table->dropColumn(['tax1_is_vat', 'tax2_is_vat']);
            });
        });
    }
};
