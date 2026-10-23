<?php

use App\Contracts\Core\CompanyClock;
use App\Modules\Core\Models\Currency;
use App\Modules\Platform\Models\ExchangeRateHistory;
use App\Modules\Platform\Models\StaffInvitation;
use App\Modules\Platform\Services\ExchangeRateStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * Platform reads "today" from the company clock (ADR 0013 Amendment 1). Each case sits on either side of the company
 * midnight: 23:59:59 and 00:00:00 in Asia/Kathmandu (UTC+5:45) and in America/New_York (UTC-4 in October).
 * `[zone, instant before midnight, instant at midnight, the company date before, the company date after]`
 */
afterEach(fn () => CarbonImmutable::setTestNow());

dataset('company midnight', [
    'Kathmandu' => ['Asia/Kathmandu', '2026-10-18 18:14:59', '2026-10-18 18:15:00', '2026-10-18', '2026-10-19'],
    'New York' => ['America/New_York', '2026-10-19 03:59:59', '2026-10-19 04:00:00', '2026-10-18', '2026-10-19'],
]);

it('prunes invitations before the start of the company day the retention reaches', function (string $zone, string $before, string $at, string $dayBefore, string $dayAfter) {
    inCompanyTimezone($zone);
    $runDate = CarbonImmutable::parse($dayAfter, 'UTC');
    [$expiredStart] = app(CompanyClock::class)->dayBounds($runDate->subDays(30));
    [$usedStart] = app(CompanyClock::class)->dayBounds($runDate->subDays(90));
    $oldExpired = StaffInvitation::factory()->create(['expires_at' => $expiredStart->subSecond()]);
    $keptExpired = StaffInvitation::factory()->create(['expires_at' => $expiredStart]);
    $oldUsed = StaffInvitation::factory()->create(['expires_at' => $usedStart->addDays(7), 'accepted_at' => $usedStart->subSecond()]);
    $keptUsed = StaffInvitation::factory()->create(['expires_at' => $usedStart->addDays(7), 'accepted_at' => $usedStart]);

    $this->artisan('automation:run', ['--only' => 'platform.prune_invitations', '--date' => $dayAfter])->assertSuccessful();

    expect(StaffInvitation::query()->orderBy('id')->pluck('id')->all())->toBe([$keptExpired->id, $keptUsed->id])->and($oldExpired->id)->not->toBe($oldUsed->id);
})->with('company midnight');

it('prunes the activity log before the start of the company day the retention reaches', function (string $zone, string $before, string $at, string $dayBefore, string $dayAfter) {
    inCompanyTimezone($zone);
    [$start] = app(CompanyClock::class)->dayBounds(CarbonImmutable::parse($dayAfter, 'UTC')->subDays(365));
    DB::table('activity_log')->insert([
        ['description' => 'Old', 'client_id' => null, 'created_at' => $start->subSecond()],
        ['description' => 'Kept', 'client_id' => null, 'created_at' => $start],
    ]);

    $this->artisan('automation:run', ['--only' => 'platform.prune_activity_log', '--date' => $dayAfter])->assertSuccessful();

    expect(DB::table('activity_log')->pluck('description')->all())->toBe(['Kept']);
})->with('company midnight');

it('draws the exchange-rate series on company days', function (string $zone, string $before, string $at, string $dayBefore, string $dayAfter) {
    inCompanyTimezone($zone);
    CarbonImmutable::setTestNow(CarbonImmutable::parse($at, 'UTC'));
    $currency = Currency::factory()->create(['code' => 'EUR']);
    // The rate changes at the first moment of $dayAfter; the series runs to the company today.
    [$changeAt] = app(CompanyClock::class)->dayBounds($dayAfter);
    ExchangeRateHistory::query()->create(['currency' => 'EUR', 'rate' => '1', 'source' => 'manual', 'effective_at' => $changeAt->subDays(10)]);
    ExchangeRateHistory::query()->create(['currency' => 'EUR', 'rate' => '2', 'source' => 'manual', 'effective_at' => $changeAt]);

    foreach ([[$before, $dayBefore, 1.0], [$at, $dayAfter, 2.0]] as [$instant, $lastDay, $lastRate]) {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($instant, 'UTC'));
        $series = app(ExchangeRateStatus::class)->dailySeries($currency, 3);

        expect(array_column($series, 'x'))->toBe([CarbonImmutable::parse($lastDay)->subDays(2)->toDateString(), CarbonImmutable::parse($lastDay)->subDay()->toDateString(), $lastDay])
            ->and(end($series)['y'])->toBe($lastRate);
    }
})->with('company midnight');
