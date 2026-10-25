<?php

use App\Modules\Catalog\Models\ProductGroup;
use App\Modules\Core\Models\ActivityLog;

test('groups saved with a slug the store now reserves are renamed once, each rename logged', function () {
    $domains = ProductGroup::factory()->create(['slug' => 'domains']);
    $cart = ProductGroup::factory()->create(['slug' => 'cart']);
    ProductGroup::factory()->create(['slug' => 'domains-plans']);
    $hosting = ProductGroup::factory()->create(['slug' => 'hosting']);
    $migration = require base_path('app/Modules/Catalog/Database/Migrations/2026_10_25_100600_rename_reserved_product_group_slugs.php');

    $migration->up();
    $migration->up();

    expect($domains->fresh()->slug)->toBe('domains-plans-2')
        ->and($cart->fresh()->slug)->toBe('cart-plans')
        ->and($hosting->fresh()->slug)->toBe('hosting')
        ->and(ActivityLog::query()->where('event', 'product_group.slug_renamed')->orderBy('id')->pluck('properties')->all())
        ->toBe([['from' => 'domains', 'to' => 'domains-plans-2'], ['from' => 'cart', 'to' => 'cart-plans']]);
});
