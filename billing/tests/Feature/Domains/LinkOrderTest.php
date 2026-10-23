<?php

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarCommandLog;
use App\Support\Domains\RegistrarException;
use Tests\Fakes\Domains\DomainFixtures;
use Tests\Fakes\Domains\FakeRegistrar;

/**
 * Staff link an order that already exists at the registrar to a domain the host holds without an order id (#338).
 * The ResellerClub rules are tested against the simulator (tools/simulators/tests/ResellerClub/LinkOrderTest.php);
 * here the host: permission, step-up, one order for one domain, the audit entry, idempotence and the sync after.
 */
beforeEach(function () {
    $this->registrar = FakeRegistrar::install();
    $this->registrar->capabilities[] = 'link_order';
    $this->com = DomainFixtures::com();
    $this->imported = fn (array $attributes = []): Domain => Domain::factory()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake', 'status' => DomainStatus::Active, ...$attributes]);
});

test('linking records the order id, writes the activity log and syncs the domain once', function () {
    actingAsStaff(['domains.*']);
    confirmPassword();
    $domain = ($this->imported)();

    $this->post(route('admin.domains.link-order', $domain), ['order_id' => ' 80000001 '])->assertRedirect(route('admin.domains.show', $domain));

    expect($domain->fresh()->registrar_meta['remote']['order_id'])->toBe(80000001)
        ->and($this->registrar->commands())->toBe(['link_order', 'sync'])
        ->and(ActivityLog::query()->where('event', 'domain.order_linked')->where('subject_id', $domain->id)->count())->toBe(1)
        ->and(ActivityLog::query()->where('event', 'domain.order_linked')->value('properties'))->toMatchArray(['order_id' => '80000001'])
        ->and(RegistrarCommandLog::query()->where('command', 'link_order')->value('staff_id'))->not->toBeNull();
});

test('linking the same order again changes nothing, adds no second entry, and syncs', function () {
    actingAsStaff(['domains.*']);
    confirmPassword();
    $domain = ($this->imported)();

    $this->post(route('admin.domains.link-order', $domain), ['order_id' => '80000001'])->assertSessionHasNoErrors();
    $this->post(route('admin.domains.link-order', $domain), ['order_id' => '80000001'])->assertSessionHasNoErrors();

    expect($this->registrar->commands())->toBe(['link_order', 'sync', 'sync'])
        ->and(ActivityLog::query()->where('event', 'domain.order_linked')->count())->toBe(1)
        ->and($domain->fresh()->registrar_meta['remote']['order_id'])->toBe(80000001);
});

test('a domain linked to one order is not moved to another', function () {
    actingAsStaff(['domains.*']);
    confirmPassword();
    $domain = ($this->imported)(['registrar_meta' => ['remote' => ['order_id' => 80000001]]]);

    $this->post(route('admin.domains.link-order', $domain), ['order_id' => '80000002'])
        ->assertSessionHasErrors(['order_id' => "{$domain->name} is already linked to order 80000001. Linking another order is not offered here; check the order in the registrar's panel."]);

    expect($this->registrar->calls)->toBe([])->and($domain->fresh()->registrar_meta['remote']['order_id'])->toBe(80000001);
});

test('an order another domain holds is refused, including a deleted record', function (bool $deleted, mixed $stored) {
    actingAsStaff(['domains.*']);
    confirmPassword();
    $holder = ($this->imported)(['registrar_meta' => ['remote' => ['order_id' => $stored]]]);
    $domain = ($this->imported)();
    $deleted && $holder->delete();

    $this->post(route('admin.domains.link-order', $domain), ['order_id' => '80000001'])
        ->assertSessionHasErrors(['order_id' => "Order 80000001 is already linked to {$holder->name}. One order belongs to one domain; nothing was changed."]);

    expect($this->registrar->calls)->toBe([])
        ->and($domain->fresh()->registrar_meta)->toBeNull()
        ->and(ActivityLog::query()->where('event', 'domain.order_linked')->exists())->toBeFalse();
})->with([
    'live, stored as a number' => [false, 80000001],
    'live, stored as text' => [false, '80000001'],
    'deleted' => [true, 80000001],
]);

test('the registrar refusing the order records nothing and shows its reason under the field', function () {
    actingAsStaff(['domains.*']);
    confirmPassword();
    $domain = ($this->imported)();
    $this->registrar->will('link_order', new RegistrarException('Order 80000009 is for other.com, not '.$domain->name.'. The name must match exactly. Nothing was linked.'));

    $this->post(route('admin.domains.link-order', $domain), ['order_id' => '80000009'])
        ->assertSessionHasErrors('order_id');

    expect($this->registrar->commands())->toBe(['link_order'])
        ->and($domain->fresh()->registrar_meta)->toBeNull()
        ->and(RegistrarCommandLog::query()->where('command', 'link_order')->value('success'))->toBeFalse();
});

test('staff need the update and commands permissions and a recent password', function () {
    $domain = ($this->imported)();

    actingAsStaff(['domains.*']);
    $this->post(route('admin.domains.link-order', $domain), ['order_id' => '80000001'])->assertSessionHasErrors('password_confirmation');

    confirmPassword();
    actingAsStaff(['domains.domains.view', 'domains.domains.commands']);
    $this->post(route('admin.domains.link-order', $domain), ['order_id' => '80000001'])->assertForbidden();

    actingAsStaff(['domains.domains.view', 'domains.domains.update']);
    $this->post(route('admin.domains.link-order', $domain), ['order_id' => '80000001'])->assertForbidden();

    expect($this->registrar->calls)->toBe([])->and($domain->fresh()->registrar_meta)->toBeNull();
});

test('only a live domain at a registrar that supports linking can be linked, with a valid id', function () {
    actingAsStaff(['domains.*']);
    confirmPassword();

    $pending = Domain::factory()->pending()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);
    $this->post(route('admin.domains.link-order', $pending), ['order_id' => '80000001'])->assertSessionHasErrors('order_id');

    $manual = ($this->imported)(['registrar_key' => null]);
    $this->post(route('admin.domains.link-order', $manual), ['order_id' => '80000001'])->assertSessionHasErrors('order_id');

    $this->registrar->capabilities = array_values(array_diff($this->registrar->capabilities, ['link_order']));
    $plain = ($this->imported)();
    $this->post(route('admin.domains.link-order', $plain), ['order_id' => '80000001'])
        ->assertSessionHasErrors(['order_id' => 'This registrar does not support linking an existing order.']);

    $this->registrar->capabilities[] = 'link_order';
    $this->post(route('admin.domains.link-order', ($this->imported)()), ['order_id' => 'abc'])
        ->assertSessionHasErrors(['order_id' => "Enter the order id as digits only, as shown in the registrar's panel."]);

    expect($this->registrar->calls)->toBe([]);
});

test('the detail page offers the link only for an unlinked live domain, and shows the linked order', function () {
    actingAsStaff(['domains.*']);
    $domain = ($this->imported)();

    $this->get(route('admin.domains.show', $domain))->assertInertia(fn ($page) => $page
        ->where('capabilities', fn ($capabilities) => collect($capabilities)->contains('link_order'))
        ->where('domain.registrar_order_id', null));

    $domain->forceFill(['registrar_meta' => ['remote' => ['order_id' => 80000001]]])->save();

    $this->get(route('admin.domains.show', $domain))->assertInertia(fn ($page) => $page->where('domain.registrar_order_id', 80000001));
});
