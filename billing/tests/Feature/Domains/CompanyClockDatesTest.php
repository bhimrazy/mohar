<?php

use App\Contracts\Core\CompanyClock;
use App\Contracts\Domains\DomainRegistry;
use App\Contracts\Notifications\MergeFieldRegistry;
use App\Data\Domains\DomainDraft;
use App\Data\Domains\Registrar\RegistrarResult;
use App\Data\Domains\Registrar\TransferSyncResult;
use App\Modules\Core\Models\Client;
use App\Modules\Domains\Actions\SyncDomains;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarCommandLog;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Services\DomainPricingService;
use Carbon\CarbonImmutable;
use Tests\Fakes\Domains\DomainFixtures;
use Tests\Fakes\Domains\FakeInvoicer;
use Tests\Fakes\Domains\FakeRegistrar;

/*
 * Domains reads "today" from the company clock (ADR 0013 Amendment 1). Each case sits on either side of the company
 * midnight: 23:59:59 and 00:00:00 in Asia/Kathmandu (UTC+5:45) and in America/New_York (UTC-4 in October).
 * `[zone, instant before midnight, instant at midnight, the company date before, the company date after]`
 */
beforeEach(function () {
    $this->registrar = FakeRegistrar::install();
    $this->com = DomainFixtures::com();
    $this->invoicer = FakeInvoicer::bind();
});
afterEach(fn () => CarbonImmutable::setTestNow());

dataset('company midnight', [
    'Kathmandu' => ['Asia/Kathmandu', '2026-10-18 18:14:59', '2026-10-18 18:15:00', '2026-10-18', '2026-10-19'],
    'New York' => ['America/New_York', '2026-10-19 03:59:59', '2026-10-19 04:00:00', '2026-10-18', '2026-10-19'],
]);

function domainsAt(string $zone, string $instant): void
{
    inCompanyTimezone($zone);
    CarbonImmutable::setTestNow(CarbonImmutable::parse($instant, 'UTC'));
}

it('dates an ordered and registered domain on the company day', function (string $zone, string $before, string $at, string $dayBefore, string $dayAfter) {
    $client = Client::factory()->create();
    $registry = app(DomainRegistry::class);

    foreach ([[$before, $dayBefore, 'one'], [$at, $dayAfter, 'two']] as [$instant, $date, $name]) {
        domainsAt($zone, $instant);
        $this->registrar->will('register', new RegistrarResult('completed'));
        $id = $registry->createPending(new DomainDraft($client->id, "{$name}.com", 'register', 1, 'USD', 1400, 1200, null, null, []));
        $pending = Domain::query()->findOrFail($id);
        expect($pending->next_due_date->toDateString())->toBe($date)->and($pending->next_invoice_date->toDateString())->toBe($date);

        $registry->process($id);
        $domain = Domain::query()->findOrFail($id);
        expect($domain->registration_date->toDateString())->toBe($date)
            ->and($domain->expiry_date->toDateString())->toBe(CarbonImmutable::parse($date)->addYear()->toDateString());
    }
})->with('company midnight');

it('dates a completed transfer on the company day when the registrar gives no expiry', function (string $zone, string $before, string $at, string $dayBefore, string $dayAfter) {
    foreach ([[$before, $dayBefore], [$at, $dayAfter]] as [$instant, $date]) {
        domainsAt($zone, $instant);
        $domain = Domain::factory()->pending('transfer')->status(DomainStatus::PendingTransfer)->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake', 'billing_anchor_day' => null]);
        $this->registrar->will('transfer_sync', new TransferSyncResult(true));

        app(SyncDomains::class)->handle();

        expect($domain->fresh())->status->toBe(DomainStatus::Active)
            ->registration_date->toDateString()->toBe($date)
            ->expiry_date->toDateString()->toBe(CarbonImmutable::parse($date)->addYears($domain->period_years)->toDateString());
    }
})->with('company midnight');

it('counts a domain as expiring until its expiry day is over on the company calendar', function (string $zone, string $before, string $at, string $dayBefore) {
    Domain::factory()->expiring($dayBefore)->create(['tld_id' => $this->com->id]);

    domainsAt($zone, $before);
    expect(Domain::query()->expiringWithin(30)->count())->toBe(1);

    domainsAt($zone, $at);
    expect(Domain::query()->expiringWithin(30)->count())->toBe(0);
})->with('company midnight');

it('counts the days until expiry from the company date', function (string $zone, string $before, string $at, string $dayBefore, string $dayAfter) {
    $domain = Domain::factory()->expiring(CarbonImmutable::parse($dayAfter)->addDays(10)->toDateString())->create(['tld_id' => $this->com->id]);

    foreach ([[$before, 11], [$at, 10]] as [$instant, $days]) {
        domainsAt($zone, $instant);
        expect(app(MergeFieldRegistry::class)->resolve('domain', $domain->id)['days_until_expiry'])->toBe($days);
    }
})->with('company midnight');

