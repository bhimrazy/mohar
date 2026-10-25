<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductGroup;
use Inertia\Testing\AssertableInertia as Assert;

it('redirects guests to the login page', function () {
    $this->get(route('admin.catalog.groups.index'))->assertRedirect(route('login'));
});

it('forbids staff without catalog permissions', function () {
    actingAsStaff(['core.clients.view']);

    $this->get(route('admin.catalog.groups.index'))->assertForbidden();
});

it('forbids staff who can only view from changing groups', function () {
    actingAsStaff(['catalog.products.view']);
    $group = ProductGroup::factory()->create();

    $this->get(route('admin.catalog.groups.index'))->assertOk();
    $this->post(route('admin.catalog.groups.store'), ['name' => 'X'])->assertForbidden();
    $this->delete(route('admin.catalog.groups.destroy', $group))->assertForbidden();
    $this->post(route('admin.catalog.groups.reorder'), ['ids' => [$group->id]])->assertForbidden();
});

it('lists groups in display order with product counts and gateway conflicts', function () {
    actingAsStaff(['catalog.products.view']);
    $second = ProductGroup::factory()->gateways(['paypal'])->create(['sort_order' => 2, 'name' => 'VPS']);
    $first = ProductGroup::factory()->gateways(['stripe'])->create(['sort_order' => 1, 'name' => 'Hosting']);
    ProductGroup::factory()->hidden()->gateways(['bank'])->create(['sort_order' => 3]);
    Product::factory()->count(2)->for($first, 'group')->create();

    $this->get(route('admin.catalog.groups.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/catalog/groups/index')
            ->has('groups', 3)
            ->where('groups.0.id', $first->id)
            ->where('groups.0.products_count', 2)
            ->where('groups.1.id', $second->id)
            ->where('gatewayConflicts', [['Hosting', 'VPS']])
            ->where('canManage', false));
});

it('creates a group with a generated slug and restricted gateways', function () {
    actingAsStaff(['catalog.products.manage']);

    $this->post(route('admin.catalog.groups.store'), [
        'name' => 'Shared Hosting',
        'slug' => '',
        'is_hidden' => false,
        'restrict_gateways' => true,
        'allowed_gateways' => 'stripe, bank_transfer',
    ])->assertRedirect(route('admin.catalog.groups.index'));

    $group = ProductGroup::query()->where('name', 'Shared Hosting')->firstOrFail();
    expect($group->slug)->toBe('shared-hosting')
        ->and($group->allowed_gateways)->toBe(['stripe', 'bank_transfer'])
        ->and($group->sort_order)->toBe(1);
});

it('warns when a saved group shares no gateway with another visible group', function () {
    actingAsStaff(['catalog.products.manage']);
    ProductGroup::factory()->gateways(['paypal'])->create(['name' => 'VPS']);

    $this->post(route('admin.catalog.groups.store'), ['name' => 'Hosting', 'restrict_gateways' => true, 'allowed_gateways' => 'stripe'])
        ->assertInertiaFlash('toast.type', 'warning');
});

it('validates the group slug', function (string $slug, string $error) {
    actingAsStaff(['catalog.products.manage']);
    ProductGroup::factory()->create(['slug' => 'taken']);

    $this->post(route('admin.catalog.groups.store'), ['name' => 'Group', 'slug' => $slug])
        ->assertSessionHasErrors(['slug' => $error]);
})->with([
    'no letter' => ['123', 'The slug must contain at least one letter.'],
    'reserved store word' => ['cart', 'This slug is reserved for the store (cart, checkout, domains, currency, orders, products). Enter a different slug.'],
    'duplicate' => ['taken', 'This slug is already used. Change the name or enter a different slug.'],
]);

it('requires gateways when restricting them', function () {
    actingAsStaff(['catalog.products.manage']);

    $this->post(route('admin.catalog.groups.store'), ['name' => 'Group', 'restrict_gateways' => true, 'allowed_gateways' => ''])
        ->assertSessionHasErrors(['allowed_gateways' => 'Enter at least one gateway, or allow every gateway.']);
});

it('updates a group and clears gateway restrictions', function () {
    actingAsStaff(['catalog.products.manage']);
    $group = ProductGroup::factory()->gateways(['stripe'])->create();

    $this->put(route('admin.catalog.groups.update', $group), ['name' => 'Renamed', 'slug' => $group->slug, 'is_hidden' => true, 'restrict_gateways' => false])
        ->assertRedirect(route('admin.catalog.groups.index'));

    expect($group->fresh())->name->toBe('Renamed')->is_hidden->toBeTrue()->allowed_gateways->toBeNull();
});

it('reorders groups', function () {
    actingAsStaff(['catalog.products.manage']);
    [$a, $b, $c] = ProductGroup::factory()->count(3)->sequence(['sort_order' => 1], ['sort_order' => 2], ['sort_order' => 3])->create();

    $this->post(route('admin.catalog.groups.reorder'), ['ids' => [$c->id, $a->id, $b->id]])->assertRedirect();

    expect(ProductGroup::query()->ordered()->pluck('id')->all())->toBe([$c->id, $a->id, $b->id]);
});

it('refuses to delete a group that still has products', function () {
    actingAsStaff(['catalog.products.manage']);
    $group = ProductGroup::factory()->create();
    Product::factory()->for($group, 'group')->create();

    $this->delete(route('admin.catalog.groups.destroy', $group))->assertSessionHasErrors('group');

    $this->assertModelExists($group);
});

it('deletes an empty group', function () {
    actingAsStaff(['catalog.products.manage']);
    $group = ProductGroup::factory()->create();

    $this->delete(route('admin.catalog.groups.destroy', $group))->assertRedirect(route('admin.catalog.groups.index'));

    $this->assertModelMissing($group);
});

it('keeps groups whose deleted products are still referenced by history', function () {
    actingAsStaff(['catalog.products.manage']);
    $group = ProductGroup::factory()->create();
    $product = Product::factory()->for($group, 'group')->create();
    $product->delete();

    $this->delete(route('admin.catalog.groups.destroy', $group))->assertSessionHasErrors('group');

    $this->assertModelExists($group);
    $this->assertSoftDeleted($product);
});

it('only reports the name when both name and slug are missing', function () {
    actingAsStaff(['catalog.products.manage']);

    $this->post(route('admin.catalog.groups.store'), ['name' => '', 'slug' => ''])
        ->assertSessionHasErrors('name')
        ->assertSessionDoesntHaveErrors('slug');
});
