<?php

use App\Contracts\Domains\Registrar\RegistrarDriver;
use App\Modules\Core\Models\ClientGroup;
use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Database\Seeders\DomainsSeeder;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Models\TldPrice;
use App\Support\Domains\RegistrarDriverRegistry;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Domains\DomainFixtures;

function tldPayload(array $overrides = []): array
{
    return [
        'extension' => '.dev', 'registrar_key' => 'log', 'auto_register' => true, 'epp_required' => true,
        'min_years' => 1, 'max_years' => 5, 'allow_register' => true, 'allow_transfer' => true,
        'grace_days' => 30, 'grace_fee' => 0, 'redemption_days' => 30, 'redemption_fee' => 8000, 'renew_window_days' => null,
        'addon_dns' => false, 'addon_id_protection' => true, 'addon_email_forwarding' => false,
        'is_spotlight' => false, 'badge' => null, 'sort_order' => 3, 'is_active' => true, ...$overrides,
    ];
}

beforeEach(function () {
    Currency::factory()->create();
});

test('TLD management needs the TLD permissions', function () {
    actingAsStaff(['domains.domains.view']);

    $this->get(route('admin.domains.tlds.index'))->assertForbidden();
    $this->post(route('admin.domains.tlds.store'), tldPayload())->assertForbidden();
});

test('staff list TLDs with their base prices', function () {
    actingAsStaff(['domains.tlds.view']);
    DomainFixtures::com('log');

    $this->get(route('admin.domains.tlds.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/domains/tlds/index')
            ->where('tlds.0.extension', '.com')
            ->where('tlds.0.price.register', 1200)
            ->where('registrarNames.log', 'Log (demo)')
            ->where('canManage', false));
});

test('staff create a TLD with a normalised extension', function () {
    actingAsStaff(['domains.tlds.*']);

    $this->post(route('admin.domains.tlds.store'), tldPayload(['extension' => ' .CO.UK. ']))->assertRedirect();

    $tld = Tld::query()->sole();
    expect($tld->extension)->toBe('.co.uk')->and($tld->redemption_fee)->toBe(8000);
    $this->get(route('admin.domains.tlds.edit', $tld))->assertInertia(fn (Assert $page) => $page->component('admin/domains/tlds/form')->where('tld.id', $tld->id));
});

test('TLD validation rejects duplicates and bad terms', function () {
    actingAsStaff(['domains.tlds.*']);
    DomainFixtures::com('log');

    $this->post(route('admin.domains.tlds.store'), tldPayload(['extension' => 'com', 'min_years' => 5, 'max_years' => 2, 'registrar_key' => 'nope']))
        ->assertSessionHasErrors(['extension', 'max_years', 'registrar_key']);
});

test('the pricing grid saves a client group slab without touching base prices', function () {
    actingAsStaff(['domains.tlds.*']);
    $tld = DomainFixtures::com('log');
    $group = ClientGroup::query()->create(['name' => 'VIP']);

    $this->put(route('admin.domains.tlds.prices', $tld), [
        'currency' => 'USD',
        'client_group_id' => $group->id,
        'prices' => [['years' => 1, 'register' => 800, 'transfer' => 700, 'renew' => 900], ['years' => 2, 'register' => null, 'transfer' => null, 'renew' => null]],
    ])->assertRedirect();
    $this->put(route('admin.domains.tlds.prices', $tld), [
        'currency' => 'USD',
        'client_group_id' => $group->id,
        'prices' => [['years' => 1, 'register' => 850, 'transfer' => 700, 'renew' => 900]],
    ])->assertRedirect();

    expect(TldPrice::query()->where('client_group_id', $group->id)->get())->toHaveCount(1)
        ->and(TldPrice::query()->where('client_group_id', $group->id)->value('register'))->toBe(850)
        ->and(TldPrice::query()->whereNull('client_group_id')->where('years', 1)->value('register'))->toBe(1200);
});

test('the pricing grid clears a base term and validates amounts', function () {
    actingAsStaff(['domains.tlds.*']);
    $tld = DomainFixtures::com('log');

    $this->put(route('admin.domains.tlds.prices', $tld), ['currency' => 'ZZZ', 'prices' => [['years' => 1, 'register' => -5]]])
        ->assertSessionHasErrors(['currency', 'prices.0.register']);
    $this->put(route('admin.domains.tlds.prices', $tld), ['currency' => 'USD', 'prices' => [['years' => 10, 'register' => null, 'transfer' => null, 'renew' => null]]])
        ->assertRedirect();

    expect(TldPrice::query()->where('years', 10)->exists())->toBeFalse();
});