it('opens the renewal window on the company day', function (string $zone, string $before, string $at, string $dayBefore, string $dayAfter) {
    $this->com->update(['renew_window_days' => 180]);
    $domain = Domain::factory()->expiring(CarbonImmutable::parse($dayAfter)->addDays(180)->toDateString())->create(['tld_id' => $this->com->id]);
    $terms = fn () => app(DomainPricingService::class)->renewalTerms($domain->fresh(), 'USD');

    domainsAt($zone, $before);
    expect($terms())->toBe([]);

    domainsAt($zone, $at);
    expect($terms())->not->toBe([]);
})->with('company midnight');

it('invoices a renewal on the company day its invoice date is reached', function (string $zone, string $before, string $at, string $dayBefore, string $dayAfter) {
    Domain::factory()->expiring(CarbonImmutable::parse($dayAfter)->addDays(30)->toDateString())->create(['tld_id' => $this->com->id, 'gateway_key' => 'stripe']);

    domainsAt($zone, $before);
    $this->artisan('automation:run', ['--only' => 'domains.renewals'])->assertSuccessful();
    expect($this->invoicer->issued())->toHaveCount(0);

    domainsAt($zone, $at);
    $this->artisan('automation:run', ['--only' => 'domains.renewals'])->assertSuccessful();
    expect($this->invoicer->issued())->toHaveCount(1);
})->with('company midnight');

it('moves a domain through grace and redemption on company days', function (string $zone, string $before, string $at, string $dayBefore, string $dayAfter) {
    $this->com->update(['grace_days' => 30, 'redemption_days' => 30]);
    // It expired 30 company days before $dayBefore, so the 31st day (and redemption) starts at the company midnight.
    $domain = Domain::factory()->expiring(CarbonImmutable::parse($dayBefore)->subDays(30)->toDateString())->create(['tld_id' => $this->com->id]);

    domainsAt($zone, $before);
    $this->artisan('automation:run', ['--only' => 'domains.lifecycle'])->assertSuccessful();
    expect($domain->fresh()->status)->toBe(DomainStatus::Grace);

    domainsAt($zone, $at);
    $this->artisan('automation:run', ['--only' => 'domains.lifecycle'])->assertSuccessful();
    expect($domain->fresh()->status)->toBe(DomainStatus::Redemption);
})->with('company midnight');

it('prunes the registrar log before the start of the company day the retention reaches', function (string $zone, string $before, string $at, string $dayBefore, string $dayAfter) {
    inCompanyTimezone($zone);
    [$start] = app(CompanyClock::class)->dayBounds(CarbonImmutable::parse($dayAfter, 'UTC')->subDays(90));
    $make = fn (CarbonImmutable $createdAt) => tap(RegistrarCommandLog::query()->create(['registrar_key' => 'fake', 'command' => 'sync', 'success' => true, 'duration_ms' => 1]), fn (RegistrarCommandLog $row) => $row->forceFill(['created_at' => $createdAt])->saveQuietly());
    $old = $make($start->subSecond());
    $kept = $make($start);

    $this->artisan('automation:run', ['--only' => 'domains.command_log_prune', '--date' => $dayAfter])->assertSuccessful();

    expect(RegistrarCommandLog::query()->pluck('id')->all())->toBe([$kept->id]);
})->with('company midnight');

it('dates a log-registrar registration and transfer from the company day, with the due dates in step', function (string $zone, string $before, string $at, string $dayBefore, string $dayAfter) {
    $tld = Tld::factory()->extension('.net')->priced(register: 1500)->create(['registrar_key' => 'log']);
    $client = Client::factory()->create();
    $registry = app(DomainRegistry::class);

    foreach ([[$before, $dayBefore, 'one'], [$at, $dayAfter, 'two']] as [$instant, $date, $name]) {
        domainsAt($zone, $instant);
        $id = $registry->createPending(new DomainDraft($client->id, "{$name}.net", 'register', 1, 'USD', 1400, 1200, null, null, []));
        $registry->process($id);

        $domain = Domain::query()->findOrFail($id);
        $year = CarbonImmutable::parse($date)->addYear()->toDateString();
        expect($domain->status)->toBe(DomainStatus::Active)
            ->and($domain->registration_date->toDateString())->toBe($date)
            ->and($domain->expiry_date->toDateString())->toBe($year)
            ->and($domain->next_due_date->toDateString())->toBe($year);

        $transfer = Domain::factory()->pending('transfer')->status(DomainStatus::PendingTransfer)->create(['tld_id' => $tld->id, 'registrar_key' => 'log', 'billing_anchor_day' => null, 'sld' => "{$name}-in", 'name' => "{$name}-in.net"]);
        app(SyncDomains::class)->handle();
        expect($transfer->fresh())->status->toBe(DomainStatus::Active)->registration_date->toDateString()->toBe($date)
            ->expiry_date->toDateString()->toBe(CarbonImmutable::parse($date)->addYears($transfer->period_years)->toDateString());
    }
})->with('company midnight');
