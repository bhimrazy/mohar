<?php

use App\Data\Billing\CallbackEvent;
use App\Data\Billing\ChargeResult;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\RefundRequest;
use App\Models\User;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\GatewayCallbackHandler;
use App\Modules\Billing\Services\GatewayManager;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Carbon\CarbonImmutable;

require_once __DIR__.'/helpers.php';

/* Re-review probes for #458, adopted: the race forced through the real handler, and the credit hold. */

class VatRacingDriver extends SandboxCardDriver
{
    public static string $answer = ChargeResult::SUCCESS;

    public function refund(GatewayConfig $gateway, RefundRequest $request): ChargeResult
    {
        return self::$answer === ChargeResult::SUCCESS ? parent::refund($gateway, $request) : new ChargeResult(self::$answer, declineReason: 'scripted');
    }
}

/** The request's DECLINED commits after the handler read the row as pending and before settle() takes its lock. */
class VatRacingRefunds extends RefundTransaction
{
    public function settle(Transaction $refund, ?string $providerRefundId, array $raw = []): Transaction
    {
        $this->release(Transaction::query()->findOrFail($refund->id));

        return parent::settle($refund, $providerRefundId, $raw);
    }
}

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-20 06:00:00');
    inCompanyTimezone();
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR']);
    useNepalVatRule(TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']));
    VatRacingDriver::$answer = ChargeResult::SUCCESS;
    app(GatewayManager::class)->register(new VatRacingDriver);
    sandboxGateway();
    $this->staff = User::factory()->staff(['billing.*'])->create()->id;
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('RR-B3: the handler read the row as pending, a DECLINED commits before settle locks it: the provider refund is still booked', function () {
    $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
    $payment = payInvoice(issueInvoice($client, [10_000]), 11_300, 'ch_rr3', 'sandbox_card');
    VatRacingDriver::$answer = ChargeResult::ERROR;
    $pending = app(RefundTransaction::class)->handle($payment, 5_650, RefundMode::Gateway, false, $this->staff, reason: 'outage');
    app()->bind(RefundTransaction::class, VatRacingRefunds::class);

    $event = new CallbackEvent(CallbackEvent::REFUND, null, 're_rr3', 5_650, 'NPR', 'ch_rr3', raw: ['id' => 're_rr3'], reference: (string) $pending->id);
    $answer = app(GatewayCallbackHandler::class)->process('sandbox_card', $event);

    expect($pending->fresh()->status)->toBe(TransactionStatus::Failed)
        ->and(Transaction::query()->where('type', 'refund')->where('status', 'succeeded')->count())->toBe(1)
        ->and(TaxNote::query()->sum('total'))->toEqual(5_650)
        ->and($payment->invoice->fresh()->amount_paid)->toBe(5_650);
});

test('RR-B1b: provider-initiated refund of an overpaid payment while a gateway refund of it is pending: credit taken once', function () {
    $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
    $payment = payInvoice(issueInvoice($client, [10_000]), 12_000, 'ch_rr1', 'sandbox_card');
    VatRacingDriver::$answer = ChargeResult::ERROR;
    $pending = app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway, false, $this->staff, reason: 'first');

    $event = new CallbackEvent(CallbackEvent::REFUND, null, 're_rr1_other', 1_000, 'NPR', 'ch_rr1', raw: ['id' => 're_rr1_other']);
    $answer = app(GatewayCallbackHandler::class)->process('sandbox_card', $event);
    app(RefundTransaction::class)->settle($pending->fresh(), 're_rr1');

    $credit = app(CreditLedgerService::class)->balance($client->id, 'NPR')->amount;
    expect(TaxNote::query()->sum('total'))->toEqual(1_300)->and($credit)->toBe(0);
});

test('RR-B1c: released gateway refund of an overpaid payment returns the 700 hold exactly once (release twice)', function () {
    $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
    $payment = payInvoice(issueInvoice($client, [10_000]), 12_000, 'ch_rr1c', 'sandbox_card');
    $before = $payment->fresh()->refundable_remaining;
    VatRacingDriver::$answer = ChargeResult::ERROR;
    $pending = app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway, false, $this->staff, reason: 'first');
    $held = app(CreditLedgerService::class)->balance($client->id, 'NPR')->amount;
    app(RefundTransaction::class)->release($pending->fresh());
    app(RefundTransaction::class)->release($pending->fresh());

    expect($held)->toBe(0)->and(app(CreditLedgerService::class)->balance($client->id, 'NPR')->amount)->toBe(700)
        ->and($payment->fresh()->refundable_remaining)->toBe($before);
});
