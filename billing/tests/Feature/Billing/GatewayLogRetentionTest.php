<?php

use App\Contracts\Core\CompanyClock;
use App\Modules\Billing\Models\GatewayLog;
use App\Modules\Core\Support\Settings;
use App\Support\CalendarDate;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => inCompanyTimezone('Asia/Kathmandu'));

function gatewayLogAt(string $result, DateTimeInterface $at): void
{
    DB::table('gateway_log')->insert(['gateway' => 'esewa', 'direction' => 'response', 'result' => $result, 'payload' => json_encode(['ref' => 'x']), 'created_at' => $at]);
}

test('the gateway log keeps 180 days by default, counted from the company midnight', function () {
    [$start] = app(CompanyClock::class)->dayBounds(CalendarDate::parse('2026-10-24')->addDays(-180));
    gatewayLogAt('old', $start->subSecond());
    gatewayLogAt('first kept', $start);
    gatewayLogAt('today', now());

    $this->artisan('automation:run', ['--only' => 'billing.gateway_log_prune', '--date' => '2026-10-24'])->assertSuccessful();

    expect(GatewayLog::query()->orderBy('id')->pluck('result')->all())->toBe(['first kept', 'today']);
});

test('a stored retention below 90 days is read as 90', function () {
    app(Settings::class)->set('billing.gateways.log_retention_days', 10);
    [$start] = app(CompanyClock::class)->dayBounds(CalendarDate::parse('2026-10-24')->addDays(-90));
    gatewayLogAt('old', $start->subSecond());
    gatewayLogAt('kept', $start);

    $this->artisan('automation:run', ['--only' => 'billing.gateway_log_prune', '--date' => '2026-10-24'])->assertSuccessful();

    expect(GatewayLog::query()->pluck('result')->all())->toBe(['kept']);
});

test('the retention setting is in the security tier: its permission, a recent password, and validation', function () {
    $payload = fn (int $days) => ['billing' => ['gateways' => ['log_retention_days' => $days]]];
    actingAsStaff(['core.settings.security']);
    $this->put(route('admin.platform.settings.update', 'billing_retention'), $payload(120))->assertSessionHasErrors('password_confirmation');

    confirmPassword();
    $this->put(route('admin.platform.settings.update', 'billing_retention'), $payload(30))->assertSessionHasErrors('billing.gateways.log_retention_days');
    $this->put(route('admin.platform.settings.update', 'billing_retention'), $payload(120))->assertSessionHasNoErrors();
    expect(app(Settings::class)->get('billing.gateways.log_retention_days'))->toBe(120);

    actingAsStaff(['billing.settings.manage']);
    confirmPassword();
    $this->put(route('admin.platform.settings.update', 'billing_retention'), $payload(150))->assertForbidden();
});

test('the gateway log page runs no count over the log', function () {
    foreach (range(1, 51) as $i) {
        gatewayLogAt("entry {$i}", now());
    }
    actingAsStaff(['billing.gateway_log.view']);
    DB::enableQueryLog();

    $this->get(route('admin.billing.gateway-log.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 50)
            ->missing('entries.total')
            ->where('entries.next_page_url', fn ($url) => is_string($url)));

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains(strtolower($sql), 'count(') && str_contains($sql, 'gateway_log')))->toBeEmpty();
});
