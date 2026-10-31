<?php

use App\Models\User;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
 * Review board probes for #522. P1 is red at 1f78fc0d: IP rotation gives an unlimited guess budget. P2 guards the fix:
 * the per-login cap counts failures only. P3 replaces "staff have a higher document limit", which passes on develop.
 */

require_once dirname(__DIR__).'/Billing/helpers.php';
require_once dirname(__DIR__).'/Notifications/helpers.php';

test('P1 a login has an hourly failure budget across IPs: twenty wrong guesses, then the right one is refused', function () {
    $this->actingAs(User::factory()->create(['password' => 'correct-horse-battery']));

    foreach (range(1, 4) as $ip) {
        foreach (range(1, 5) as $i) {
            // The twentieth uses the budget up and ends the session (#523).
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$ip}"])
                ->postJson(route('password.confirm.store'), ['password' => "wrong-{$ip}-{$i}"])->assertStatus($ip === 4 && $i === 5 ? 429 : 422);
        }
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
        ->postJson(route('password.confirm.store'), ['password' => 'correct-horse-battery'])->assertUnauthorized();
    expect(session('auth.step_up_confirmed_at'))->toBeNull();
});

test('P2 correct confirmations do not use up the hourly failure budget', function () {
    $this->actingAs(User::factory()->create(['password' => 'correct-horse-battery']));

    foreach (range(1, 25) as $i) {
        $this->travel(61)->seconds();
        $this->postJson(route('password.confirm.store'), ['password' => 'correct-horse-battery'])->assertCreated();
    }
});

test('P3 staff get sixty document downloads a minute, then 429 with no print', function () {
    CarbonImmutable::setTestNow('2026-10-20 06:00:00');
    Storage::fake('objects');
    Mail::fake();
    Currency::factory()->npr()->create();
    billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR']);
    useNepalVatRule(TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']));
    $invoice = issueInvoice(Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']), [1_000_000]);
    billingStaff();

    foreach (range(1, 60) as $download) {
        $this->get(route('admin.billing.invoices.pdf', $invoice))->assertOk();
    }

    $this->get(route('admin.billing.invoices.pdf', $invoice))->assertTooManyRequests();
    expect($invoice->fresh()->print_count)->toBe(60);
    CarbonImmutable::setTestNow();
});
