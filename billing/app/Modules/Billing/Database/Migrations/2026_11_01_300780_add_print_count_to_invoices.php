<?php

use App\Modules\Core\Support\LockTimeout;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expand only (VAT PR 5, vat-register §9): how many times a tax invoice's PDF was downloaded while Nepal VAT mode was on,
 * so the second and later copies say so. Defaults to 0, so the previous release keeps working.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        LockTimeout::run('invoices', function (): void {
            if (! Schema::hasColumn('invoices', 'print_count')) {
                Schema::table('invoices', fn (Blueprint $table) => $table->unsignedInteger('print_count')->default(0));
            }
        });
    }

    public function down(): void
    {
        LockTimeout::run('invoices', function (): void {
            if (Schema::hasColumn('invoices', 'print_count')) {
                Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('print_count'));
            }
        });
    }
};
