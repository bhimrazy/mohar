<?php

use App\Events\Domains\DomainRegistered;
use App\Events\Domains\DomainTransferFailed;
use App\Events\Domains\DomainTransferred;
use App\Modules\Domains\Actions\SyncDomains;
use App\Modules\Domains\Drivers\Registrars\Data\SyncResult;
use App\Modules\Domains\Drivers\Registrars\Data\TransferSyncResult;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Events\DomainSyncReport;
use App\Modules\Domains\Events\DomainTransferredAway;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Services\RenewalInvoices;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\Domains\DomainFixtures;
use Tests\Fakes\Domains\FakeInvoicer;
use Tests\Fakes\Domains\FakeRegistrar;

beforeEach(function () {
    $this->registrar = FakeRegistrar::install();
    $this->com = DomainFixtures::com();
    $this->invoicer = FakeInvoicer::bind();
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00'));
});

test('a completed transfer activates the domain with the registrar expiry and wipes the EPP code', function () {
    Event::fake([DomainTransferred::class, DomainSyncReport::class]);
    $domain = Domain::factory()->pending('transfer')->status(DomainStatus::PendingTransfer)->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);
    $this->registrar->will('transfer_sync', new TransferSyncResult(true, expiryDate: CarbonImmutable::parse('2028-03-15')));

    app(SyncDomains::class)->handle();

    expect($domain->fresh())
        ->status->toBe(DomainStatus::Active)
        ->expiry_date->toDateString()->toBe('2028-03-15')
        ->next_due_date->toDateString()->toBe('2028-03-15')
        ->epp_code->toBeNull();
    Event::assertDispatched(DomainTransferred::class, fn (DomainTransferred $event) => $event->domainId === $domain->id);
    Event::assertDispatched(DomainSyncReport::class);
});

test('a failed transfer returns to pending with the reason', function () {
    Event::fake([DomainTransferFailed::class, DomainSyncReport::class]);
    $domain = Domain::factory()->pending('transfer')->status(DomainStatus::PendingTransfer)->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);
    $this->registrar->will('transfer_sync', new TransferSyncResult(false, failed: true, reason: 'Invalid auth code'));

    app(SyncDomains::class)->handle();

    expect($domain->fresh())->status->toBe(DomainStatus::Pending)->last_error->toBe('Invalid auth code');
    Event::assertDispatched(DomainTransferFailed::class, fn (DomainTransferFailed $event) => $event->reason === 'Invalid auth code');
});

test('notify-only sync reports a different expiry without changing anything', function () {
    Event::fake([DomainSyncReport::class]);
    config(['domains.sync_notify_only' => true]);
    $domain = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);
    $this->registrar->will('sync', new SyncResult(true, expiryDate: CarbonImmutable::parse('2028-01-31')));

    app(SyncDomains::class)->handle();

    expect($domain->fresh()->expiry_date->toDateString())->toBe('2027-01-31');
    Event::assertDispatched(DomainSyncReport::class, fn (DomainSyncReport $report) => $report->notifyOnly
        && $report->changes[0]['field'] === 'expiry_date' && $report->changes[0]['to'] === '2028-01-31' && ! $report->changes[0]['applied']);
});

test('sync applies a new expiry and moves the due date only without an open renewal invoice', function () {
    $free = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);
    $invoiced = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);
    app(RenewalInvoices::class)->issue($invoiced);
    $this->registrar->will('sync', new SyncResult(true, expiryDate: CarbonImmutable::parse('2028-01-31')));

    app(SyncDomains::class)->handle();

    expect($free->fresh())->expiry_date->toDateString()->toBe('2028-01-31')->next_due_date->toDateString()->toBe('2028-01-31')
        ->and($invoiced->fresh())->expiry_date->toDateString()->toBe('2028-01-31')->next_due_date->toDateString()->toBe('2027-01-31');
});

test('an expiry moving back more than a year is flagged instead of applied', function () {
    $domain = Domain::factory()->expiring('2029-01-31')->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);
    $this->registrar->will('sync', new SyncResult(true, expiryDate: CarbonImmutable::parse('2027-01-31')));

    app(SyncDomains::class)->handle();

    expect($domain->fresh())->expiry_date->toDateString()->toBe('2029-01-31')->last_error->toContain('review');
});

test('sync confirms a registration that timed out', function () {
    Event::fake([DomainRegistered::class]);
    $domain = Domain::factory()->pending()->status(DomainStatus::PendingRegistration)->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);
    $this->registrar->will('sync', new SyncResult(true, expiryDate: CarbonImmutable::parse('2027-10-17')));

    app(SyncDomains::class)->handle();

    expect($domain->fresh())->status->toBe(DomainStatus::Active)->expiry_date->toDateString()->toBe('2027-10-17');
    Event::assertDispatched(DomainRegistered::class);
});

test('a domain transferred away stops invoicing', function () {
    Event::fake([DomainTransferredAway::class]);
    $domain = Domain::factory()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);
    app(RenewalInvoices::class)->issue($domain);
    $this->registrar->will('sync', new SyncResult(false, transferredAway: true));

    app(SyncDomains::class)->handle();

    expect($domain->fresh()->status)->toBe(DomainStatus::TransferredAway)
        ->and($this->invoicer->cancelled)->toHaveCount(1);
    Event::assertDispatched(DomainTransferredAway::class);
});

test('domains are synced in batches, oldest first, at most once per interval', function () {
    config(['domains.sync_batch_size' => 2, 'domains.sync_interval_hours' => 4]);
    $recent = Domain::factory()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake', 'last_synced_at' => now()->subHour()]);
    $old = Domain::factory()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake', 'last_synced_at' => now()->subDays(2)]);
    $never = Domain::factory()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake', 'last_synced_at' => null]);
    $older = Domain::factory()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake', 'last_synced_at' => now()->subDay()]);

    $result = app(SyncDomains::class)->handle();

    expect($result['synced'])->toBe(2)
        ->and(array_column($this->registrar->calls, 'domain'))->toBe([$never->name, $old->name])
        ->and($recent->fresh()->last_sync_status)->toBeNull()
        ->and($older->fresh()->last_sync_status)->toBeNull();
});

test('a driver without sync marks the domain unsupported', function () {
    $this->registrar->capabilities = ['register'];
    $domain = Domain::factory()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);

    app(SyncDomains::class)->handle();

    expect($domain->fresh()->last_sync_status)->toBe('unsupported');
});

test('the sync command runs a batch', function () {
    Domain::factory()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);

    $this->artisan('domains:sync')->assertSuccessful();

    expect($this->registrar->commands())->toBe(['sync']);
});
