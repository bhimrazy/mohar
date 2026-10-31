<?php

use App\Contracts\Notifications\MergeFieldRegistry;
use App\Modules\Domains\Actions\IssueRenewalInvoices;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\DomainInvoice;
use App\Modules\Domains\Models\Tld;
use App\Support\CalendarDate;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Billing\FakeInvoicer;
use Tests\Fakes\Domains\DomainFixtures;

beforeEach(function () {
    [$this->user, $this->client] = actingAsClient();
    $np = Tld::factory()->extension('.com.np')->create();
    $this->selfRegistered = Domain::factory()->create(['tld_id' => $np->id, 'client_id' => $this->client->id, 'registrar_key' => null, 'expiry_date' => null, 'registration_date' => null, 'recurring_amount' => 0, 'auto_renew' => false]);
    $this->sold = Domain::factory()->create(['tld_id' => DomainFixtures::com()->id, 'client_id' => $this->client->id, 'registrar_key' => 'log']);
});

test('the client list and detail flag a name registered at the registry, and not one we sold', function () {
    $this->get(route('client.domains.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('domains.data', fn ($rows) => collect($rows)->firstWhere('id', $this->selfRegistered->id)['registered_by_client'] === true
            && collect($rows)->firstWhere('id', $this->sold->id)['registered_by_client'] === false));

    $this->get(route('client.domains.show', $this->selfRegistered->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('domain.registered_by_client', true)->where('domain.status', 'active')->where('canRenewNow', false));

    $this->get(route('client.domains.show', $this->sold->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('domain.registered_by_client', false));
});

test('staff see the same flag on the admin list and detail', function () {
    actingAsStaff(['domains.*']);

    $this->get(route('admin.domains.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('domains.data', fn ($rows) => collect($rows)->firstWhere('id', $this->selfRegistered->id)['registered_by_client'] === true
            && collect($rows)->firstWhere('id', $this->sold->id)['registered_by_client'] === false));

    $this->get(route('admin.domains.show', $this->selfRegistered->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('domain.registered_by_client', true));
});

test('mail merge fields name the status and expiry of a name registered at the registry', function () {
    $fields = app(MergeFieldRegistry::class);

    expect($fields->resolve('domain', $this->selfRegistered->id))->toMatchArray([
        'status' => 'active',
        'status_label' => 'Self-registered',
        'registered_by_client' => true,
        'expiry_date' => null,
        'expiry_label' => 'At the registry',
    ])->and($fields->resolve('domain', $this->sold->id))->toMatchArray([
        'status_label' => 'Active',
        'registered_by_client' => false,
        'expiry_label' => null,
    ]);
});

test('auto-renew and renew now are refused for a name registered at the registry, with no change', function () {
    $this->put(route('client.domains.auto-renew', $this->selfRegistered->id), ['enabled' => true])
        ->assertSessionHasErrors(['domain' => "Auto-renew can't be turned on for a name registered at the registry by the client; it is renewed there."]);
    $this->post(route('client.domains.renew', $this->selfRegistered->id))
        ->assertSessionHasErrors(['domain' => 'A name registered at the registry by the client is renewed there, so no renewal invoice is created.']);

    expect($this->selfRegistered->fresh()->auto_renew)->toBeFalse()
        ->and(DomainInvoice::query()->where('domain_id', $this->selfRegistered->id)->count())->toBe(0);

    $this->put(route('client.domains.auto-renew', $this->sold->id), ['enabled' => false])->assertSessionHasNoErrors();
    expect($this->sold->fresh()->auto_renew)->toBeFalse();
});

test('staff cannot invoice or renew a name registered at the registry', function () {
    actingAsStaff(['domains.*']);

    $this->post(route('admin.domains.renewal-invoice', $this->selfRegistered))
        ->assertSessionHasErrors(['domain' => 'A name registered at the registry by the client is renewed there, so no renewal invoice is created.']);
    $this->post(route('admin.domains.command', [$this->selfRegistered, 'renew']))->assertSessionHasErrors('domain');

    expect(DomainInvoice::query()->where('domain_id', $this->selfRegistered->id)->count())->toBe(0);
});

test('staff can save dates on such a name, but not turn auto-renew on', function () {
    actingAsStaff(['domains.*']);
    $payload = ['expiry_date' => '2027-03-01', 'next_due_date' => '2027-03-01', 'next_invoice_date' => '2027-02-01', 'period_years' => 1, 'first_payment_amount' => 0, 'recurring_amount' => 0, 'registrar_key' => null, 'auto_renew' => false, 'id_protection' => false, 'dns_management' => false, 'email_forwarding' => false];

    $this->put(route('admin.domains.update', $this->selfRegistered), $payload)->assertSessionHasNoErrors();
    expect($this->selfRegistered->fresh()->expiry_date->toDateString())->toBe('2027-03-01');

    $this->put(route('admin.domains.update', $this->selfRegistered), [...$payload, 'auto_renew' => true])
        ->assertSessionHasErrors(['domain' => "Auto-renew can't be turned on for a name registered at the registry by the client; it is renewed there."]);
    expect($this->selfRegistered->fresh()->auto_renew)->toBeFalse();
});

test('the daily renewal run does not invoice a name registered at the registry', function () {
    $invoicer = FakeInvoicer::bind();
    $due = CarbonImmutable::today()->subDay();
    $this->selfRegistered->forceFill(['auto_renew' => true, 'next_invoice_date' => $due, 'next_due_date' => $due->addDays(30), 'recurring_amount' => 1000])->save();
    $this->sold->forceFill(['auto_renew' => true, 'next_invoice_date' => $due, 'next_due_date' => $due->addDays(30)])->save();

    $issued = app(IssueRenewalInvoices::class)->handle(CalendarDate::parse(CarbonImmutable::today()->toDateString()));

    expect($issued)->toBe(1)
        ->and($invoicer->issued())->toHaveCount(1)
        ->and($invoicer->issued()[0]['draft']->lines[0]->billableId)->toBe($this->sold->id);
});

test('a new domain under such an extension starts with auto-renew off', function () {
    $np = Tld::query()->where('extension', '.com.np')->firstOrFail();
    $attributes = Domain::factory()->make(['tld_id' => $np->id, 'client_id' => $this->client->id])->getAttributes();
    unset($attributes['auto_renew']);

    expect(Domain::query()->create($attributes)->fresh()->auto_renew)->toBeFalse();
});
