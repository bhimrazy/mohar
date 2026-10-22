<?php

use App\Contracts\Core\CompanyClock;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PayMethod;
use App\Modules\Billing\Services\BillingAutomation;
use App\Modules\Core\Models\AutomationRun;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\AutomationStatus;
use App\Modules\Core\Support\CoreCompanyClock;
use App\Modules\Core\Support\Settings;
use App\Modules\Support\Enums\TicketStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;

require_once __DIR__.'/../Billing/helpers.php';
require_once __DIR__.'/../Support/helpers.php';

/*
 * Review probes of #337 (rb337-*), adopted. The automation run at 03:00 in Kathmandu is 21:15 UTC the day before, so
 * every reader of the run date, and every comparison of a calendar date with a timestamp, is exercised at that instant.
 */

beforeEach(fn () => inCompanyTimezone('Asia/Kathmandu'));
afterEach(fn () => CarbonImmutable::setTestNow());

function freezeAt0300Nepal(): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-18 21:15:00', 'UTC')); // 03:00 on 19 Oct in Kathmandu
}

it('rb337-1: the test helper freezes now() and Carbon alike', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-18 18:20:00', 'UTC'));

    expect(now()->toIso8601ZuluString())->toBe('2026-10-18T18:20:00Z')->and(Carbon\Carbon::now()->toIso8601ZuluString())->toBe('2026-10-18T18:20:00Z');
});

it('rb337-3: the Support auto-close run at 03:00 Nepal time does not close a 72 hour ticket after 48 hours', function () {
    app(Settings::class)->set('support.auto_close_hours', 72);
    freezeAt0300Nepal();
    $ticket = clientTicket(Client::factory()->create());
    $ticket->forceFill(['status' => TicketStatus::Answered, 'last_reply_at' => now()->subHours(48)])->save();

    $this->artisan('automation:run', ['--only' => 'support.auto_close'])->assertSuccessful();
    expect($ticket->fresh()->status)->toBe(TicketStatus::Answered);

    $ticket->forceFill(['last_reply_at' => now()->subHours(73)])->save();
    $this->artisan('automation:run', ['--only' => 'support.auto_close', '--force' => true])->assertSuccessful();
    expect($ticket->fresh()->status)->not->toBe(TicketStatus::Answered);
});

it('rb337-4: no reminder on the company day of a capture attempt made by the same run', function () {
    freezeAt0300Nepal();
    $today = CarbonImmutable::parse('2026-10-19');
    $invoice = Invoice::factory()->create(['client_id' => billingClient()->id, 'due_date' => '2026-10-20', 'issue_date' => '2026-10-01', 'last_capture_attempt_at' => null]);
    expect(app(BillingAutomation::class)->reminderStage($invoice->fresh(), $today, 7, [1, 3, 7]))->toBe('before_due');

    $invoice->forceFill(['last_capture_attempt_at' => now()])->save();

    expect(app(BillingAutomation::class)->reminderStage($invoice->fresh(), $today, 7, [1, 3, 7]))->toBeNull();
});

it('rb337-5: a failed capture is not charged again on the same company day', function () {
    sandboxGateway();
    billingSettings(['capture.retry_days' => '0,0,0']);
    $client = billingClient();
    PayMethod::factory()->withCard('4000000000000002')->create(['client_id' => $client->id]);
    freezeAt0300Nepal();
    $invoice = issueInvoice($client, [5_000], CarbonImmutable::parse('2026-10-19'));

    $this->artisan('automation:run', ['--only' => 'billing.auto_capture'])->assertSuccessful();
    expect($invoice->fresh()->capture_attempts)->toBe(1);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 04:00:00', 'UTC')); // 09:45, the same company day
    $this->artisan('automation:run', ['--only' => 'billing.auto_capture', '--force' => true])->assertSuccessful();
    expect($invoice->fresh()->capture_attempts)->toBe(1);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 21:15:00', 'UTC')); // the next company day
    $this->artisan('automation:run', ['--only' => 'billing.auto_capture'])->assertSuccessful();
    expect($invoice->fresh()->capture_attempts)->toBe(2);
});

it('rb337-6: failedToday counts the run of the company day', function () {
    freezeAt0300Nepal();
    AutomationRun::query()->create(['run_date' => '2026-10-19', 'task' => 'x', 'status' => 'failed']);
    AutomationRun::query()->create(['run_date' => '2026-10-18', 'task' => 'y', 'status' => 'success']);

    expect(app(AutomationStatus::class)->failedToday())->toBe(1);
});

it('rb337-8: a deploy between 21:15 and 03:00 UTC skips one run date, and the next run catches up', function () {
    app(Settings::class)->set('core.automation_time', '03:00');
    $this->app->forgetInstance(Schedule::class);
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'automation:run'));
    $dates = ['2026-10-18']; // the last run before the deploy: 03:00 UTC, run date 18 Oct
    for ($t = CarbonImmutable::parse('2026-10-18 23:00', 'UTC'); $t->lt(CarbonImmutable::parse('2026-10-20 23:00', 'UTC')); $t = $t->addMinute()) {
        CarbonImmutable::setTestNow($t);
        if ($event->isDue($this->app)) {
            $dates[] = app(CompanyClock::class)->today()->toDateString();
        }
    }
    expect($dates)->toBe(['2026-10-18', '2026-10-20', '2026-10-21']);

    // 19 Oct never ran, but the cancellation window is `due_date <= today - days`, so the 20 Oct run takes the invoice.
    billingSettings(['auto_cancel.days_overdue' => 7]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-12 06:00', 'UTC'));
    $invoice = issueInvoice(billingClient(), [5_000], CarbonImmutable::parse('2026-10-12'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 21:15', 'UTC'));
    $this->artisan('automation:run', ['--only' => 'billing.auto_cancel'])->assertSuccessful();

    expect($invoice->fresh()->status->value)->toBe('cancelled');
});

it('accepts a backward-compatible zone name and maps it to the canonical zone', function (string $stored, string $zone) {
    app(Settings::class)->set('system.timezone', $stored);

    expect(app(CompanyClock::class)->timezone())->toBe($zone)->and(app(CoreCompanyClock::class)->isFallback())->toBeFalse();
})->with([['Asia/Katmandu', 'Asia/Kathmandu'], ['Asia/Calcutta', 'Asia/Kolkata'], ['Etc/UTC', 'UTC']]);

it('reports a real fallback to UTC and shows a dashboard warning', function () {
    app(Settings::class)->set('system.timezone', 'Mars/Olympus');
    $reported = [];
    $this->app->make(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$reported): void {
        $reported[] = $e->getMessage();
    });

    expect(app(CompanyClock::class)->timezone())->toBe('UTC')
        ->and(app(CoreCompanyClock::class)->isFallback())->toBeTrue();

    actingAsStaff(['*']);
    $this->get(route('admin.dashboard'))->assertOk()->assertSee('Company time zone is not valid');
});
