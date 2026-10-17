<?php

use App\Events\Domains\DomainEppCodeRetrieved;
use App\Events\Domains\DomainStatusChanged;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Jobs\RegisterDomain;
use App\Modules\Domains\Jobs\RenewDomain;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Modules\Domains\Models\RegistrarCommandLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Domains\DomainFixtures;
use Tests\Fakes\Domains\FakeRegistrar;

beforeEach(function () {
    $this->registrar = FakeRegistrar::install();
    $this->com = DomainFixtures::com();
});

test('staff need the domains view permission', function () {
    actingAsStaff(['support.*']);
    $domain = Domain::factory()->create(['tld_id' => $this->com->id]);

    $this->get(route('admin.domains.index'))->assertForbidden();
    $this->get(route('admin.domains.show', $domain))->assertForbidden();
});

test('viewers cannot change domains or run commands', function () {
    actingAsStaff(['domains.domains.view']);
    $domain = Domain::factory()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);

    $this->put(route('admin.domains.nameservers', $domain), ['nameservers' => ['ns1.a.com', 'ns2.a.com']])->assertForbidden();
    $this->post(route('admin.domains.epp', $domain))->assertForbidden();
    $this->post(route('admin.domains.command', [$domain, 'sync']))->assertForbidden();
    $this->delete(route('admin.domains.destroy', $domain))->assertForbidden();
    expect($this->registrar->calls)->toBe([]);
});

test('the domain list filters by status, registrar, extension, client and expiry', function () {
    actingAsStaff(['domains.*']);
    $this->travelTo(CarbonImmutable::parse('2026-10-17'));
    $client = Client::factory()->create();
    $soon = Domain::factory()->expiring('2026-11-01')->create(['tld_id' => $this->com->id, 'client_id' => $client->id]);
    Domain::factory()->expiring('2027-09-01')->create(['tld_id' => $this->com->id]);
    $pending = Domain::factory()->pending()->create(['tld_id' => $this->com->id, 'registrar_key' => null]);

    $this->get(route('admin.domains.index', ['expiring' => 30]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/domains/index')->has('domains.data', 1)->where('domains.data.0.id', $soon->id));
    $this->get(route('admin.domains.index', ['status' => 'pending']))
        ->assertInertia(fn (Assert $page) => $page->has('domains.data', 1)->where('domains.data.0.id', $pending->id));
    $this->get(route('admin.domains.index', ['registrar' => 'manual']))
        ->assertInertia(fn (Assert $page) => $page->has('domains.data', 1)->where('domains.data.0.id', $pending->id));
    $this->get(route('admin.domains.index', ['client' => $client->id]))
        ->assertInertia(fn (Assert $page) => $page->has('domains.data', 1));
    $this->get(route('admin.domains.index', ['search' => strtoupper($soon->sld), 'tld' => 'com']))
        ->assertInertia(fn (Assert $page) => $page->has('domains.data', 1)->where('domains.data.0.name', $soon->name));
});

test('the detail page shows dates, capabilities and the command log', function () {
    actingAsStaff(['domains.*']);
    $domain = Domain::factory()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);
    $this->registrar->capabilities = ['sync', 'nameservers'];

    $this->get(route('admin.domains.show', $domain))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/domains/show')
            ->where('domain.id', $domain->id)
            ->where('domain.expiry_date', $domain->expiry_date->toDateString())
            ->where('capabilities', ['sync', 'nameservers'])
            ->has('contacts.registrant')
            ->has('commandLog')
            ->where('can.commands', true)
            ->missing('domain.epp_code'));
});