test('registrar settings are saved encrypted and blank secrets are kept', function () {
    actingAsStaff(['domains.registrars.manage']);
    confirmPassword();

    $this->put(route('admin.domains.registrars.update', 'log'), ['is_active' => true, 'test_mode' => true, 'settings' => ['api_key' => 'secret-1', 'simulate' => 'none']])->assertRedirect();
    $this->put(route('admin.domains.registrars.update', 'log'), ['is_active' => false, 'test_mode' => true, 'settings' => ['api_key' => '', 'simulate' => 'error']])->assertRedirect();

    $account = RegistrarAccount::query()->where('key', 'log')->sole();
    expect($account->settings)->toBe(['api_key' => 'secret-1', 'simulate' => 'error'])
        ->and($account->is_active)->toBeFalse()
        ->and($account->getRawOriginal('settings'))->not->toContain('secret-1');

    $this->get(route('admin.domains.registrars.index'))
        ->assertInertia(fn (Assert $page) => $page->component('admin/domains/registrars/index')
            ->where('registrars.0.key', 'log')
            ->where('registrars.0.settings.api_key', '')
            ->where('registrars.0.secrets_set.api_key', true));
});

test('the connection test reports the driver result', function () {
    actingAsStaff(['domains.registrars.manage']);
    confirmPassword();
    RegistrarAccount::query()->create(['key' => 'log', 'settings' => ['api_key' => 'invalid']]);

    $this->post(route('admin.domains.registrars.test', 'log'))
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');
    $this->post(route('admin.domains.registrars.test', 'missing'))->assertNotFound();
});

test('security review L4: registrar credentials need the exact permission and a recent password, and never reach old input', function () {
    actingAsStaff(['domains.registrars.manage']);
    $this->put(route('admin.domains.registrars.update', 'log'), ['is_active' => true, 'test_mode' => true, 'settings' => ['api_key' => 'secret-1']])->assertSessionHasErrors('password_confirmation');
    $this->post(route('admin.domains.registrars.test', 'log'))->assertSessionHasErrors('password_confirmation');

    confirmPassword();
    actingAsStaff(['domains.*']);
    $this->put(route('admin.domains.registrars.update', 'log'), ['is_active' => true, 'test_mode' => true, 'settings' => ['api_key' => 'secret-1']])->assertForbidden();
    expect(RegistrarAccount::query()->exists())->toBeFalse();

    actingAsStaff(['domains.registrars.manage']);
    $this->put(route('admin.domains.registrars.update', 'log'), ['test_mode' => true, 'settings' => ['api_key' => 'secret-flash', 'simulate' => 'error', 'extra' => 'undeclared']])
        ->assertSessionHasErrors('is_active')
        ->assertSessionHasInput('settings.simulate', 'error')
        ->assertSessionMissing('_old_input.settings.api_key')
        ->assertSessionMissing('_old_input.settings.extra');
});

test('registrar configuration needs its permission', function () {
    actingAsStaff(['domains.tlds.*']);

    $this->get(route('admin.domains.registrars.index'))->assertForbidden();
});

test('zero register or renew prices and registrable terms without a renewal price are rejected', function () {
    actingAsStaff(['domains.tlds.*']);
    $tld = DomainFixtures::com('log');

    $this->put(route('admin.domains.tlds.prices', $tld), ['currency' => 'USD', 'prices' => [
        ['years' => 1, 'register' => 0, 'transfer' => 0, 'renew' => 0],
        ['years' => 2, 'register' => 2400, 'transfer' => null, 'renew' => null],
    ]])->assertSessionHasErrors(['prices.0.register', 'prices.0.renew', 'prices.1.renew'])->assertSessionDoesntHaveErrors('prices.0.transfer');

    expect(TldPrice::query()->where('years', 1)->value('renew'))->toBe(1400);
});

test('the seeder prices every TLD in every configured currency', function () {
    Currency::factory()->create(['code' => 'INR', 'name' => 'Indian Rupee', 'prefix' => '₹', 'exchange_rate' => 84, 'is_default' => false]);
    Currency::factory()->create(['code' => 'NPR', 'name' => 'Nepalese Rupee', 'prefix' => 'रू ', 'exchange_rate' => 134.4, 'is_default' => false]);

    $this->seed(DomainsSeeder::class);

    expect(TldPrice::query()->distinct()->orderBy('currency')->pluck('currency')->all())->toBe(['INR', 'NPR', 'USD'])
        ->and(TldPrice::query()->whereHas('tld', fn ($query) => $query->where('extension', '.com'))->where('currency', 'INR')->where('years', 1)->value('register'))->toBe(109900)
        ->and(TldPrice::query()->whereIn('currency', ['INR', 'NPR', 'USD'])->where('years', 1)->whereNull('renew')->exists())->toBeFalse();
});

test('registrar names come from driver metadata without loading accounts', function () {
    DB::enableQueryLog();

    $names = array_map(fn (RegistrarDriver $driver): string => $driver->name(), app(RegistrarDriverRegistry::class)->all());

    expect($names)->toMatchArray(['log' => 'Log (demo)'])
        ->and(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'registrar_accounts')))->toBeEmpty();
});
