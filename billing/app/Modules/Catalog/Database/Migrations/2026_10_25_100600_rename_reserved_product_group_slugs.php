<?php

use App\Modules\Catalog\Models\ProductGroup;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The store now owns /store/<word> for these words, so a group saved with one of them before has product URLs that
 * no route matches. Rename it to "<slug>-plans" (then "-plans-2", ...), log each rename, and skip groups already
 * renamed: running it again changes nothing. The list is a copy, so a later change to the reserved words does not
 * rewrite this step.
 */
return new class extends Migration
{
    private const RESERVED = ['cart', 'checkout', 'domains', 'currency', 'orders', 'products'];

    public function up(): void
    {
        foreach (ProductGroup::query()->whereIn('slug', self::RESERVED)->orderBy('id')->get() as $group) {
            $old = $group->slug;
            $slug = $old.'-plans';

            for ($n = 2; DB::table('product_groups')->where('slug', $slug)->exists(); $n++) {
                $slug = "{$old}-plans-{$n}";
            }

            $group->forceFill(['slug' => $slug])->save();
            app(ActivityLogger::class)->log(
                "Product group slug \"{$old}\" renamed to \"{$slug}\": the store reserves /store/{$old}",
                $group,
                properties: ['from' => $old, 'to' => $slug],
                event: 'product_group.slug_renamed',
            );
        }
    }

    public function down(): void
    {
        // Renamed slugs are kept: the old ones collide with store routes.
    }
};
