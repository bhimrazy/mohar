<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Set manually, don't auto-convert": price conversion from the default currency skips these rows. When
 * automatic conversion was off, existing prices in other currencies were entered by staff, so they are marked
 * manual and the first conversion leaves them alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            $table->boolean('is_manual')->default(false)->after('setup_fee');
        });

        $default = DB::table('currencies')->where('is_default', true)->value('code');
        $setting = DB::table('settings')->where('key', 'catalog.auto_convert_prices')->value('value');
        $autoConvert = filter_var(is_string($setting) ? json_decode($setting, true) : false, FILTER_VALIDATE_BOOLEAN);

        if (! $autoConvert) {
            DB::table('prices')
                ->when($default !== null, fn ($query) => $query->where('currency', '!=', $default))
                ->update(['is_manual' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            $table->dropColumn('is_manual');
        });
    }
};
