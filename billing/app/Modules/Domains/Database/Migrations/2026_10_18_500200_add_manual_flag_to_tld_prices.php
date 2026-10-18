<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Set manually — don't auto-convert" for TLD price rows in non-default currencies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tld_prices', function (Blueprint $table) {
            $table->boolean('is_manual')->default(false)->after('renew');
        });
    }

    public function down(): void
    {
        Schema::table('tld_prices', function (Blueprint $table) {
            $table->dropColumn('is_manual');
        });
    }
};