test('staff edit dates and amounts and can recalculate the renewal price', function () {
    actingAsStaff(['domains.*']);
    $domain = Domain::factory()->create(['tld_id' => $this->com->id, 'recurring_amount' => 999, 'period_years' => 1]);

    $this->put(route('admin.domains.update', $domain), [
        'registration_date' => '2025-01-10',
        'expiry_date' => '2027-01-10',
        'next_due_date' => '2027-01-10',
        'next_invoice_date' => '2026-12-11',
        'period_years' => 2,
        'first_payment_amount' => 1200,
        'recurring_amount' => 999,
        'registrar_key' => 'fake',
        'auto_renew' => false,
        'id_protection' => true,
        'dns_management' => false,
        'email_forwarding' => false,
        'recalculate_recurring' => true,
    ])->assertRedirect(route('admin.domains.show', $domain));

    expect($domain->fresh())
        ->expiry_date->toDateString()->toBe('2027-01-10')
        ->period_years->toBe(2)
        ->recurring_amount->toBe(2800)
        ->auto_renew->toBeFalse()
        ->id_protection->toBeTrue()
        ->registrar_key->toBe('fake');
});

test('editing validates the dates', function () {
    actingAsStaff(['domains.*']);
    $domain = Domain::factory()->create(['tld_id' => $this->com->id]);

    $this->put(route('admin.domains.update', $domain), ['next_due_date' => '2027-01-01', 'next_invoice_date' => '2027-02-01', 'period_years' => 11])
        ->assertSessionHasErrors(['next_invoice_date', 'period_years', 'recurring_amount']);
});

test('staff can force any status with a logged reason', function () {
    Event::fake([DomainStatusChanged::class]);
    actingAsStaff(['domains.*']);
    $domain = Domain::factory()->status(DomainStatus::Expired)->create(['tld_id' => $this->com->id]);

    $this->put(route('admin.domains.status', $domain), ['status' => 'grace'])->assertSessionHasErrors('reason');
    $this->put(route('admin.domains.status', $domain), ['status' => 'grace', 'reason' => 'Registry restored it'])->assertRedirect();

    expect($domain->fresh()->status)->toBe(DomainStatus::Grace);
    $this->assertDatabaseHas('activity_log', ['subject_id' => $domain->id, 'description' => 'Domain status forced from expired to grace']);
    Event::assertDispatched(DomainStatusChanged::class, fn (DomainStatusChanged $event) => $event->reason === 'Registry restored it');
});

test('staff queue a registration only for pending domains', function () {
    Queue::fake();
    actingAsStaff(['domains.*']);
    $pending = Domain::factory()->pending()->create(['tld_id' => $this->com->id]);
    $active = Domain::factory()->create(['tld_id' => $this->com->id]);

    $this->post(route('admin.domains.command', [$pending, 'register']))->assertRedirect();
    $this->post(route('admin.domains.command', [$active, 'register']))->assertSessionHasErrors('domain');

    Queue::assertPushed(RegisterDomain::class, 1);
});

test('staff renew and sync from the detail page', function () {
    Queue::fake();
    $staff = actingAsStaff(['domains.*']);
    $domain = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake', 'period_years' => 2]);

    $this->post(route('admin.domains.command', [$domain, 'renew']))->assertRedirect();
    Queue::assertPushed(RenewDomain::class, fn (RenewDomain $job) => $job->years === 2 && $job->staffId === $staff->id && $job->fromExpiry === '2027-01-31');

    $this->post(route('admin.domains.command', [$domain, 'sync']))->assertRedirect()->assertSessionHasNoErrors();
    expect($this->registrar->commands())->toBe(['sync'])
        ->and($domain->fresh()->last_sync_status)->toBe('ok');
    $this->assertDatabaseHas('registrar_command_log', ['domain_id' => $domain->id, 'command' => 'sync', 'staff_id' => $staff->id]);
});

