<?php

use App\Data\Billing\InvoiceLineData;
use App\Enums\BillableType;
use App\Events\Billing\InvoicePaid;
use App\Events\Domains\DomainRenewed;
use App\Events\Domains\RegistrarActionFailed;
use App\Modules\Domains\Actions\PerformRenewal;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Exceptions\RegistrarException;
use App\Modules\Domains\Jobs\RegisterDomain;
use App\Modules\Domains\Jobs\RenewDomain;
use App\Modules\Domains\Jobs\TransferDomain;
use App\Modules\Domains\Models\Domain;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\Domains\DomainFixtures;
use Tests\Fakes\Domains\FakeInvoicer;
use Tests\Fakes\Domains\FakeRegistrar;

beforeEach(function () {
    $this->registrar = FakeRegistrar::install();
    $this->com = DomainFixtures::com();
});

function domainLine(Domain $domain, ?string $periodStart = null, int $years = 1): InvoiceLineData
{
    $start = $periodStart === null ? null : CarbonImmutable::parse($periodStart);

    return new InvoiceLineData("Domain {$domain->name}", 1400, billableType: BillableType::Domain, billableId: $domain->id,
        periodStart: $start, periodEnd: $start?->addYears($years));
}

test('paying the registration invoice queues the registration exactly once', function () {
    Queue::fake();
    $domain = Domain::factory()->pending()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);
    $event = new InvoicePaid(501, $domain->client_id, [domainLine($domain)]);

    event($event);
    event($event);

    Queue::assertPushed(RegisterDomain::class, 1);
    Queue::assertPushed(RegisterDomain::class, fn (RegisterDomain $job) => $job->domainId === $domain->id);
    expect($domain->fresh()->provision_invoice_id)->toBe(501);
});

test('a paid transfer queues a transfer job', function () {
    Queue::fake();
    $domain = Domain::factory()->pending('transfer')->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);

    event(new InvoicePaid(502, $domain->client_id, [domainLine($domain)]));

    Queue::assertPushed(TransferDomain::class, 1);
    Queue::assertNotPushed(RegisterDomain::class);
});

test('without auto-register the paid domain waits for staff', function () {
    Queue::fake();
    $this->com->update(['auto_register' => false]);
    $domain = Domain::factory()->pending()->create(['tld_id' => $this->com->id]);

    event(new InvoicePaid(503, $domain->client_id, [domainLine($domain)]));

    Queue::assertNothingPushed();
    $this->assertDatabaseHas('activity_log', ['subject_id' => $domain->id, 'description' => "Staff task: accept the paid register of {$domain->name}"]);
});

test('lines for other billable types are ignored', function () {
    Queue::fake();
    $domain = Domain::factory()->pending()->create(['tld_id' => $this->com->id]);

    event(new InvoicePaid(504, $domain->client_id, [new InvoiceLineData('Hosting', 500, billableType: BillableType::Service, billableId: $domain->id)]));

    Queue::assertNothingPushed();
    expect($this->registrar->calls)->toBe([]);
});

test('an unpaid or partially paid renewal never sends a renew command', function () {
    $invoicer = FakeInvoicer::bind();
    $domain = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);
    $this->travelTo(CarbonImmutable::parse('2027-01-01'));
    $this->artisan('automation:run', ['--only' => 'domains.renewals'])->assertSuccessful();

    expect($invoicer->issued())->toHaveCount(1)
        ->and($this->registrar->commands())->not->toContain('renew')
        ->and($domain->fresh()->expiry_date->toDateString())->toBe('2027-01-31');
});

test('a paid two-year renewal advances expiry and due dates by two years', function () {
    Event::fake([DomainRenewed::class]);
    $domain = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake', 'period_years' => 2]);

    event(new InvoicePaid(601, $domain->client_id, [domainLine($domain, '2027-01-31', 2)]));

    expect($domain->fresh())
        ->expiry_date->toDateString()->toBe('2029-01-31')
        ->next_due_date->toDateString()->toBe('2029-01-31')
        ->status->toBe(DomainStatus::Active)
        ->and($this->registrar->commands())->toBe(['renew']);
    Event::assertDispatched(DomainRenewed::class, fn (DomainRenewed $event) => $event->domainId === $domain->id && $event->years === 2);
});

test('replaying a paid renewal invoice renews only once', function () {
    Queue::fake();
    $domain = Domain::factory()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);
    $event = new InvoicePaid(602, $domain->client_id, [domainLine($domain, $domain->next_due_date->toDateString())]);

    event($event);
    event($event);

    Queue::assertPushed(RenewDomain::class, 1);
});

test('a renewal retried after the first attempt succeeded is not sent again', function () {
    $domain = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);
    $job = new RenewDomain($domain->id, 1, '2027-01-31');

    $job->handle(app(PerformRenewal::class));
    $job->handle(app(PerformRenewal::class));

    expect($domain->fresh()->expiry_date->toDateString())->toBe('2028-01-31')
        ->and($this->registrar->commands())->toBe(['renew']);
});

test('a renewal paid during redemption asks the registrar to restore', function () {
    $domain = Domain::factory()->expiring('2026-08-01')->status(DomainStatus::Redemption)->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);

    event(new InvoicePaid(603, $domain->client_id, [domainLine($domain, '2026-08-01')]));

    expect($this->registrar->commands())->toBe(['restore'])
        ->and($domain->fresh()->status)->toBe(DomainStatus::Active);
});

test('a failed renewal alerts staff and keeps the dates', function () {
    Event::fake([RegistrarActionFailed::class]);
    $this->registrar->will('renew', new RegistrarException('Insufficient registrar balance'));
    $domain = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);

    event(new InvoicePaid(604, $domain->client_id, [domainLine($domain, '2027-01-31')]));

    expect($domain->fresh())->expiry_date->toDateString()->toBe('2027-01-31')->last_error->toBe('Insufficient registrar balance');
    Event::assertDispatched(RegistrarActionFailed::class, fn (RegistrarActionFailed $event) => $event->action === 'renew');
});

test('a manual domain is extended locally with a staff task', function () {
    $domain = Domain::factory()->manual()->expiring('2027-01-31')->create(['tld_id' => $this->com->id]);

    event(new InvoicePaid(605, $domain->client_id, [domainLine($domain, '2027-01-31')]));

    expect($domain->fresh()->expiry_date->toDateString())->toBe('2028-01-31');
    $this->assertDatabaseHas('activity_log', ['subject_id' => $domain->id, 'description' => "Staff task: renew {$domain->name} for 1 year(s) at the registrar"]);
});

test('an expired domain is not renewed automatically on payment', function () {
    $domain = Domain::factory()->expiring('2026-01-31')->status(DomainStatus::Expired)->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);

    event(new InvoicePaid(606, $domain->client_id, [domainLine($domain, '2026-01-31')]));

    expect($domain->fresh()->status)->toBe(DomainStatus::Expired)
        ->and($this->registrar->commands())->toBe([]);
});

test('anchored renewals keep a leap-day expiry', function () {
    $domain = Domain::factory()->expiring('2028-02-29')->create(['tld_id' => $this->com->id, 'registrar_key' => null]);

    app(PerformRenewal::class)->handle($domain, 1);
    expect($domain->fresh()->expiry_date->toDateString())->toBe('2029-02-28');

    app(PerformRenewal::class)->handle($domain->fresh(), 3);
    expect($domain->fresh()->expiry_date->toDateString())->toBe('2032-02-29');
});
