<?php

use App\Modules\Core\Support\LockTimeout;
use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A product slug is unique among products that are not deleted (database review 2026-10-31, L2), so a deleted
 * product no longer blocks its slug; services and domains already scope their uniques this way. The partial unique
 * is built before the full one is dropped, so slugs are never unenforced. On PostgreSQL the full one is a constraint.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const LIVE = 'products_slug_live_unique';

    public function up(): void
    {
        // @compatible the previous release keeps every slug unique, so no live pair can violate the narrower index.
        OnlineIndex::create('products', ['slug'], self::LIVE, unique: true, where: 'deleted_at is null');

        LockTimeout::run('products', function (): void {
            if (Schema::hasIndex('products', 'products_slug_unique')) {
                Schema::table('products', fn (Blueprint $table) => $table->dropUnique(['slug']));
            }
        });
    }

    public function down(): void
    {
        if (DB::table('products')->select('slug')->groupBy('slug')->havingRaw('count(*) > 1')->exists()) {
            throw new RuntimeException('A deleted product shares its slug with another product; rename one before rolling back.');
        }

        LockTimeout::run('products', function (): void {
            if (! Schema::hasIndex('products', 'products_slug_unique')) {
                Schema::table('products', fn (Blueprint $table) => $table->unique('slug'));
            }
        });

        OnlineIndex::drop('products', self::LIVE, unique: true);
    }
};
