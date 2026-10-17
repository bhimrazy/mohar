<?php

use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Events\DomainAutoRenewChanged;
use App\Modules\Domains\Jobs\TransferDomain;
use App\Modules\Domains\Models\Domain;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Domains\DomainFixtures;
use Tests\Fakes\Domains\FakeRegistrar;

beforeEach(function () {
    $this->registrar = FakeRegistrar::install();
    [$this->user, $this->client] = actingAsClient();
    $this->com = DomainFixtures::com();
    $this->domain = Domain::factory()->create(['tld_id' => $this->com->id, 'client_id' => $this->client->id, 'registrar_key' => 'fake']);
});

test('clients see only their own domains', function () {
    $foreign = Domain::factory()->create(['tld_id' => $this->com->id]);

    $this->get(route('client.domains.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('client/domains/index')->has('domains.data', 1)->where('domains.data.0.id', $this->domain->id)->missing('domains.data.0.last_error'));

    $this->get(route('client.domains.show', $foreign->id))->assertNotFound();
    $this->put(route('client.domains.nameservers', $foreign->id), ['nameservers' => ['ns1.evil.com', 'ns2.evil.com']])->assertNotFound();
    $this->post(route('client.domains.epp', $foreign->id))->assertNotFound();
    expect($this->registrar->calls)->toBe([]);
});

test('the detail page shows management options for the registrar capabilities', function () {
    $this->registrar->capabilities = ['nameservers', 'epp'];

    $this->get(route('client.domains.show', $this->domain->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('client/domains/show')
            ->where('domain.name', $this->domain->name)
            ->where('capabilities', ['nameservers', 'epp'])
            ->where('canManage', true)
            ->has('renewalTerms', 9));
});

test('users without the domains permission are forbidden', function () {
    $user = User::factory()->create();
    $this->client->users()->attach($user, ['is_owner' => false, 'permissions' => json_encode(['billing'])]);
    $this->actingAs($user);

    $this->get(route('client.domains.index'))->assertForbidden();
    $this->put(route('client.domains.nameservers', $this->domain->id), ['nameservers' => ['ns1.a.com', 'ns2.a.com']])->assertForbidden();
    $this->put(route('client.domains.nameservers', $this->domain->id), ['nameservers' => ['bad']])->assertForbidden();
    expect($this->registrar->calls)->toBe([]);
});

test('clients update nameservers with validation', function () {
    $this->put(route('client.domains.nameservers', $this->domain->id), ['nameservers' => ['ns1.example.com']])
        ->assertSessionHasErrors('nameservers');
    $this->put(route('client.domains.nameservers', $this->domain->id), ['nameservers' => ['ns1.example.com', 'not a host']])
        ->assertSessionHasErrors('nameservers.1');

    $this->put(route('client.domains.nameservers', $this->domain->id), ['nameservers' => ['ns1.cloud.com', 'ns2.cloud.com', 'ns3.cloud.com']])
        ->assertRedirect(route('client.domains.show', $this->domain->id));

    expect($this->domain->fresh()->nameservers)->toBe(['ns1.cloud.com', 'ns2.cloud.com', 'ns3.cloud.com']);
});

test('a registrar without lock support returns capability_not_supported', function () {
    $this->registrar->capabilities = ['nameservers'];

    $this->putJson(route('client.domains.lock', $this->domain->id), ['enabled' => false])
        ->assertStatus(422)
        ->assertJsonPath('code', 'capability_not_supported');

    $this->get(route('client.domains.show', $this->domain->id))
        ->assertInertia(fn (Assert $page) => $page->where('capabilities', fn ($capabilities) => ! collect($capabilities)->contains('lock')));
    expect($this->domain->fresh()->registrar_lock)->toBeTrue();
});

test('clients lock and unlock their domain', function () {
    $this->put(route('client.domains.lock', $this->domain->id), ['enabled' => false])->assertRedirect();

    expect($this->domain->fresh()->registrar_lock)->toBeFalse()
        ->and($this->registrar->commands())->toBe(['lock']);
});

test('clients toggle auto-renew', function () {
    Event::fake([DomainAutoRenewChanged::class]);

    $this->put(route('client.domains.auto-renew', $this->domain->id), ['enabled' => false])->assertRedirect();

    expect($this->domain->fresh()->auto_renew)->toBeFalse();
    Event::assertDispatched(DomainAutoRenewChanged::class, fn (DomainAutoRenewChanged $event) => ! $event->enabled);
});

test('client EPP retrieval shows the code once and is logged', function () {
    $this->post(route('client.domains.epp', $this->domain->id))
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.epp.code', 'FAKE-EPP-CODE');

    $log = ActivityLog::query()->where('subject_id', $this->domain->id)->where('description', 'EPP code retrieved by client')->sole();
    expect($log->properties)->toMatchArray(['actor' => 'client', 'user_id' => $this->user->id])
        ->and($log->client_id)->toBe($this->client->id);
});

test('clients update contacts', function () {
    $contact = ['first_name' => 'Grace', 'last_name' => 'Hopper', 'email' => 'Grace@Example.com', 'country' => 'us'];

    $this->put(route('client.domains.contacts', $this->domain->id), ['contacts' => ['registrant' => $contact]])->assertRedirect();
    $this->put(route('client.domains.contacts', $this->domain->id), ['contacts' => ['registrant' => ['first_name' => 'X']]])
        ->assertSessionHasErrors(['contacts.registrant.last_name', 'contacts.registrant.email']);

    $this->assertDatabaseHas('domain_contacts', ['domain_id' => $this->domain->id, 'role' => 'registrant', 'email' => 'grace@example.com', 'country' => 'US']);
});

test('expired and pending domains cannot be managed', function (DomainStatus $status) {
    $this->domain->update(['status' => $status]);

    $this->put(route('client.domains.nameservers', $this->domain->id), ['nameservers' => ['ns1.a.com', 'ns2.a.com']])
        ->assertSessionHasErrors('domain');
    $this->post(route('client.domains.epp', $this->domain->id))->assertSessionHasErrors('domain');
    $this->get(route('client.domains.show', $this->domain->id))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canManage', false));

    expect($this->registrar->calls)->toBe([]);
})->with([DomainStatus::Expired, DomainStatus::PendingTransfer, DomainStatus::Cancelled]);

test('a failed paid transfer can be resubmitted with a new EPP code', function () {
    Queue::fake();
    $transfer = Domain::factory()->pending('transfer')->create(['tld_id' => $this->com->id, 'client_id' => $this->client->id, 'provision_invoice_id' => 10, 'last_error' => 'Bad code']);

    $this->put(route('client.domains.transfer', $transfer->id), ['epp_code' => ''])->assertSessionHasErrors('epp_code');
    $this->put(route('client.domains.transfer', $transfer->id), ['epp_code' => 'NEW-CODE'])->assertRedirect();

    expect($transfer->fresh())->epp_code->toBe('NEW-CODE')->last_error->toBeNull();
    Queue::assertPushed(TransferDomain::class, fn (TransferDomain $job) => $job->domainId === $transfer->id);
});

test('another client account of the same login stays separate', function () {
    $second = Client::factory()->create();
    $second->users()->attach($this->user, ['is_owner' => true]);
    $other = Domain::factory()->create(['tld_id' => $this->com->id, 'client_id' => $second->id]);

    $this->get(route('client.domains.show', $other->id))->assertNotFound();
});
