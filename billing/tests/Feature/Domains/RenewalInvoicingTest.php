<?php

use App\Enums\BillableType;
use App\Events\Domains\DomainExpired;
use App\Events\Domains\FreeDomainRenewalBlocked;
use App\Events\Services\ServiceActivated;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Actions\ApplyExpiryLifecycle;
use App\Modules\Domains\Actions\IssueRenewalInvoices;
use App\Modules\Domains\Actions\SetAutoRenew;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\DomainInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\Domains\DomainFixtures;
use Tests\Fakes\Domains\FakeInvoicer;
use Tests\Fakes\Domains\FakeRegistrar;

beforeEach(function () {
    FakeRegistrar::install();
    $this->com = DomainFixtures::com();
    $this->invoicer = FakeInvoicer::bind();
});

test('the renewal invoice is issued once when the lead time is reached', function () {
    $domain = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id, 'recurring_amount' => 1499, 'gateway_key' => 'stripe']);

    $this->travelTo(CarbonImmutable::parse('2026-12-31'));
    $this->artisan('automation:run')->assertSuccessful();
    expect($this->invoicer->issued())->toHaveCount(0);

    $this->travelTo(CarbonImmutable::parse('2027-01-01'));
    $this->artisan('automation:run')->assertSuccessful();
    $this->artisan('automation:run', ['--force' => true])->assertSuccessful();
    app(IssueRenewalInvoices::class)->handle(CarbonImmutable::parse('2027-01-02'));

    expect($this->invoicer->issued())->toHaveCount(1);
    $draft = $this->invoicer->issued()[0]['draft'];
    $line = $draft->lines[0];
    expect($draft)->clientId->toBe($domain->client_id)->currency->toBe('USD')->paymentGateway->toBe('stripe')
        ->and($draft->dueDate->toDateString())->toBe('2027-01-31')
        ->and($line)->unitAmount->toBe(1499)->billableType->toBe(BillableType::Domain)->billableId->toBe($domain->id)
        ->and($line->periodStart->toDateString())->toBe('2027-01-31')
        ->and($line->periodEnd->toDateString())->toBe('2028-01-31');
    $this->assertDatabaseHas('domain_invoices', ['domain_id' => $domain->id, 'kind' => 'renewal', 'status' => 'open']);
});

test('domains with auto-renew off are not invoiced', function () {
    Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id, 'auto_renew' => false]);

    expect(app(IssueRenewalInvoices::class)->handle(CarbonImmutable::parse('2027-01-10')))->toBe(0)
        ->and($this->invoicer->issued())->toBe([]);
});

test('only active domains are invoiced', function () {
    Domain::factory()->expiring('2027-01-31')->status(DomainStatus::PendingTransfer)->create(['tld_id' => $this->com->id]);
    Domain::factory()->expiring('2027-01-31')->status(DomainStatus::Cancelled)->create(['tld_id' => $this->com->id]);

    expect(app(IssueRenewalInvoices::class)->handle(CarbonImmutable::parse('2027-01-10')))->toBe(0);
});

test('a free domain whose linked service is not active on the same due date is not renewed', function (array $service) {
    Event::fake([FreeDomainRenewalBlocked::class]);
    $domain = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id, 'recurring_amount' => 0, 'linked_service_id' => 77, ...$service]);

    app(IssueRenewalInvoices::class)->handle(CarbonImmutable::parse('2027-01-05'));
    app(IssueRenewalInvoices::class)->handle(CarbonImmutable::parse('2027-01-06'));

    expect($this->invoicer->issued())->toBe([]);
    Event::assertDispatchedTimes(FreeDomainRenewalBlocked::class, 1);
    Event::assertDispatched(FreeDomainRenewalBlocked::class, fn (FreeDomainRenewalBlocked $event) => $event->domainId === $domain->id);
})->with([
    'suspended service' => [['linked_service_status' => 'suspended', 'linked_service_due_date' => '2027-01-31']],
    'different due date' => [['linked_service_status' => 'active', 'linked_service_due_date' => '2027-02-15']],
]);

test('a free domain renews with its active linked service and its zero-total invoice pays itself', function () {
    $free = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake', 'recurring_amount' => 0, 'linked_service_id' => 77]);
    $paid = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);
    event(new ServiceActivated(77, $free->client_id, '2027-01-31'));

    expect(app(IssueRenewalInvoices::class)->handle(CarbonImmutable::parse('2027-01-05')))->toBe(2);

    $zeroInvoice = collect($this->invoicer->invoices)->search(fn ($invoice) => $invoice['draft']->lines[0]->billableId === $free->id);
    expect($this->invoicer->status($zeroInvoice))->toBe('paid')
        ->and($free->fresh()->expiry_date->toDateString())->toBe('2028-01-31')
        ->and($paid->fresh()->expiry_date->toDateString())->toBe('2027-01-31')
        ->and(DomainInvoice::query()->where('domain_id', $free->id)->sole()->status)->toBe('paid');

    expect(app(IssueRenewalInvoices::class)->handle(CarbonImmutable::parse('2027-01-06')))->toBe(0);
});

