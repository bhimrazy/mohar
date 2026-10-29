<?php

use App\Contracts\Core\CompanyClock;
use App\Contracts\Services\ServiceRegistry;
use App\Data\Billing\InvoiceLineData;
use App\Data\Services\ServiceDraft;
use App\Enums\BillableType;
use App\Enums\BillingCycle;
use App\Events\Billing\InvoicePaid;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Actions\PruneModuleLog;
use App\Modules\Services\Actions\QuoteServiceChange;
use App\Modules\Services\Enums\CancellationStatus;
use App\Modules\Services\Enums\ChangeKind;
use App\Modules\Services\Enums\ChangeStatus;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\CancellationRequest;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceChange;
use App\Support\Dashboard\Dashboard;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/*
 * Services reads "today" from the company clock (ADR 0013 Amendment 1). Each case freezes a UTC instant on either side
 * of the company midnight: 23:59:59 and 00:00:00 in Asia/Kathmandu (UTC+5:45) and in America/New_York (UTC-4 in October).
 * `[zone, instant before midnight, instant at midnight, the company date before, the company date after]`
 */
beforeEach(function () {
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
});
afterEach(fn () => CarbonImmutable::setTestNow());

dataset('company midnight', [
    'Kathmandu' => ['Asia/Kathmandu', '2026-10-18 18:14:59', '2026-10-18 18:15:00', '2026-10-18', '2026-10-19'],
    'New York' => ['America/New_York', '2026-10-19 03:59:59', '2026-10-19 04:00:00', '2026-10-18', '2026-10-19'],
]);

function atUtc(string $zone, string $instant): void
{
    inCompanyTimezone($zone);
    CarbonImmutable::setTestNow(CarbonImmutable::parse($instant, 'UTC'));
}

it('flags a service overdue from the first moment of the company day after its due date', function (string $zone, string $before, string $at, string $dayBefore) {
    actingAsStaff();
    $service = Service::factory()->dueOn($dayBefore)->create();

    atUtc($zone, $before);
    $this->get(route('admin.services.index', ['overdue' => 1]))->assertInertia(fn (Assert $page) => $page->has('services.data', 0));
    $this->get(route('admin.services.index'))->assertInertia(fn (Assert $page) => $page->where('services.data.0.is_overdue', false));

    atUtc($zone, $at);
    $this->get(route('admin.services.index', ['overdue' => 1]))->assertInertia(fn (Assert $page) => $page->where('services.data.0.id', $service->id));
    $this->get(route('admin.services.index'))->assertInertia(fn (Assert $page) => $page->where('services.data.0.is_overdue', true));
})->with('company midnight');

it('dates an immediate cancellation and an addon on the company day', function (string $zone, string $before, string $at, string $dayBefore, string $dayAfter) {
    actingAsStaff();
    $service = Service::factory()->dueOn('2027-03-01')->create();

    foreach ([[$before, $dayBefore], [$at, $dayAfter]] as [$instant, $date]) {
        atUtc($zone, $instant);
        confirmPassword();
        $this->post(route('admin.services.services.cancel', $service), ['type' => 'immediate', 'reason' => 'Moving'])->assertSessionHasNoErrors();
        $request = CancellationRequest::query()->latest('id')->firstOrFail();
        expect($request->process_after->toDateString())->toBe($date);
        $request->update(['status' => CancellationStatus::Voided]);

        $this->post(route('admin.services.services.addons.store', $service), ['name' => 'Extra IP', 'cycle' => 'monthly', 'recurring_amount' => 400, 'setup_fee' => 0, 'status' => 'active'])->assertSessionHasNoErrors();
        expect($service->addons()->latest('id')->firstOrFail()->registration_date->toDateString())->toBe($date);
    }
})->with('company midnight');

it('sets the termination date of a service on the company day', function (string $zone, string $before, string $at, string $dayBefore, string $dayAfter) {
    actingAsStaff();

    foreach ([[$before, $dayBefore], [$at, $dayAfter]] as [$instant, $date]) {
        atUtc($zone, $instant);
        confirmPassword();
        $service = Service::factory()->create();
        $this->post(route('admin.services.services.status', $service), ['status' => 'terminated'])->assertSessionHasNoErrors();
        expect($service->refresh())->status->toBe(ServiceStatus::Terminated)->and($service->termination_date->toDateString())->toBe($date);
    }
})->with('company midnight');

