<?php

use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Domains\DomainFixtures;
use Tests\Fakes\Domains\FakeInvoicer;
use Tests\Fakes\Domains\FakeRegistrar;

beforeEach(function () {
    FakeRegistrar::install();
    $this->invoicer = FakeInvoicer::bind();
});

test('clients renew now at the current price for the chosen term', function () {
    [, $client] = actingAsClient();
    $com = DomainFixtures::com();
    $domain = Domain::factory()->create(['tld_id' => $com->id, 'client_id' => $client->id, 'recurring_amount' => 999]);

    $this->get(route('client.domains.show', $domain->id))->assertInertia(fn (Assert $page) => $page->where('canRenewNow', true));
    $this->post(route('client.domains.renew', $domain->id), ['years' => 2])->assertRedirect(route('client.domains.show', $domain->id));

    $draft = $this->invoicer->issued()[0]['draft'];
    expect($draft->lines[0]->unitAmount)->toBe(2800)
        ->and($draft->lines[0]->periodStart->toDateString())->toBe($domain->next_due_date->toDateString())
        ->and($draft->lines[0]->periodEnd->toDateString())->toBe($domain->next_due_date->addYears(2)->toDateString());

    $this->post(route('client.domains.renew', $domain->id), ['years' => 1])->assertSessionHasErrors('domain');
    expect($this->invoicer->issued())->toHaveCount(1);
});

test('clients cannot renew someone else\'s domain or an unavailable term', function () {
    [, $client] = actingAsClient();
    $com = DomainFixtures::com();
    $foreign = Domain::factory()->create(['tld_id' => $com->id]);
    $own = Domain::factory()->create(['tld_id' => $com->id, 'client_id' => $client->id]);

    $this->post(route('client.domains.renew', $foreign->id))->assertNotFound();
    $this->post(route('client.domains.renew', $own->id), ['years' => 10])->assertSessionHasErrors('domain');
    $own->update(['status' => DomainStatus::PendingTransfer]);
    $this->post(route('client.domains.renew', $own->id))->assertSessionHasErrors('domain');

    expect($this->invoicer->issued())->toBe([]);
});

test('a bundled free domain renews with its hosting, not on its own', function () {
    [, $client] = actingAsClient();
    $com = DomainFixtures::com();
    $domain = Domain::factory()->create(['tld_id' => $com->id, 'client_id' => $client->id, 'recurring_amount' => 0, 'linked_service_id' => 9, 'linked_service_status' => 'active']);

    $this->post(route('client.domains.renew', $domain->id))->assertSessionHasErrors('domain');
});

test('staff create a renewal invoice at the locked amount', function () {
    actingAsStaff(['domains.*']);
    Currency::factory()->create();
    $com = DomainFixtures::com(attributes: ['grace_days' => 30, 'grace_fee' => 500]);
    $domain = Domain::factory()->status(DomainStatus::Grace)->create(['tld_id' => $com->id, 'recurring_amount' => 999, 'period_years' => 1]);

    $this->post(route('admin.domains.renewal-invoice', $domain))->assertRedirect(route('admin.domains.show', $domain));

    expect(array_map(fn ($line) => $line->unitAmount, $this->invoicer->issued()[0]['draft']->lines))->toBe([999, 500]);
    $this->assertDatabaseHas('domain_invoices', ['domain_id' => $domain->id, 'kind' => 'grace', 'status' => 'open']);
});