test('turning auto-renew off cancels the unpaid renewal invoice', function () {
    $domain = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id]);
    app(IssueRenewalInvoices::class)->handle(CarbonImmutable::parse('2027-01-05'));
    $invoiceId = array_key_first($this->invoicer->invoices);

    app(SetAutoRenew::class)->handle($domain, false);

    expect($this->invoicer->status($invoiceId))->toBe('cancelled')
        ->and($domain->fresh()->auto_renew)->toBeFalse();
    $this->assertDatabaseHas('domain_invoices', ['invoice_id' => $invoiceId, 'status' => 'cancelled']);
});

test('expiry runs grace, redemption and expiry in order and re-runs change nothing', function () {
    Event::fake([DomainExpired::class]);
    $this->com->update(['grace_days' => 30, 'redemption_days' => 30]);
    $domain = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id]);
    app(IssueRenewalInvoices::class)->handle(CarbonImmutable::parse('2027-01-05'));
    $invoiceId = array_key_first($this->invoicer->invoices);

    $this->artisan('automation:run', ['--date' => '2027-02-01', '--only' => 'domains.lifecycle']);
    expect($domain->fresh()->status)->toBe(DomainStatus::Grace)
        ->and($this->invoicer->status($invoiceId))->toBe('unpaid');

    $this->artisan('automation:run', ['--date' => '2027-03-02', '--only' => 'domains.lifecycle']);
    expect($domain->fresh()->status)->toBe(DomainStatus::Grace);

    $this->artisan('automation:run', ['--date' => '2027-03-03', '--only' => 'domains.lifecycle']);
    expect($domain->fresh()->status)->toBe(DomainStatus::Redemption);

    $this->artisan('automation:run', ['--date' => '2027-04-02', '--only' => 'domains.lifecycle']);
    $this->artisan('automation:run', ['--date' => '2027-04-02', '--only' => 'domains.lifecycle', '--force' => true]);
    expect($domain->fresh()->status)->toBe(DomainStatus::Expired)
        ->and($this->invoicer->status($invoiceId))->toBe('cancelled');
    Event::assertDispatchedTimes(DomainExpired::class, 1);
});

test('entering grace with a fee replaces the renewal invoice with renewal plus fee', function () {
    Currency::factory()->euro()->create();
    $this->com->update(['grace_days' => 30, 'grace_fee' => 1000]);
    $domain = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id, 'currency' => 'EUR', 'recurring_amount' => 1300]);
    app(IssueRenewalInvoices::class)->handle(CarbonImmutable::parse('2027-01-05'));
    $original = array_key_first($this->invoicer->invoices);

    app(ApplyExpiryLifecycle::class)->handle(CarbonImmutable::parse('2027-02-01'));

    $replacement = $this->invoicer->invoices[array_key_last($this->invoicer->invoices)];
    expect($this->invoicer->status($original))->toBe('cancelled')
        ->and($this->invoicer->invoices)->toHaveCount(2)
        ->and(array_map(fn ($line) => $line->unitAmount, $replacement['draft']->lines))->toBe([1300, 920])
        ->and($replacement['draft']->currency)->toBe('EUR');
    $this->assertDatabaseHas('domain_invoices', ['domain_id' => $domain->id, 'kind' => 'grace', 'status' => 'open']);
});

test('without grace or redemption the unpaid renewal is cancelled the day after expiry', function () {
    $domain = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id]);
    app(IssueRenewalInvoices::class)->handle(CarbonImmutable::parse('2027-01-05'));
    $invoiceId = array_key_first($this->invoicer->invoices);

    app(ApplyExpiryLifecycle::class)->handle(CarbonImmutable::parse('2027-01-31'));
    expect($domain->fresh()->status)->toBe(DomainStatus::Active);

    app(ApplyExpiryLifecycle::class)->handle(CarbonImmutable::parse('2027-02-01'));
    expect($domain->fresh()->status)->toBe(DomainStatus::Expired)
        ->and($this->invoicer->status($invoiceId))->toBe('cancelled');
});

test('the global switch disables grace and redemption', function () {
    config(['domains.grace_redemption_enabled' => false]);
    $this->com->update(['grace_days' => 30, 'redemption_days' => 30]);
    $domain = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id]);

    app(ApplyExpiryLifecycle::class)->handle(CarbonImmutable::parse('2027-02-01'));

    expect($domain->fresh()->status)->toBe(DomainStatus::Expired);
});

test('every due domain is invoiced even beyond one chunk', function () {
    $client = Client::factory()->create();
    $start = CarbonImmutable::parse('2027-01-31');

    // Later ids get earlier invoice dates, the order that made date-ordered chunking skip rows.
    foreach (range(0, 204) as $index) {
        Domain::factory()->expiring($start->subDays($index)->toDateString())->create(['tld_id' => $this->com->id, 'client_id' => $client->id]);
    }

    expect(app(IssueRenewalInvoices::class)->handle(CarbonImmutable::parse('2027-01-05')))->toBe(205)
        ->and($this->invoicer->issued())->toHaveCount(205);
});