it('picks the MRR month-end points from the company month', function (string $zone, string $before, string $at) {
    Service::factory()->create(['recurring_amount' => 1_000, 'registration_date' => '2026-10-15']);
    $staff = actingAsStaff();
    // Point 10 is the end of the previous month: 30 Sep until the company clock reaches 1 Nov, then 31 Oct.
    $point = fn () => collect(app(Dashboard::class)->resolve('admin', $staff))->firstWhere('key', 'services.mrr')['sparkline'][10];

    atUtc($zone, $before);
    expect($point())->toBe(0);

    atUtc($zone, $at);
    expect($point())->toBe(1_000);
})->with([
    'Kathmandu' => ['Asia/Kathmandu', '2026-10-31 18:14:59', '2026-10-31 18:15:00'],
    'New York' => ['America/New_York', '2026-11-01 03:59:59', '2026-11-01 04:00:00'],
]);

it('lists a renewal due on the company date in the dashboard widget until the day is over', function (string $zone, string $before, string $at, string $dayBefore) {
    $staff = actingAsStaff();
    Service::factory()->dueOn($dayBefore)->create();
    $items = fn () => collect(app(Dashboard::class)->resolveWidgets('admin', $staff))->firstWhere('key', 'services.renewals')['items'];

    atUtc($zone, $before);
    expect($items())->toHaveCount(1);

    atUtc($zone, $at);
    expect($items())->toHaveCount(0);
})->with('company midnight');

