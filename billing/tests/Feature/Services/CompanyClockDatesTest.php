<?php

use App\Contracts\Core\CompanyClock;
use App\Contracts\Services\ServiceRegistry;
use App\Data\Services\ServiceDraft;
use App\Enums\BillingCycle;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Actions\PruneModuleLog;
use App\Modules\Services\Enums\CancellationStatus;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\CancellationRequest;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
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
        $today = app(CompanyClock::class)->todayDate()->toDateString();
        $put($today)->assertSessionDoesntHaveErrors('next_due_date');
        $put(CarbonImmutable::parse($today)->subDay()->toDateString())->assertSessionHasErrors('next_due_date');
    }
})->with('company midnight');
