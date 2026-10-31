<?php

use App\Contracts\Catalog\PriceBook;
use App\Modules\Catalog\Models\Addon;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Models\Currency;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Catalog\FakeProvisioningDrivers;

beforeEach(function () {
    Currency::factory()->create();
});

it('stores driver settings encrypted at rest', function () {
    $product = Product::factory()->create(['driver_config' => ['plan' => 'starter-plan', 'api_token' => 'tok-12345']]);
    $addon = Addon::factory()->create(['driver_config' => ['password' => 'hunter2-secret']]);

    $raw = (string) DB::table('products')->where('id', $product->id)->value('driver_config');

    expect($raw)->not->toContain('tok-12345')->not->toContain('starter-plan')
        ->and((string) DB::table('addons')->where('id', $addon->id)->value('driver_config'))->not->toContain('hunter2-secret')
        ->and($product->fresh()->driver_config)->toBe(['plan' => 'starter-plan', 'api_token' => 'tok-12345'])
        ->and(app(PriceBook::class)->product($product->id)?->driverConfig)->toBe(['plan' => 'starter-plan', 'api_token' => 'tok-12345']);
});

it('never sends secret driver values to the browser', function () {
    actingAsStaff(['catalog.*']);
    FakeProvisioningDrivers::withLogDriver();
    $product = Product::factory()->create(['provisioning_driver' => 'log', 'driver_config' => ['plan' => 'starter', 'api_token' => 'tok-12345', 'db_password' => 'pw-999']]);
    $addon = Addon::factory()->create(['driver_config' => ['client_secret' => 'shh-777', 'region' => 'eu']]);

    $this->get(route('admin.catalog.products.edit', $product))
        ->assertDontSee('tok-12345')
        ->assertDontSee('pw-999')
        ->assertInertia(fn (Assert $page) => $page
            ->where('product.driver_config', ['plan' => 'starter', 'api_token' => '', 'db_password' => ''])
            ->where('product.driver_secret_keys', ['api_token', 'db_password']));

    $this->get(route('admin.catalog.addons.edit', $addon))
        ->assertDontSee('shh-777')
        ->assertInertia(fn (Assert $page) => $page
            ->where('addon.driver_config', ['client_secret' => '', 'region' => 'eu'])
            ->where('addon.driver_secret_keys', ['client_secret']));
});

it('keeps a stored secret when it is left empty and replaces it when a new value is entered', function () {
    actingAsStaff(['catalog.products.manage']);
    FakeProvisioningDrivers::withLogDriver();
    $product = Product::factory()->create(['provisioning_driver' => 'log', 'driver_config' => ['plan' => 'starter', 'api_token' => 'tok-12345']]);
    $pairs = fn (string $token) => [['key' => 'plan', 'value' => 'pro'], ['key' => 'api_token', 'value' => $token]];

    $this->put(route('admin.catalog.products.provisioning.update', $product), ['provisioning_driver' => 'log', 'driver_config' => $pairs('')])->assertSessionHasNoErrors();
    expect($product->fresh()->driver_config)->toBe(['plan' => 'pro', 'api_token' => 'tok-12345']);

    confirmPassword();
    $this->put(route('admin.catalog.products.provisioning.update', $product), ['provisioning_driver' => 'log', 'driver_config' => $pairs('tok-new')])->assertSessionHasNoErrors();
    expect($product->fresh()->driver_config)->toBe(['plan' => 'pro', 'api_token' => 'tok-new']);
});

it('keeps a stored addon secret when it is left empty', function () {
    actingAsStaff(['catalog.addons.manage']);
    $addon = Addon::factory()->create(['driver_config' => ['password' => 'pw-1']]);

    $this->put(route('admin.catalog.addons.update', $addon), [
        'name' => $addon->name, 'kind' => 'feature', 'payment_type' => 'recurring', 'auto_setup' => 'on_payment', 'provisioning_driver' => 'none',
        'driver_config' => [['key' => 'password', 'value' => '']], 'product_ids' => [], 'prices' => [],
    ])->assertSessionHasNoErrors();

    expect($addon->fresh()->driver_config)->toBe(['password' => 'pw-1']);
});

/*
 * Security review 2026-10-29, L3: entering a secret value needs a recent password confirmation; saving with secrets
 * left blank (kept) or changing other settings does not.
 */
it('asks for step-up before a product provisioning secret is replaced, and keeps it out of the session', function () {
    actingAsStaff(['catalog.products.manage']);
    FakeProvisioningDrivers::withLogDriver();
    $product = Product::factory()->create(['provisioning_driver' => 'log', 'driver_config' => ['plan' => 'starter', 'api_token' => 'tok-12345']]);
    $save = fn (string $token) => $this->from(route('admin.catalog.products.edit', $product))->put(route('admin.catalog.products.provisioning.update', $product), [
        'provisioning_driver' => 'log', 'driver_config' => [['key' => 'plan', 'value' => 'pro'], ['key' => 'api_token', 'value' => $token]],
    ]);

    $save('tok-attacker')->assertRedirect(route('admin.catalog.products.edit', $product))->assertSessionHasErrors('password_confirmation');
    expect($product->fresh()->driver_config)->toBe(['plan' => 'starter', 'api_token' => 'tok-12345'])
        ->and(json_encode(session()->all()))->not->toContain('tok-attacker');

    $save('')->assertSessionHasNoErrors();
    expect($product->fresh()->driver_config)->toBe(['plan' => 'pro', 'api_token' => 'tok-12345']);
});

it('asks for step-up on the add-on form only when a secret value is entered', function () {
    actingAsStaff(['catalog.addons.manage']);
    $addon = Addon::factory()->create(['driver_config' => ['password' => 'pw-1']]);
    $save = fn (string $password) => $this->putJson(route('admin.catalog.addons.update', $addon), [
        'name' => 'Renamed', 'kind' => 'feature', 'payment_type' => 'recurring', 'auto_setup' => 'on_payment', 'provisioning_driver' => 'none',
        'driver_config' => [['key' => 'password', 'value' => $password]], 'product_ids' => [], 'prices' => [],
    ]);

    $save('pw-attacker')->assertUnprocessable()->assertJsonValidationErrors('password_confirmation');
    expect($addon->fresh()->driver_config)->toBe(['password' => 'pw-1']);

    $save('')->assertSessionHasNoErrors();
    expect($addon->fresh()->name)->toBe('Renamed');

    confirmPassword();
    $save('pw-2')->assertSessionHasNoErrors();
    expect($addon->fresh()->driver_config)->toBe(['password' => 'pw-2']);
});