it('prunes the module log before the start of the company day the retention reaches', function (string $zone, string $before, string $at, string $dayBefore, string $dayAfter) {
    inCompanyTimezone($zone);
    $service = Service::factory()->create();
    // Retention is 30 days: the run date `$dayAfter` keeps entries from the start of the company day 30 days earlier.
    $cutoff = CarbonImmutable::parse($dayAfter)->subDays(30);
    [$start] = app(CompanyClock::class)->dayBounds($cutoff);
    $make = fn (CarbonImmutable $createdAt) => tap(ModuleCommand::query()->create(['service_id' => $service->id, 'driver' => 'log', 'command' => 'create', 'initiator' => 'system', 'success' => true, 'status' => ModuleCommand::RUNNING]), fn (ModuleCommand $row) => $row->forceFill(['created_at' => $createdAt])->saveQuietly());
    $old = $make($start->subSecond());
    $kept = $make($start);

    expect(app(PruneModuleLog::class)->handle(CarbonImmutable::parse($dayAfter)))->toBe(1)
        ->and(ModuleCommand::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(ModuleCommand::query()->whereKey($kept->id)->exists())->toBeTrue();
})->with('company midnight');

it('starts a service ordered at the company midnight on the company date', function (string $zone, string $before, string $at, string $dayBefore, string $dayAfter) {
    $client = Client::factory()->create();
    $draft = fn () => new ServiceDraft($client->id, 1, BillingCycle::Monthly, 'USD', 1_000, 1_000, CarbonImmutable::parse('2027-01-01'));

    foreach ([[$before, $dayBefore], [$at, $dayAfter]] as [$instant, $date]) {
        atUtc($zone, $instant);
        confirmPassword();
        $service = Service::query()->findOrFail(app(ServiceRegistry::class)->createPending($draft()));
        expect($service->registration_date->toDateString())->toBe($date)->and($service->next_due_date->toDateString())->toBe($date);
    }
})->with('company midnight');

it('accepts an import due date of the company today and refuses the day before', function (string $zone, string $before, string $at, string $dayBefore, string $dayAfter) {
    $server = Server::factory()->create(['driver' => 'cpanel', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    ServerGroup::factory()->create()->servers()->attach($server, ['sort_order' => 0]);
    Whm::fake(['listaccts' => Whm::fixture('listaccts_example', ['data' => ['acct' => [...Whm::fixture('listaccts_page1')['data']['acct'], ...Whm::fixture('listaccts_page2')['data']['acct']]]])]);
    actingAsStaff();
    confirmPassword();
    $this->post(route('admin.services.servers.import.fetch', $server))->assertSessionHasNoErrors();
    $put = fn (string $date) => $this->put(route('admin.services.servers.import.select', $server), ['ticked' => true, 'next_due_date' => $date]);

    foreach ([$before, $at] as $instant) {
        atUtc($zone, $instant);
        confirmPassword();
        $today = app(CompanyClock::class)->todayDate()->toDateString();
        $put($today)->assertSessionDoesntHaveErrors('next_due_date');
        $put(CarbonImmutable::parse($today)->subDay()->toDateString())->assertSessionHasErrors('next_due_date');
    }
})->with('company midnight');

it('counts a change applied just after the company midnight of the window start, not one just before', function (string $zone, string $justBefore, string $justAfter) {
    inCompanyTimezone($zone);
    $service = Service::factory()->create(['recurring_amount' => 1_000]);
    $change = fn (string $at, int $amount) => ServiceChange::query()->create([
        'service_id' => $service->id, 'kind' => ChangeKind::Product, 'from_snapshot' => ['recurring_amount' => 1_000],
        'to_snapshot' => ['recurring_amount' => $amount], 'currency' => 'USD', 'status' => ChangeStatus::Applied,
        'applied_at' => CarbonImmutable::parse($at, 'UTC'),
    ]);
    $quote = app(QuoteServiceChange::class);
    $windowStart = CarbonImmutable::parse('2026-11-01');
    $end = CarbonImmutable::parse('2026-12-01');

    $change($justBefore, 3_000);
    expect($quote->paidAmountFor($service, $windowStart, $end))->not->toBe(3_000);

    $change($justAfter, 5_000);
    expect($quote->paidAmountFor($service, $windowStart, $end))->toBe(5_000);
})->with([
    // 1 Nov 00:00 in Kathmandu is 31 Oct 18:15 UTC; in New York (EDT) it is 1 Nov 04:00 UTC.
    'Kathmandu' => ['Asia/Kathmandu', '2026-10-31 18:14:59', '2026-10-31 18:15:00'],
    'New York' => ['America/New_York', '2026-11-01 03:59:59', '2026-11-01 04:00:00'],
]);

it('lifts an overdue suspension on payment while the due day is still today, not after the company midnight', function (string $zone, string $before, string $at, string $dayBefore) {
    $service = Service::factory()->dueOn($dayBefore)->onServer()->create();
    $this->artisan('automation:run', ['--date' => $dayBefore, '--only' => 'services.renewals'])->assertSuccessful(); // the renewal for [due, due+1m) is open
    $paidEarlier = fn () => event(new InvoicePaid(9000 + $service->id, $service->client_id, [new InvoiceLineData('Earlier period', 1000, 1, true, BillableType::Service, $service->id, CarbonImmutable::parse($dayBefore)->subMonth(), CarbonImmutable::parse($dayBefore))]));

    foreach ([[$before, ServiceStatus::Active], [$at, ServiceStatus::Suspended]] as [$instant, $expected]) {
        $service->forceFill(['status' => ServiceStatus::Suspended, 'suspension_reason' => 'overdue'])->save();
        atUtc($zone, $instant);
        confirmPassword();
        $paidEarlier();
        expect($service->refresh()->status)->toBe($expected);
    }
})->with('company midnight');

it('dates an imported account on the company calendar of its creation instant', function (string $zone, string $createdAt, string $date) {
    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    inCompanyTimezone($zone);
    $server = Server::factory()->create(['driver' => 'cpanel', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    $group = ServerGroup::factory()->create();
    $group->servers()->attach($server, ['sort_order' => 0]);
    $this->kit->priceBook->addProduct(21, 'Basic hosting', ['monthly' => 1200], driver: 'cpanel', serverGroupId: $group->id, driverConfig: ['package' => 'root_basic']);
    Client::factory()->create(['email' => 'anna@alpha-books.com', 'currency' => 'USD']);
    $alpha = [...Whm::fixture('listaccts_page1')['data']['acct'][0], 'unix_startdate' => CarbonImmutable::parse($createdAt, 'UTC')->getTimestamp()];
    Whm::fake(['listaccts' => Whm::fixture('listaccts_example', ['data' => ['acct' => [$alpha]]])]);
    actingAsStaff();
    confirmPassword();
    $this->post(route('admin.services.servers.import.fetch', $server))->assertSessionHasNoErrors();
    $this->put(route('admin.services.servers.import.select', $server), ['proposed' => true])->assertSessionHasNoErrors();
    $review = $this->get(route('admin.services.servers.import.review', $server))->assertOk();
    $row = collect($review->inertiaProps('rows'))->keyBy('username')['alpha'];
    $this->post(route('admin.services.servers.import.apply', $server), ['digest' => $review->inertiaProps('digest')])->assertSessionHasNoErrors();

    $anniversary = CarbonImmutable::parse($date)->setYear(2026)->toDateString();
    expect(Service::query()->sole()->registration_date->toDateString())->toBe($date)
        ->and($row['decision']['next_due_date'])->toBe($anniversary)
        ->and($row['decision']['anchor_day'])->toBe((int) substr($date, 8));
})->with([
    // 2023-11-14 18:15 UTC is 00:00 on the 15th in Kathmandu; 05:00 UTC is 00:00 on the 14th in New York (EST).
    'Kathmandu before midnight' => ['Asia/Kathmandu', '2023-11-14 18:14:59', '2023-11-14'],
    'Kathmandu at midnight' => ['Asia/Kathmandu', '2023-11-14 18:15:00', '2023-11-15'],
    'New York before midnight' => ['America/New_York', '2023-11-14 04:59:59', '2023-11-13'],
    'New York at midnight' => ['America/New_York', '2023-11-14 05:00:00', '2023-11-14'],
]);
