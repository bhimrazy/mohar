<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Price conversion support for TLD prices:
 * - is_manual: "set manually, don't auto-convert". There was no TLD conversion before, so every existing row in a
 *   non-default currency was entered by staff and is marked manual: the first conversion never overwrites it.
 * - enabled: converted rows whose default-currency price is removed are turned off (not deleted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tld_prices', function (Blueprint $table) {
            $table->boolean('is_manual')->default(false)->after('renew');
            $table->boolean('enabled')->default(true)->after('is_manual');
        });

        $default = DB::table('currencies')->where('is_default', true)->value('code');

        DB::table('tld_prices')
            ->when($default !== null, fn ($query) => $query->where('currency', '!=', $default))
            ->update(['is_manual' => true]);
    }

    public function down(): void
    {
        Schema::table('tld_prices', function (Blueprint $table) {
            $table->dropColumn(['is_manual', 'enabled']);
        });
    }
};
