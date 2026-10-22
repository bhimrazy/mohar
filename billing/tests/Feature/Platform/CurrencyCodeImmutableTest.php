<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    actingAsStaff(['core.currencies.manage', 'core.currencies.rates']);
    Currency::factory()->create();
    $this->npr = Currency::factory()->npr()->create();
});

function nprDisplay(array $overrides = []): array
{
    return [...['name' => 'Nepali Rupee', 'prefix' => 'Rs ', 'decimals' => 2, 'format' => '1,23,456.78'], ...$overrides];
}

test('an update that changes the code is refused and nothing is saved', function () {
    $client = Client::factory()->create(['currency' => 'NPR']);

    $this->from(route('admin.platform.currencies.edit', $this->npr))
        ->put(route('admin.platform.currencies.update', $this->npr), nprDisplay(['code' => 'NRS']))
        ->assertRedirect(route('admin.platform.currencies.edit', $this->npr))
        ->assertSessionHasErrors(['code' => 'The code of an existing currency cannot be changed. To use another code, add a new currency.']);

    expect($this->npr->fresh())->code->toBe('NPR')->name->toBe('Nepalese Rupee')
        ->and(Currency::query()->where('code', $client->fresh()->currency)->exists())->toBeTrue();
});

test('an update that repeats the code, in any case, or omits it saves the display settings', function (array $code) {
    $this->put(route('admin.platform.currencies.update', $this->npr), nprDisplay($code))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.platform.currencies.edit', $this->npr));

    expect($this->npr->fresh())->code->toBe('NPR')->name->toBe('Nepali Rupee');
})->with([
    'same code' => [['code' => 'NPR']],
    'lower case' => [['code' => 'npr']],
    'omitted' => [[]],
]);

test('the edit page shows the code read-only with the reason; the create page keeps it editable', function () {
    $this->get(route('admin.platform.currencies.edit', $this->npr))
        ->assertInertia(fn (Assert $page) => $page->component('admin/platform/currencies/form')
            ->where('currency.code', 'NPR')
            ->where('codeLockedReason', 'Clients, invoices, prices and rate history refer to this code, so it cannot be changed. To use another code, add a new currency.'));

    confirmPassword();
    $this->get(route('admin.platform.currencies.create'))
        ->assertInertia(fn (Assert $page) => $page->component('admin/platform/currencies/form')->missing('currency')->missing('codeLockedReason'));
});

test('creating a currency still requires a new three-letter code', function () {
    confirmPassword();
    $payload = ['name' => 'Indian Rupee', 'prefix' => '₹', 'decimals' => 2, 'format' => '1,23,456.78', 'exchange_rate' => '0.625'];

    $this->post(route('admin.platform.currencies.store'), $payload)->assertSessionHasErrors('code');
    $this->post(route('admin.platform.currencies.store'), [...$payload, 'code' => 'npr'])->assertSessionHasErrors('code');
    $this->post(route('admin.platform.currencies.store'), [...$payload, 'code' => 'INRS'])->assertSessionHasErrors('code');
    $this->post(route('admin.platform.currencies.store'), [...$payload, 'code' => 'inr'])->assertSessionHasNoErrors();

    expect(Currency::query()->where('code', 'INR')->exists())->toBeTrue();
});

test('the model refuses to rename a saved currency from any other path', function () {
    expect(fn () => $this->npr->update(['code' => 'NRS']))->toThrow(LogicException::class, 'Currency code NPR cannot be changed.');

    expect($this->npr->fresh()->code)->toBe('NPR');
});