test('staff EPP retrieval is logged with the actor', function () {
    Event::fake([DomainEppCodeRetrieved::class]);
    $staff = actingAsStaff(['domains.*']);
    $domain = Domain::factory()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);

    $this->post(route('admin.domains.epp', $domain))->assertRedirect()->assertSessionHas('inertia.flash_data.epp.code', 'FAKE-EPP-CODE');

    $log = ActivityLog::query()->where('subject_id', $domain->id)->where('description', 'EPP code retrieved by staff')->sole();
    expect($log->properties)->toMatchArray(['actor' => 'staff', 'user_id' => $staff->id, 'domain' => $domain->name])
        ->and($log->client_id)->toBe($domain->client_id);
    Event::assertDispatched(DomainEppCodeRetrieved::class, fn (DomainEppCodeRetrieved $event) => $event->actor === 'staff' && $event->userId === $staff->id);
    expect(json_encode(RegistrarCommandLog::query()->where('command', 'epp')->value('response')))->not->toContain('FAKE-EPP-CODE');
});

test('staff update nameservers at the registrar', function () {
    actingAsStaff(['domains.*']);
    $domain = Domain::factory()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);

    $this->put(route('admin.domains.nameservers', $domain), ['nameservers' => ['NS1.New.com', 'ns2.new.com', 'ns2.new.com']])->assertRedirect();

    expect($domain->fresh()->nameservers)->toBe(['ns1.new.com', 'ns2.new.com'])
        ->and($this->registrar->commands())->toBe(['nameservers']);
});

test('moving a domain changes the owner for future invoices only', function () {
    actingAsStaff(['domains.*']);
    $domain = Domain::factory()->create(['tld_id' => $this->com->id]);
    $other = Client::factory()->create();

    $this->post(route('admin.domains.move', $domain), ['client_id' => $other->id])->assertRedirect();

    expect($domain->fresh()->client_id)->toBe($other->id);
});

test('deleting a domain record never calls the registrar', function () {
    actingAsStaff(['domains.*']);
    $domain = Domain::factory()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);

    $this->delete(route('admin.domains.destroy', $domain))->assertRedirect(route('admin.domains.index'));

    $this->assertSoftDeleted($domain);
    expect($this->registrar->calls)->toBe([])
        ->and(Domain::withTrashed()->find($domain->id)->active_name)->toBeNull();
});

test('renewal is not offered for pending registrations or transfers', function (DomainStatus $status) {
    Queue::fake();
    actingAsStaff(['domains.*']);
    $domain = Domain::factory()->pending()->status($status)->create(['tld_id' => $this->com->id]);

    $this->post(route('admin.domains.command', [$domain, 'renew']))->assertSessionHasErrors('domain');

    Queue::assertNotPushed(RenewDomain::class);
})->with([DomainStatus::Pending, DomainStatus::PendingTransfer, DomainStatus::PendingRegistration]);

test('domains needing review are listed and forcing a status closes the review', function () {
    actingAsStaff(['domains.*']);
    $flagged = Domain::factory()->pending()->status(DomainStatus::PendingRegistration)->create([
        'tld_id' => $this->com->id, 'needs_review' => true, 'registrar_meta' => ['submission' => now()->toIso8601String()],
    ]);
    Domain::factory()->create(['tld_id' => $this->com->id]);

    $this->get(route('admin.domains.index', ['attention' => 1]))
        ->assertInertia(fn (Assert $page) => $page->has('domains.data', 1)->where('domains.data.0.id', $flagged->id));
    $this->post(route('admin.domains.command', [$flagged, 'sync']))->assertRedirect();

    $this->put(route('admin.domains.status', $flagged), ['status' => 'active', 'reason' => 'Verified at the registrar'])->assertRedirect();

    expect($flagged->fresh())->needs_review->toBeFalse()->registrar_meta->toBeNull();
});

test('a disabled registrar is shown on the detail page and refuses sync', function () {
    actingAsStaff(['domains.*']);
    RegistrarAccount::query()->create(['key' => 'fake', 'is_active' => false]);
    $domain = Domain::factory()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);

    $this->get(route('admin.domains.show', $domain))->assertInertia(fn (Assert $page) => $page->where('registrar.active', false));
    $this->post(route('admin.domains.command', [$domain, 'sync']))->assertSessionHasErrors('registrar');

    expect($this->registrar->calls)->toBe([]);
});
