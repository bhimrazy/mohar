<?php

use App\Contracts\Domains\DomainRegistry;
use App\Contracts\Domains\TldAttributes;
use App\Data\Domains\DomainDraft;
use App\Data\Integrations\SettingField;
use App\Enums\FieldType;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Actions\SubmitToRegistrar;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarCommandLog;
use App\Modules\Domains\Models\Tld;
use App\Modules\Orders\Models\Cart;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Domains\FakeRegistrar;

const NEXUS_NOTE = 'ABN-SECRET-77';

beforeEach(function () {
    Currency::factory()->create();
    $this->registrar = FakeRegistrar::install();
    $this->registrar->tldFields['.us'] = [
        new SettingField('purpose', 'What the domain is for', FieldType::Select, required: true, options: ['P1' => 'Business', 'P3' => 'Personal']),
        new SettingField('note', 'Registration note', required: false),
        new SettingField('warranty', 'I am eligible', FieldType::Boolean, required: true),
    ];
    $this->us = Tld::factory()->extension('.us')->priced()->create(['registrar_key' => 'fake']);
    $this->com = Tld::factory()->extension('.com')->priced()->create(['registrar_key' => 'fake']);
    $this->client = Client::factory()->create();
});

function usDraft(Client $client, array $attributes, string $action = 'register'): DomainDraft
{
    return new DomainDraft($client->id, $action === 'transfer' ? 'asha-in.us' : 'asha.us', $action, 1, 'USD', 1400, 1200, null, $action === 'transfer' ? 'AUTH-1' : null, [], false, $attributes);
}

test('the fields come from the driver, only for the extensions it declares them for', function () {
    $fields = app(TldAttributes::class)->fieldsByTld();

    expect(array_keys($fields))->toBe(['.us'])
        ->and(array_map(fn (SettingField $field): string => $field->key, $fields['.us']))->toBe(['purpose', 'note', 'warranty']);
});

test('a registrar without the capability, or a disabled one, asks for nothing', function () {
    $this->registrar->capabilities = array_values(array_diff($this->registrar->capabilities, ['tld_attributes']));

    expect(app(TldAttributes::class)->fieldsByTld())->toBe([]);

    $this->registrar->capabilities[] = 'tld_attributes';
    $this->us->update(['registered_by_client' => true]);

    expect(app(TldAttributes::class)->fieldsByTld())->toBe([]);
});

test('answers are checked against the same field list', function (array $input, array $errors) {
    $check = fn () => app(TldAttributes::class)->clean('.us', $input);

    try {
        $check();
        $thrown = [];
    } catch (ValidationException $exception) {
        $thrown = array_keys($exception->errors());
    }

    expect($thrown)->toBe($errors);
})->with([
    'nothing sent' => [[], ['tld_attributes.purpose', 'tld_attributes.warranty']],
    'an option the form never offered' => [['purpose' => 'P9', 'warranty' => '1'], ['tld_attributes.purpose']],
    'a required confirmation left off' => [['purpose' => 'P1', 'warranty' => '0'], ['tld_attributes.warranty']],
    'a value with control characters' => [['purpose' => 'P1', 'warranty' => '1', 'note' => "a\x00b"], ['tld_attributes.note']],
    'a valid set' => [['purpose' => 'P3', 'warranty' => 'on'], []],
]);

test('unknown keys are dropped and a boolean is delivered as true or false', function () {
    expect(app(TldAttributes::class)->clean('.us', ['purpose' => ' P3 ', 'warranty' => '1', 'injected' => 'x']))->toBe(['purpose' => 'P3', 'warranty' => 'true'])
        ->and(app(TldAttributes::class)->clean('.com', ['anything' => 'x']))->toBe([]);
});

test('the domain search page and the staff order form receive the fields', function () {
    $this->get(route('store.domains', ['domain' => 'asha.us']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/store/domains')
            ->where('tld_attribute_fields', fn ($fields) => array_keys($fields->all()) === ['.us'] && $fields['.us'][0]['key'] === 'purpose'));

    actingAsStaff(['orders.*']);
    $this->get(route('admin.orders.create'))
        ->assertInertia(fn (Assert $page) => $page->where('tld_attribute_fields', fn ($fields) => $fields['.us'][1]['key'] === 'note'));
});

test('the cart refuses a registration without the details, and keeps the details encrypted', function () {
    $this->post(route('store.cart.domains.store'), ['domain' => 'asha.us', 'action' => 'register', 'years' => 1, 'tld_attributes' => ['warranty' => '1']])
        ->assertSessionHasErrors(['tld_attributes.purpose']);
    expect(Cart::query()->count())->toBe(0);

    $this->post(route('store.cart.domains.store'), ['domain' => 'asha.us', 'action' => 'register', 'years' => 1, 'tld_attributes' => ['purpose' => 'P3', 'note' => NEXUS_NOTE, 'warranty' => '1']])
        ->assertSessionHasNoErrors();

    $cart = Cart::query()->sole();
    expect(json_encode($cart->items))->not->toContain(NEXUS_NOTE)
        ->and($cart->cartItems()[0]->tldAttributes)->toBe(['purpose' => 'P3', 'note' => NEXUS_NOTE, 'warranty' => 'true']);
});

test('an extension without fields takes no details and a transfer sends none', function () {
    $this->post(route('store.cart.domains.store'), ['domain' => 'asha.com', 'action' => 'register', 'years' => 1, 'tld_attributes' => ['purpose' => 'P3']])
        ->assertSessionHasNoErrors();
    $this->post(route('store.cart.domains.store'), ['domain' => 'asha.us', 'action' => 'transfer', 'years' => 1, 'epp_code' => 'AUTH-1'])
        ->assertSessionHasNoErrors();

    expect(array_map(fn ($item) => $item->tldAttributes, Cart::query()->sole()->cartItems()))->toBe([[], []]);
});

test('a pending domain stores the details, and checks them again whoever built the draft', function () {
    expect(fn () => app(DomainRegistry::class)->createPending(usDraft($this->client, ['purpose' => 'P1'])))->toThrow(ValidationException::class);

    $domain = Domain::query()->findOrFail(app(DomainRegistry::class)->createPending(usDraft($this->client, ['purpose' => 'P1', 'note' => NEXUS_NOTE, 'warranty' => '1'])));
    $transfer = Domain::query()->findOrFail(app(DomainRegistry::class)->createPending(usDraft($this->client, ['purpose' => 'P1'], 'transfer')));

    expect($domain->additional_fields)->toBe(['tld_attributes' => ['purpose' => 'P1', 'note' => NEXUS_NOTE, 'warranty' => 'true']])
        ->and($transfer->additional_fields)->toBeNull();
});

test('the registrar receives the details, and they reach no log or activity text', function () {
    $domain = Domain::query()->findOrFail(app(DomainRegistry::class)->createPending(usDraft($this->client, ['purpose' => 'P3', 'note' => NEXUS_NOTE, 'warranty' => '1'])));

    app(SubmitToRegistrar::class)->handle($domain);

    expect($this->registrar->requests)->toHaveCount(1)
        ->and($this->registrar->requests[0]->tldAttributes)->toBe(['purpose' => 'P3', 'note' => NEXUS_NOTE, 'warranty' => 'true'])
        ->and(json_encode(RegistrarCommandLog::query()->get()->toArray()))->not->toContain(NEXUS_NOTE)
        ->and(json_encode(ActivityLog::query()->get()->toArray()))->not->toContain(NEXUS_NOTE)
        ->and(json_encode(ActivityLog::query()->get()->toArray()))->toContain('Domain register created');
});
