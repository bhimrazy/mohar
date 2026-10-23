<?php

use App\Contracts\Core\CompanyClock;
use App\Modules\Notifications\Actions\PruneNotificationLogs;
use App\Modules\Notifications\Enums\EmailStatus;
use App\Modules\Notifications\Enums\WebhookDeliveryStatus;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Models\WebhookDelivery;
use App\Modules\Notifications\Models\WebhookEndpoint;
use App\Modules\Notifications\Services\SampleContext;
use Carbon\CarbonImmutable;

/*
 * Notifications reads "today" from the company clock (ADR 0013 Amendment 1). Each case sits on either side of the
 * company midnight: 23:59:59 and 00:00:00 in Asia/Kathmandu (UTC+5:45) and in America/New_York (UTC-4 in October).
 * `[zone, instant before midnight, instant at midnight, the company date before, the company date after]`
 */
afterEach(fn () => CarbonImmutable::setTestNow());

dataset('company midnight', [
    'Kathmandu' => ['Asia/Kathmandu', '2026-10-18 18:14:59', '2026-10-18 18:15:00', '2026-10-18', '2026-10-19'],
    'New York' => ['America/New_York', '2026-10-19 03:59:59', '2026-10-19 04:00:00', '2026-10-18', '2026-10-19'],
]);

it('keeps email bodies and webhook deliveries from the start of the company day the retention reaches', function (string $zone, string $before, string $at, string $dayBefore, string $dayAfter) {
    inCompanyTimezone($zone);
    $endpoint = WebhookEndpoint::factory()->create();
    // Retention is 365 days for bodies and 30 for deliveries; the run date is a date value (todayDate()).
    $runDate = CarbonImmutable::parse($dayAfter, 'UTC');
    [$bodyStart] = app(CompanyClock::class)->dayBounds($runDate->subDays(365));
    [$deliveryStart] = app(CompanyClock::class)->dayBounds($runDate->subDays(30));
    $message = fn (CarbonImmutable $createdAt) => tap(EmailMessage::factory()->create(['status' => EmailStatus::Sent]), fn (EmailMessage $row) => $row->forceFill(['created_at' => $createdAt])->saveQuietly());
    $delivery = fn (CarbonImmutable $createdAt) => tap(WebhookDelivery::query()->create(['event_id' => fake()->uuid(), 'webhook_endpoint_id' => $endpoint->id, 'event_type' => 'invoice.paid', 'payload' => ['id' => 'x'], 'attempt' => 1, 'status' => WebhookDeliveryStatus::Succeeded]), fn (WebhookDelivery $row) => $row->forceFill(['created_at' => $createdAt])->saveQuietly());
    $oldMessage = $message($bodyStart->subSecond());
    $keptMessage = $message($bodyStart);
    $oldDelivery = $delivery($deliveryStart->subSecond());
    $keptDelivery = $delivery($deliveryStart);

    expect(app(PruneNotificationLogs::class)->handle($runDate))->toBe(2)
        ->and($oldMessage->refresh()->body_html)->toBeNull()
        ->and($keptMessage->refresh()->body_html)->not->toBeNull()
        ->and(WebhookDelivery::query()->whereKey($oldDelivery->id)->exists())->toBeFalse()
        ->and(WebhookDelivery::query()->whereKey($keptDelivery->id)->exists())->toBeTrue();
})->with('company midnight');

it('runs the manual prune command on the company date', function (string $zone, string $before, string $at, string $dayBefore, string $dayAfter) {
    inCompanyTimezone($zone);
    $endpoint = WebhookEndpoint::factory()->create();
    CarbonImmutable::setTestNow(CarbonImmutable::parse($at, 'UTC'));
    [$start] = app(CompanyClock::class)->dayBounds(CarbonImmutable::parse($dayAfter, 'UTC')->subDays(30));
    $make = fn (CarbonImmutable $createdAt) => tap(WebhookDelivery::query()->create(['event_id' => fake()->uuid(), 'webhook_endpoint_id' => $endpoint->id, 'event_type' => 'invoice.paid', 'payload' => ['id' => 'x'], 'attempt' => 1, 'status' => WebhookDeliveryStatus::Succeeded]), fn (WebhookDelivery $row) => $row->forceFill(['created_at' => $createdAt])->saveQuietly());
    $old = $make($start->subSecond());
    $kept = $make($start);

    $this->artisan('notifications:prune')->assertSuccessful();

    expect(WebhookDelivery::query()->pluck('id')->all())->toBe([$kept->id])->and($old->id)->not->toBe($kept->id);
})->with('company midnight');

it('dates the sample previews from the company today', function (string $zone, string $before, string $at, string $dayBefore, string $dayAfter) {
    inCompanyTimezone($zone);

    foreach ([[$before, $dayBefore], [$at, $dayAfter]] as [$instant, $date]) {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($instant, 'UTC'));
        $sample = app(SampleContext::class)->build();
        $day = CarbonImmutable::parse($date);

        expect($sample['domain']['registration_date'])->toBe($date)
            ->and($sample['domain']['expiry_date'])->toBe($day->addYear()->toDateString())
            ->and($sample['domains'][0]['expiry_date'])->toBe($day->addDays(30)->toDateString())
            ->and($sample['domains'][1]['expiry_date'])->toBe($day->addDays(14)->toDateString());
    }
})->with('company midnight');
