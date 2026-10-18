<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientGroup;
use App\Modules\Core\Models\Currency;
use Inertia\Testing\AssertableInertia as Assert;

test('client groups require core.client_groups.manage', function () {
    actingAsStaff(['core.clients.*']);

    $this->get(route('admin.platform.client-groups.index'))->assertForbidden();
});

test('a client group can be created and edited', function () {
    actingAsStaff(['core.client_groups.manage']);

    $this->post(route('admin.platform.client-groups.store'), ['name' => 'Resellers', 'colour' => '#3B82F6', 'discount_percent' => '12.5', 'exempt_from_suspension' => true, 'separate_invoices' => false])
        ->assertRedirect(route('admin.platform.client-groups.index'));

    $group = ClientGroup::query()->sole();
    expect($group)->colour->toBe('#3b82f6')->discount_percent->toBe('12.50')->exempt_from_suspension->toBeTrue();

    $this->get(route('admin.platform.client-groups.index'))
        ->assertInertia(fn (Assert $page) => $page->component('admin/platform/client-groups/index')->where('groups.0.name', 'Resellers')->where('groups.0.clients_count', 0));

    $this->put(route('admin.platform.client-groups.update', $group), ['name' => 'VIP', 'discount_percent' => 0])->assertRedirect();
    expect($group->fresh())->name->toBe('VIP')->discount_percent->toBe('0.00')->exempt_from_suspension->toBeFalse();
});

test('discount must be between 0 and 100 and colour a hex value', function (mixed $discount) {
    actingAsStaff(['core.client_groups.manage']);

    $this->post(route('admin.platform.client-groups.store'), ['name' => 'Bad', 'colour' => 'blue', 'discount_percent' => $discount])
        ->assertSessionHasErrors(['discount_percent', 'colour']);
})->with([-1, 100.01, 'ten', 5.555]);

test('a group with clients cannot be deleted', function () {
    actingAsStaff(['core.client_groups.manage']);
    Currency::factory()->create();
    $used = ClientGroup::query()->create(['name' => 'Used', 'discount_percent' => 0]);
    Client::factory()->create(['client_group_id' => $used->id]);
    $empty = ClientGroup::query()->create(['name' => 'Empty', 'discount_percent' => 0]);

    $this->delete(route('admin.platform.client-groups.destroy', $used))->assertSessionHasErrors('group');
    $this->delete(route('admin.platform.client-groups.destroy', $empty))->assertRedirect();

    expect(ClientGroup::query()->pluck('name')->all())->toBe(['Used']);
});
