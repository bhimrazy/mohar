<?php

use App\Contracts\Domains\DomainRegistry;
use App\Contracts\Domains\TldAttributes;
use App\Data\Domains\DomainDraft;
use App\Data\Integrations\SettingField;
use App\Enums\FieldType;
use App\Events\Domains\RegistrarActionFailed;
use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Actions\SubmitToRegistrar;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Modules\Domains\Models\RegistrarCommandLog;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Services\TldAttributeService;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Services\TemplateCatalog;
use App\Modules\Orders\Models\Cart;
use App\Support\Domains\RegistrarException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Domains\FakeRegistrar;

const NEXUS_NOTE = 'ABN-SECRET-77';
const NEXUS_ID = '51824753556';

beforeEach(function () {
    Currency::factory()->create();
    $this->registrar = FakeRegistrar::install();
    $this->registrar->tldFields['.us'] = [
        new SettingField('purpose', 'What the domain is for', FieldType::Select, required: true, help: 'Pick the closest use.', options: ['P1' => 'Business', 'P3' => 'Personal']),
        new SettingField('note', 'Registration note', required: false),
        new SettingField('id', 'Identification number', required: false),
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

function cartErrors(array $attributes, string $domain = 'asha.us'): array
{
    $response = test()->post(route('store.cart.domains.store'), ['domain' => $domain, 'action' => 'register', 'years' => 1, 'tld_attributes' => $attributes]);

    return app('session.store')->get('errors')['default']['messages'] ?? [];
}

test('the fields come from the driver, only for the extensions it declares them for', function () {
    $fields = app(TldAttributes::class)->fieldsByTld();

    expect(array_keys($fields))->toBe(['.us'])
        ->and(array_map(fn (SettingField $field): string => $field->key, $fields['.us']))->toBe(['purpose', 'note', 'id', 'warranty']);
});

test('a registrar without the capability asks for nothing, and a client-registered extension too', function () {
    $this->registrar->capabilities = array_values(array_diff($this->registrar->capabilities, ['tld_attributes']));
    app()->forgetScopedInstances();

    expect(app(TldAttributes::class)->fieldsByTld())->toBe([])
        ->and(app(TldAttributes::class)->clean('.us', []))->toBe([]);

    $this->registrar->capabilities[] = 'tld_attributes';
    $this->us->update(['registered_by_client' => true]);
    app()->forgetScopedInstances();

    expect(app(TldAttributes::class)->fieldsByTld())->toBe([]);
});

test('answers are checked against the same field list', function (array $input, array $errors) {
    try {
        app(TldAttributes::class)->clean('.us', $input);
        $thrown = [];
    } catch (ValidationException $exception) {
        $thrown = array_keys($exception->errors());
    }

    expect($thrown)->toBe($errors);
})->with([
    'nothing sent' => [[], ['tld_attributes.purpose', 'tld_attributes.warranty']],
    'an option the form never offered' => [['purpose' => 'P9', 'warranty' => '1'], ['tld_attributes.purpose']],
    'a required confirmation left off' => [['purpose' => 'P1', 'warranty' => '0'], ['tld_attributes.warranty']],
    'a newline' => [['purpose' => 'P1', 'warranty' => '1', 'note' => "a\nb"], ['tld_attributes.note']],
    'a line separator' => [['purpose' => 'P1', 'warranty' => '1', 'note' => "a\u{2028}b"], ['tld_attributes.note']],
    'a C1 control' => [['purpose' => 'P1', 'warranty' => '1', 'note' => "a\u{0085}b"], ['tld_attributes.note']],
    'a bidi override' => [['purpose' => 'P1', 'warranty' => '1', 'note' => "a\u{202E}b"], ['tld_attributes.note']],
    'invalid UTF-8' => [['purpose' => 'P1', 'warranty' => '1', 'note' => "ab\xFFcd"], ['tld_attributes.note']],
    'a value over 255 characters' => [['purpose' => 'P1', 'warranty' => '1', 'note' => str_repeat('a', 256)], ['tld_attributes.note']],
    'a valid set' => [['purpose' => 'P3', 'warranty' => 'on'], []],
]);

test('every refusal names the field by its label, never by its key', function () {
    $messages = [];

    foreach ([[], ['purpose' => 'P9', 'warranty' => '0', 'note' => str_repeat('a', 256)], ['purpose' => 'P1', 'warranty' => '1', 'note' => "ab\xFF"]] as $input) {
        try {
            app(TldAttributes::class)->clean('.us', $input);
        } catch (ValidationException $exception) {
            $messages = [...$messages, ...array_merge(...array_values($exception->errors()))];
        }
    }

    expect($messages)->toContain('Choose what the domain is for.')
        ->toContain('Tick the box to confirm.')
        ->toContain('Registration note can be up to 255 characters.')
        ->and(implode(' ', $messages))->not->toContain('tld_attributes')->not->toContain('purpose');
});

test('unknown keys are dropped and a boolean is delivered as true or false', function () {
    expect(app(TldAttributes::class)->clean('.us', ['purpose' => ' P3 ', 'warranty' => '1', 'injected' => 'x']))->toBe(['purpose' => 'P3', 'warranty' => 'true'])
        ->and(app(TldAttributes::class)->clean('.com', ['anything' => 'x']))->toBe([]);
});

test('the domain search page and the staff order form receive the fields, with their help', function () {
    $this->get(route('store.domains', ['domain' => 'asha.us']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/store/domains')
            ->where('tld_attribute_fields', fn ($fields) => array_keys($fields->all()) === ['.us'] && $fields['.us'][0]['key'] === 'purpose' && $fields['.us'][0]['help'] === 'Pick the closest use.'));

    actingAsStaff(['orders.*']);
    $this->get(route('admin.orders.create'))
        ->assertInertia(fn (Assert $page) => $page->where('tld_attribute_fields', fn ($fields) => $fields['.us'][1]['key'] === 'note'));
});

test('the extension fields cost a fixed number of queries however many extensions there are', function () {
    $count = function (): int {
        app()->forgetScopedInstances();
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(TldAttributes::class)->fieldsByTld();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $before = $count();

    foreach (range(1, 50) as $i) {
        Tld::factory()->extension(".x{$i}")->priced()->create(['registrar_key' => 'fake']);
        $this->registrar->tldFields[".x{$i}"] = [new SettingField('purpose', 'Purpose', required: true)];
    }

    expect($before)->toBeLessThanOrEqual(2)->and($count())->toBe($before)->and(count(app(TldAttributes::class)->fieldsByTld()))->toBe(51);

    $this->get(route('store.domains', ['domain' => 'asha.us']))->assertOk();
    app()->forgetScopedInstances();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get(route('store.domains', ['domain' => 'asha.us']))->assertOk();
    $page = count(DB::getQueryLog());
    DB::disableQueryLog();

    foreach (range(51, 100) as $i) {
        Tld::factory()->extension(".x{$i}")->priced()->create(['registrar_key' => 'fake']);
        $this->registrar->tldFields[".x{$i}"] = [new SettingField('purpose', 'Purpose', required: true)];
    }

    app()->forgetScopedInstances();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get(route('store.domains', ['domain' => 'asha.us']))->assertOk();

    expect(count(DB::getQueryLog()))->toBe($page);
});

test('the cart refuses a registration without the details, and keeps the details encrypted', function () {
    expect(cartErrors(['warranty' => '1']))->toHaveKey('tld_attributes.purpose')
        ->and(Cart::query()->count())->toBe(0);

    $this->post(route('store.cart.domains.store'), ['domain' => 'asha.us', 'action' => 'register', 'years' => 1, 'tld_attributes' => ['purpose' => 'P3', 'note' => NEXUS_NOTE, 'warranty' => '1']])
        ->assertSessionHasNoErrors();

    $cart = Cart::query()->sole();
    expect(json_encode($cart->items))->not->toContain(NEXUS_NOTE)
        ->and($cart->cartItems()[0]->tldAttributes)->toBe(['purpose' => 'P3', 'note' => NEXUS_NOTE, 'warranty' => 'true']);
});

test('invalid UTF-8 or an over-long value at the cart is a field error, not a 500 or a silent drop', function () {
    expect(cartErrors(['purpose' => 'P1', 'warranty' => '1', 'note' => "ab\xFFcd"]))->toHaveKey('tld_attributes.note');

    $errors = cartErrors(['purpose' => 'P1', 'warranty' => '1', 'note' => str_repeat('a', 300)]);

    expect($errors['tld_attributes.note'][0])->toBe('Registration note can be up to 255 characters.')
        ->and(Cart::query()->count())->toBe(0);
});

test('the cart and the checkout read the entered details back, ID numbers shortened', function () {
    $this->post(route('store.cart.domains.store'), ['domain' => 'asha.us', 'action' => 'register', 'years' => 1, 'tld_attributes' => ['purpose' => 'P3', 'id' => NEXUS_ID, 'warranty' => '1']]);

    $this->get(route('store.cart'))->assertInertia(fn (Assert $page) => $page
        ->where('cart.items.0.details', [
            ['label' => 'What the domain is for', 'value' => 'Personal'],
            ['label' => 'Identification number', 'value' => '••••••••556'],
            ['label' => 'I am eligible', 'value' => 'Yes'],
        ]));
});

test('an extension without fields takes no details and a transfer sends none', function () {
    $this->post(route('store.cart.domains.store'), ['domain' => 'asha.com', 'action' => 'register', 'years' => 1, 'tld_attributes' => ['purpose' => 'P3']])
        ->assertSessionHasNoErrors();
    $this->post(route('store.cart.domains.store'), ['domain' => 'asha.us', 'action' => 'transfer', 'years' => 1, 'epp_code' => 'AUTH-1'])
        ->assertSessionHasNoErrors();

    expect(array_map(fn ($item) => $item->tldAttributes, Cart::query()->sole()->cartItems()))->toBe([[], []]);
});

test('a disabled registrar account hides the form and refuses the order at the cart in plain words', function () {
    RegistrarAccount::query()->create(['key' => 'fake', 'is_active' => false, 'settings' => []]);
    app()->forgetScopedInstances();

    $errors = cartErrors(['purpose' => 'P3', 'warranty' => '1']);

    expect(app(TldAttributes::class)->fieldsByTld())->toBe([])
        ->and($errors['domain'][0])->toBe('We cannot take .us registrations right now. Please try again later.')
        ->and(Cart::query()->count())->toBe(0);
});

test('an order whose account was disabled after the details were collected is refused, not stripped of them', function () {
    RegistrarAccount::query()->create(['key' => 'fake', 'is_active' => true, 'settings' => []]);
    $draft = usDraft($this->client, ['purpose' => 'P1', 'warranty' => '1']);
    RegistrarAccount::query()->where('key', 'fake')->update(['is_active' => false]);
    app()->forgetScopedInstances();

    expect(fn () => app(DomainRegistry::class)->createPending($draft))->toThrow(ValidationException::class, 'We cannot take .us registrations right now');
});

test('a pending domain stores the details, and checks them again whoever built the draft', function () {
    expect(fn () => app(DomainRegistry::class)->createPending(usDraft($this->client, ['purpose' => 'P1'])))->toThrow(ValidationException::class);

    $domain = Domain::query()->findOrFail(app(DomainRegistry::class)->createPending(usDraft($this->client, ['purpose' => 'P1', 'note' => NEXUS_NOTE, 'warranty' => '1'])));
    $transfer = Domain::query()->findOrFail(app(DomainRegistry::class)->createPending(usDraft($this->client, ['purpose' => 'P1'], 'transfer')));

    expect($domain->additional_fields)->toBe(['tld_attributes' => ['purpose' => 'P1', 'note' => NEXUS_NOTE, 'warranty' => 'true']])
        ->and($transfer->additional_fields)->toBeNull();
});

test('invalid UTF-8 on a draft built outside the cart is a validation error, not a server error', function () {
    expect(fn () => app(DomainRegistry::class)->createPending(usDraft($this->client, ['purpose' => 'P1', 'warranty' => '1', 'note' => "ab\xFFcd"])))
        ->toThrow(ValidationException::class);
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

/**
 * Everywhere an error text goes after a register call failed with $message: the domain's last_error, the command
 * log, the activity log, the RegistrarActionFailed event, the staff mail and the admin domain page.
 *
 * @return array<string, string>
 */
function errorPlaces(Domain $domain, string $message): array
{
    Event::fake([RegistrarActionFailed::class]);
    test()->registrar->will('register', new RegistrarException($message));

    app(SubmitToRegistrar::class)->handle($domain);

    $places = [
        'last_error' => (string) $domain->fresh()->last_error,
        'command log' => json_encode(RegistrarCommandLog::query()->get()->toArray()),
        'activity log' => json_encode(ActivityLog::query()->get()->toArray()),
    ];
    Event::assertDispatched(RegistrarActionFailed::class, function (RegistrarActionFailed $event) use (&$places): bool {
        $places['event'] = $event->error;

        return true;
    });

    return $places;
}

/**
 * @param  array<string, string>  $places
 * @param  list<string>  $needles
 * @return list<string> the places that hold any of the needles
 */
function leaksIn(array $places, array $needles): array
{
    return array_keys(array_filter($places, fn (string $text): bool => collect($needles)->contains(fn (string $needle): bool => str_contains($text, $needle))));
}

test('a registry message that repeats an answer is masked in every place an error goes', function () {
    $domain = Domain::query()->findOrFail(app(DomainRegistry::class)->createPending(usDraft($this->client, ['purpose' => 'P1', 'id' => NEXUS_ID, 'note' => NEXUS_NOTE, 'warranty' => '1'])));

    $places = errorPlaces($domain, 'The registry refused: invalid value '.NEXUS_ID.' for id, also seen as 518 2475 3556 and '.NEXUS_NOTE.'.');

    expect(leaksIn($places, [NEXUS_ID, NEXUS_NOTE, '518 2475 3556']))->toBe([])
        ->and($places['last_error'])->toContain('invalid value')->toContain('for id');

    actingAsStaff(['domains.*']);
    $this->get(route('admin.domains.show', $domain))->assertInertia(fn (Assert $page) => $page
        ->where('commandLog', fn ($log) => ! str_contains(json_encode($log), NEXUS_ID) && ! str_contains(json_encode($log), NEXUS_NOTE))
        ->where('domain', fn ($shown) => ! str_contains(json_encode($shown), NEXUS_ID)));
});

test('the failure mail to staff does not carry an answer the registry repeated', function () {
    app(TemplateCatalog::class)->install('en');
    Mail::fake();
    User::factory()->staff(['*'])->create(['email' => 'admin@example.test']);
    $domain = Domain::query()->findOrFail(app(DomainRegistry::class)->createPending(usDraft($this->client, ['purpose' => 'P1', 'id' => NEXUS_ID, 'warranty' => '1'])));
    $this->registrar->will('register', new RegistrarException('Invalid value '.NEXUS_ID.' for attribute id'));

    app(SubmitToRegistrar::class)->handle($domain);

    $sent = [];
    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use (&$sent): bool {
        $sent[] = $mail->subjectLine."\n".$mail->plainText."\n".$mail->htmlBody;

        return true;
    });
    $logged = EmailMessage::query()->where('template_key', 'staff.registrar_action_failed')->get(['body_text', 'body_html'])->toArray();

    expect($logged)->not->toBe([])
        ->and(leaksIn(['mail' => implode("\n", $sent), 'mail log' => json_encode($logged)], [NEXUS_ID]))->toBe([])
        ->and(implode("\n", $sent))->toContain('for attribute id');
});

test('an answer is masked however the registry regrouped, punctuated or re-cased it', function (string $typed, string $echoed) {
    $domain = Domain::query()->findOrFail(app(DomainRegistry::class)->createPending(usDraft($this->client, ['purpose' => 'P1', 'id' => $typed, 'warranty' => '1'])));

    $places = errorPlaces($domain, "Invalid value {$echoed} for attribute id");

    expect(leaksIn($places, [$echoed]))->toBe([])
        ->and($places['last_error'])->toContain('Invalid value')->toContain('for attribute id');
})->with([
    'as typed' => ['51 824 753 556', '51 824 753 556'],
    'spaces removed' => ['51 824 753 556', '51824753556'],
    'regrouped' => ['51 824 753 556', '518 247 535 56'],
    'dashed' => ['51 824 753 556', '51-824-753-556'],
    'dotted' => ['51 824 753 556', '51.824.753.556'],
    'upper-cased' => ['tm-abc-1234', 'TM-ABC-1234'],
    'spaced and re-cased' => ['tm-abc-1234', 'Tm Abc 1234'],
    'separators dropped and re-cased' => ['tm-abc-1234', 'TMABC1234'],
]);

test('a registry message with invalid UTF-8 keeps its words and still masks the answer', function () {
    $domain = Domain::query()->findOrFail(app(DomainRegistry::class)->createPending(usDraft($this->client, ['purpose' => 'P1', 'id' => NEXUS_ID, 'warranty' => '1'])));

    $places = errorPlaces($domain, "Registry: \xFF value ".NEXUS_ID.' refused for attribute id');

    expect(leaksIn($places, [NEXUS_ID]))->toBe([])
        ->and($places['last_error'])->toContain('refused for attribute id');
});

test('a value of fewer than four characters is not masked, so ordinary words in an error survive', function () {
    $domain = Domain::query()->findOrFail(app(DomainRegistry::class)->createPending(usDraft($this->client, ['purpose' => 'P1', 'id' => 'X7Q', 'warranty' => '1'])));

    $places = errorPlaces($domain, 'Invalid value X7Q for attribute id');

    expect($places['last_error'])->toContain('X7Q');
});

test('select and boolean answers are not masked, so ordinary words in an error survive', function () {
    $service = app(TldAttributeService::class);

    expect($service->maskable('.us', ['purpose' => 'P1', 'warranty' => 'true', 'note' => 'abc', 'id' => '5182 4753']))->toBe(['5182 4753', '51824753'])
        ->and($service->maskable('.us', ['id' => 'Ab-12.cd']))->toBe(['Ab-12.cd', 'Ab12cd', 'ab-12.cd', 'AB-12.CD', 'ab12cd', 'AB12CD']);
});

test('staff see the stored details on the domain page with labels, not codes', function () {
    $domain = Domain::query()->findOrFail(app(DomainRegistry::class)->createPending(usDraft($this->client, ['purpose' => 'P1', 'id' => NEXUS_ID, 'warranty' => '1'])));
    actingAsStaff(['domains.*']);

    $this->get(route('admin.domains.show', $domain))->assertInertia(fn (Assert $page) => $page->where('registryDetails', [
        ['label' => 'What the domain is for', 'value' => 'Business'],
        ['label' => 'Identification number', 'value' => NEXUS_ID],
        ['label' => 'I am eligible', 'value' => 'Yes'],
    ]));
});

test('the details stay on a soft-deleted domain until an erasure or purge clears them, which does not exist yet', function () {
    $domain = Domain::query()->findOrFail(app(DomainRegistry::class)->createPending(usDraft($this->client, ['purpose' => 'P1', 'warranty' => '1'])));

    $domain->delete();

    expect(Domain::query()->whereKey($domain->id)->exists())->toBeFalse()
        ->and(Domain::withTrashed()->findOrFail($domain->id)->additional_fields)->toBe(['tld_attributes' => ['purpose' => 'P1', 'warranty' => 'true']]);
});
