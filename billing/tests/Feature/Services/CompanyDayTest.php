<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Enums\ChangeStatus;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceChange;
use App\Modules\Services\Models\UsageSnapshot;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Services\ServicesTestKit;

/*
 * Services tasks receive the run date as a date value; their instants come from dayBounds() (ADR 0013 Amendment 1).
 * The Kathmandu run at 03:00 is 21:15 UTC the day before.
 */

beforeEach(function () {
    $this->kit = ServicesTestKit::bind();
    inCompanyTimezone('Asia/Kathmandu');
});
afterEach(fn () => CarbonImmutable::setTestNow());

it('a337-1: stamps a usage snapshot with the time of the 03:00 run, not a day ahead', function () {
    Currency::factory()->create();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-18 21:15:00', 'UTC'));
    $service = Service::factory()->dueOn('2026-12-01')->onServer()->create();
    $this->kit->driver->rememberAccount((int) $service->server_id, (string) $service->username);

    $this->artisan('automation:run', ['--only' => 'services.usage-sync'])->assertSuccessful();
    expect(UsageSnapshot::query()->sole()->captured_at->utc()->toDateTimeString())->toBe('2026-10-18 21:15:00');

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 04:00:00', 'UTC')); // the same company day
    $this->artisan('automation:run', ['--only' => 'services.usage-sync', '--force' => true])->assertSuccessful();
    expect(UsageSnapshot::query()->count())->toBe(1);
});

it('stamps a snapshot of a past run date at the start of that company day', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-25 06:00:00', 'UTC'));
    $service = Service::factory()->dueOn('2026-12-01')->onServer()->create();
    $this->kit->driver->rememberAccount((int) $service->server_id, (string) $service->username);

    $this->artisan('automation:run', ['--date' => '2026-10-20', '--only' => 'services.usage-sync'])->assertSuccessful();

    expect(UsageSnapshot::query()->sole()->captured_at->utc()->toDateTimeString())->toBe('2026-10-19 18:15:00');
});

it('a337-2: expires an unpaid upgrade at the end of the company day, not 5h45 earlier', function (string $expiresAt, ChangeStatus $status) {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-18 21:15:00', 'UTC')); // 03:00 on 19 Oct
    $service = Service::factory()->create();
    $change = ServiceChange::query()->create(['service_id' => $service->id, 'kind' => 'product', 'from_snapshot' => '{}', 'to_snapshot' => '{}', 'currency' => 'USD',
        'status' => ChangeStatus::PendingPayment->value, 'expires_at' => $expiresAt]);

    $this->artisan('automation:run', ['--only' => 'services.upgrade-expiry'])->assertSuccessful();

    expect($change->refresh()->status)->toBe($status);
})->with([
    'expired before 00:00 on 20 Oct (18:15 UTC)' => ['2026-10-19 18:00:00', ChangeStatus::Expired],
    'expires at 02:45 on 20 Oct, still today in UTC' => ['2026-10-19 21:00:00', ChangeStatus::PendingPayment],
]);

it('a337-3: prices the upgrade quote on the same company date as the change invoice', function (string $route) {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-31 18:20:00', 'UTC')); // 00:05 on 1 Jan 2027 in Kathmandu
    $this->kit->priceBook->addProduct(1, 'Starter', ['monthly' => 3100]);
    $this->kit->priceBook->addProduct(2, 'Business', ['monthly' => 6200]);
    $this->kit->priceBook->allowUpgrade(1, 2);

    if ($route === 'client') {
        [, $client] = actingAsClient();
        $service = Service::factory()->dueOn('2027-01-10')->create(['client_id' => $client->id, 'product_id' => 1, 'recurring_amount' => 3100]);
        $show = route('client.services.show', $service->id);
        $change = route('client.services.change', $service->id);
    } else {
        Currency::factory()->create();
        actingAsStaff(['*']);
        $service = Service::factory()->dueOn('2027-01-10')->create(['client_id' => Client::factory()->create()->id, 'product_id' => 1, 'recurring_amount' => 3100]);
        $show = route('admin.services.services.show', $service);
        $change = route('admin.services.services.change', $service);
    }

    $quoted = new stdClass;
    $this->get($show)->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload->where('changeOptions', function ($options) use ($quoted): bool {
        $quoted->amount = ((array) ($options['options'] ?? []))[0]['amount_due'] ?? null;

        return true;
    })));
    $this->post($change, ['product_id' => 2, 'cycle' => 'monthly'])->assertRedirect();
    $invoiced = collect($this->kit->invoicer->invoices)->last()['draft'];

    expect($quoted->amount ?? null)->not->toBeNull()->and($invoiced->lines[0]->total())->toBe($quoted->amount);
})->with(['client', 'admin']);
