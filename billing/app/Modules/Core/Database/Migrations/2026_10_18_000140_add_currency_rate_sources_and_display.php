<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-currency rate source and price-conversion rules, plus the new display defaults: Indian lakh grouping
 * for INR/NPR and "Rs " for NPR. Display rows are only changed where they still hold the old seed values, so
 * staff customisations survive. Exchange rates widen to decimal(24,12) so a weak default currency (NPR, IDR)
 * keeps enough significant digits; widening keeps every stored value. Rolling back rounds to six decimals.
 */
return new class extends Migration
{
    public function up(): void
    {
        self::withoutSingleDefaultIndex(function (): void {
            Schema::table('currencies', function (Blueprint $table) {
                $table->decimal('exchange_rate', 24, 12)->default(1)->change();
            });

            Schema::table('currencies', function (Blueprint $table) {
                $table->string('rate_source', 30)->default('manual')->after('exchange_rate');
                $table->timestamp('rate_checked_at')->nullable()->after('rate_updated_at');
                $table->string('price_rounding', 20)->default('none');
                $table->decimal('price_buffer_percent', 5, 2)->default(0);
            });
        });

        DB::table('currencies')->whereIn('code', ['INR', 'NPR'])->where('format', '1,234.56')->update(['format' => '1,23,456.78']);
        DB::table('currencies')->where('code', 'NPR')->where('prefix', 'रू ')->update(['prefix' => 'Rs ']);

        // Every currency starts on the manual source, so the legacy flag must agree with it.
        DB::table('currencies')->update(['is_auto_rate' => false]);
        Cache::forget('core.currency_displays.v1');
    }

    public function down(): void
    {
        DB::table('currencies')->where('code', 'NPR')->where('prefix', 'Rs ')->update(['prefix' => 'रू ']);
        DB::table('currencies')->whereIn('code', ['INR', 'NPR'])->where('format', '1,23,456.78')->update(['format' => '1,234.56']);

        self::withoutSingleDefaultIndex(function (): void {
            Schema::table('currencies', function (Blueprint $table) {
                $table->dropColumn(['rate_source', 'rate_checked_at', 'price_rounding', 'price_buffer_percent']);
            });

            Schema::table('currencies', function (Blueprint $table) {
                $table->decimal('exchange_rate', 16, 6)->default(1)->change();
            });
        });
    }

    /**
     * On SQLite a column change or drop rebuilds the table and re-creates its indexes without their WHERE clause, so
     * the partial "one default currency" index (2026_10_18_000150) would become a plain unique index on is_default
     * (failing as soon as two currencies are not the default). When that index exists (this migration runs after
     * it), drop it around the schema change and build it again as the partial index. The table is tiny, so a
     * locking build is fine.
     */
    private static function withoutSingleDefaultIndex(Closure $change): void
    {
        $index = Schema::hasIndex('currencies', 'currencies_single_default_unique');

        if ($index) {
            OnlineIndex::drop('currencies', 'currencies_single_default_unique', allowLocking: true);
        }

        $change();

        if ($index) {
            OnlineIndex::create('currencies', ['is_default'], 'currencies_single_default_unique', unique: true, where: 'is_default = true', allowLocking: true);
        }
    }
};
