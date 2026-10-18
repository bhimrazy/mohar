<?php

use App\Enums\BillingCycle;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Service;
use App\Support\Dashboard\Dashboard;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo('2026-10-18 12:00:00');
    Currency::factory()->create();
    Currency::factory()->inr()->create();
});

function mrrCard(array $permissions = ['services.*']): ?array
{
    return collect(app(Dashboard::class)->resolve('admin', actingAsStaff($permissions)))->firstWhere('key', 'services.mrr');
}

test('MRR normalises every cycle to a month and consolidates currencies', function () {
    Service::factory()->create(['cycle' => BillingCycle::Monthly, 'recurring_amount' => 1_500]);
    Service::factory()->create(['cycle' => BillingCycle::Annually, 'recurring_amount' => 12_000]); // 1,000 a month
    Service::factory()->suspended()->create(['cycle' => BillingCycle::Quarterly, 'recurring_amount' => 3_000]); // 1,000
    Service::factory()->create(['currency' => 'INR', 'cycle' => BillingCycle::Monthly, 'recurring_amount' => 84_000]); // $10
    Service::factory()->pending()->create(['recurring_amount' => 9_999]);
    Service::factory()->create(['cycle' => BillingCycle::OneTime, 'recurring_amount' => 50_000]);

    $card = mrrCard();

    expect($card['money'])->toBe(['amount' => 4_500, 'currency' => 'USD'])
        ->and($card['hint'])->toBe('From 4 recurring services')
        ->and($card['variants']['USD']['money'])->toBe(['amount' => 3_500, 'currency' => 'USD'])
        ->and($card['variants']['INR']['money'])->toBe(['amount' => 84_000, 'currency' => 'INR'])
        ->and($card['sparkline'])->toHaveCount(12)
        ->and(collect($card['sparkline'])->last())->toBe(4_500)
        ->and($card['href'])->toBe(route('admin.services.index', ['status' => 'active']));
});

test('MRR history counts a service from registration until it ended', function () {
    Service::factory()->create(['recurring_amount' => 1_000, 'registration_date' => '2026-01-10']);
    Service::factory()->create(['recurring_amount' => 2_000, 'registration_date' => '2026-10-05']);
    Service::factory()->status(ServiceStatus::Cancelled)->create(['recurring_amount' => 500, 'registration_date' => '2025-06-01', 'termination_date' => '2026-10-01']);

    $card = mrrCard();

    // A month ago: the first and the since-cancelled service were billing (1,500); now: 3,000.
    expect($card['sparkline'][0])->toBe(500)                // end of Nov 2025: only the cancelled one
        ->and($card['sparkline'][2])->toBe(1_500)           // end of Jan 2026
        ->and(collect($card['sparkline'])->last())->toBe(3_000)
        ->and($card['trend'])->toMatchArray(['value' => '100%', 'direction' => 'up']);
});

test('MRR is hidden without services access and the provisioning check needs the module log permission', function () {
    expect(mrrCard(['billing.*']))->toBeNull()
        ->and(collect(app(Dashboard::class)->resolveHealth('admin', actingAsStaff(['services.services.view'])))->pluck('key'))->not->toContain('services.provisioning');
});

test('provisioning health reports the 7-day success rate and open failures', function () {
    $log = fn (bool $success, int $daysAgo = 0) => DB::table('module_commands')->insert([
        'driver' => 'log', 'command' => 'create', 'initiator' => 'test', 'success' => $success,
        'status' => $success ? 'succeeded' : 'failed', 'created_at' => now()->subDays($daysAgo),
    ]);
    collect(range(1, 49))->each(fn () => $log(true));
    $log(false);
    $log(false, 10); // outside the window

    $check = collect(app(Dashboard::class)->resolveHealth('admin', actingAsStaff(['services.*'])))->firstWhere('key', 'services.provisioning');

    expect($check)->toMatchArray(['value' => '98%', 'tone' => 'success', 'detail' => '49 of 50 commands succeeded (7 days)']);
});

test('MRR offers every configured currency, so the default-currency view exists without activity in it', function () {
    Service::factory()->create(['currency' => 'INR', 'recurring_amount' => 84_000]);

    $card = mrrCard();

    expect(array_keys($card['variants']))->toBe(['USD', 'INR'])
        ->and($card['variants']['USD'])->toMatchArray(['money' => ['amount' => 0, 'currency' => 'USD'], 'hint' => 'No recurring services'])
        ->and($card['variants']['INR']['money'])->toBe(['amount' => 84_000, 'currency' => 'INR']);
});
