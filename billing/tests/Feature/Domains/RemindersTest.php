<?php

use App\Events\Domains\DomainExpiring;
use App\Events\Domains\DomainExpiryDigest;
use App\Modules\Core\Models\Client;
use App\Modules\Domains\Actions\SendExpiryReminders;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\DomainReminder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\Domains\DomainFixtures;

beforeEach(function () {
    $this->com = DomainFixtures::com();
});

test('one reminder is logged per offset and expiry cycle and re-runs send nothing', function () {
    Event::fake([DomainExpiring::class, DomainExpiryDigest::class]);
    $domain = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id]);
    $reminders = app(SendExpiryReminders::class);

    foreach (['2027-01-01', '2027-01-24', '2027-02-03'] as $day) {
        expect($reminders->handle(CarbonImmutable::parse($day)))->toBe(1)
            ->and($reminders->handle(CarbonImmutable::parse($day)))->toBe(0);
    }
    expect($reminders->handle(CarbonImmutable::parse('2027-01-15')))->toBe(0);

    expect(DomainReminder::query()->where('domain_id', $domain->id)->orderBy('id')->pluck('offset_days')->all())->toBe([30, 7, -3]);
    Event::assertDispatchedTimes(DomainExpiring::class, 3);
    Event::assertDispatched(DomainExpiring::class, fn (DomainExpiring $event) => $event->daysLeft === -3 && $event->domainId === $domain->id);
});

test('domains due on the same day are aggregated into one reminder per client', function () {
    Event::fake([DomainExpiring::class, DomainExpiryDigest::class]);
    $client = Client::factory()->create();
    Domain::factory()->count(3)->expiring('2027-01-31')->create(['tld_id' => $this->com->id, 'client_id' => $client->id]);
    Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id]);

    app(SendExpiryReminders::class)->handle(CarbonImmutable::parse('2027-01-01'));

    Event::assertDispatchedTimes(DomainExpiryDigest::class, 2);
    Event::assertDispatched(DomainExpiryDigest::class, fn (DomainExpiryDigest $event) => $event->clientId === $client->id
        && count($event->domains) === 3 && $event->domains[0]['days_left'] === 30);
});

test('auto-renew-off and free domains follow the reminder settings', function () {
    Event::fake([DomainExpiring::class, DomainExpiryDigest::class]);
    config(['domains.remind_when_auto_renew_off' => false, 'domains.remind_free_domains' => false]);
    Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id, 'auto_renew' => false]);
    Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id, 'recurring_amount' => 0]);

    expect(app(SendExpiryReminders::class)->handle(CarbonImmutable::parse('2027-01-01')))->toBe(0);
});

test('a missed automation day is caught up without repeating or skipping ahead', function () {
    Event::fake([DomainExpiring::class, DomainExpiryDigest::class]);
    $domain = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id]);
    $reminders = app(SendExpiryReminders::class);

    expect($reminders->handle(CarbonImmutable::parse('2027-01-03')))->toBe(1)
        ->and($reminders->handle(CarbonImmutable::parse('2027-01-04')))->toBe(0);

    Event::assertDispatched(DomainExpiring::class, fn (DomainExpiring $event) => $event->daysLeft === 28);
    expect(DomainReminder::query()->where('domain_id', $domain->id)->sole())
        ->offset_days->toBe(30)
        ->dispatched_at->not->toBeNull();
});

test('domains whose renewal for the cycle is paid get no reminder', function () {
    Event::fake([DomainExpiring::class, DomainExpiryDigest::class]);
    $domain = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id]);
    $domain->invoices()->create(['invoice_id' => 5, 'kind' => 'renewal', 'period_start' => '2027-01-31', 'years' => 1, 'status' => 'paid']);

    expect(app(SendExpiryReminders::class)->handle(CarbonImmutable::parse('2027-01-01')))->toBe(0);
});
