<?php

use App\Data\Billing\CallbackEvent;
use App\Data\Billing\ChargeResult;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\RefundRequest;
use App\Models\User;
use App\Modules\Billing\Actions\ApplyCredit;
use App\Modules\Billing\Actions\CancelInvoice;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\GatewayCallbackHandler;
use App\Modules\Billing\Services\NepalVatMode;
use App\Modules\Billing\Services\TaxNoteIssuer;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

/* Review probes for PR #458 (review board), adopted as tests. NPR, VAT 13%, Nepal time. */

class VatRefundProbeDriver extends SandboxCardDriver
{
    public static string $answer = ChargeResult::SUCCESS;

    public function refund(GatewayConfig $gateway, RefundRequest $request): ChargeResult
    {
        return self::$answer === ChargeResult::SUCCESS ? parent::refund($gateway, $request) : new ChargeResult(self::$answer, declineReason: 'scripted');
    }
}

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-20 06:00:00');
    inCompanyTimezone();
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR']);
    useNepalVatRule(TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']));
    VatRefundProbeDriver::$answer = ChargeResult::SUCCESS;
    app(GatewayManager::class)->register(new VatRefundProbeDriver);
    sandboxGateway();
    $this->staff = User::factory()->staff(['billing.*'])->create()->id;
});

afterEach(fn () => CarbonImmutable::setTestNow());

function vatRefundProbeCredit(int $client): int
{
    return app(CreditLedgerService::class)->balance($client, 'NPR')->amount;
}

test('P1: a record-only refund while a gateway refund of the same overpaid payment is pending takes the 700 credit back twice', function () {
    $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
    $payment = payInvoice(issueInvoice($client, [10_000]), 12_000, 'ch_p1', 'sandbox_card');
    expect(vatRefundProbeCredit($client->id))->toBe(700);

    VatRefundProbeDriver::$answer = ChargeResult::ERROR;
    $pending = app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway, false, $this->staff, reason: 'first');
    expect($pending->status)->toBe(TransactionStatus::Pending);

    $recorded = app(RefundTransaction::class)->handle($payment->fresh(), 1_000, RefundMode::RecordOnly, false, $this->staff, reason: 'second');
    app(RefundTransaction::class)->settle($pending->fresh(), 're_p1');

    $notes = TaxNote::query()->sum('total');

    // Correct: 700 credit once, 1,300 through notes, credit balance 0.
    expect((int) $notes)->toBe(1_300)->and(vatRefundProbeCredit($client->id))->toBe(0);
});

test('P2: the mode can be switched off while a reservation is pending; the refund then finalises as an old-style row on a register invoice', function () {
    $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
    $payment = payInvoice(issueInvoice($client, [10_000]), 11_300, 'ch_p2', 'sandbox_card');
    VatRefundProbeDriver::$answer = ChargeResult::ERROR;
    $pending = app(RefundTransaction::class)->handle($payment, 5_650, RefundMode::Gateway, false, $this->staff, reason: 'outage');

    $mode = app(NepalVatMode::class);
    $switchedOff = true;

    try {
        $mode->apply(User::query()->findOrFail($this->staff), ['enabled' => false, 'enabled_from' => $mode->enabledFrom(), 'vat_rules' => $mode->vatRules(), 'export_rule' => $mode->exportRule()]);
    } catch (Throwable $e) {
        $switchedOff = false;
    }

    app(RefundTransaction::class)->settle($pending->fresh(), 're_p2');
    $reasons = DB::table('invoice_adjustments')->where('invoice_id', $payment->invoice_id)->pluck('reason')->all();

    // Expected: switching off refused while a reservation is pending (it is a note to be).
    expect($switchedOff)->toBeFalse();
});

test('P3: a callback that loses the race to a release books the refund the provider made, as a provider-initiated one', function () {
    $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
    $payment = payInvoice(issueInvoice($client, [10_000]), 11_300, 'ch_p3', 'sandbox_card');
    VatRefundProbeDriver::$answer = ChargeResult::ERROR;
    $pending = app(RefundTransaction::class)->handle($payment, 5_650, RefundMode::Gateway, false, $this->staff, reason: 'outage');

    // The request's DECLINED / staff release commits first; the provider's callback for the same reference then arrives.
    app(RefundTransaction::class)->release($pending->fresh());
    $event = new CallbackEvent(CallbackEvent::REFUND, null, 're_p3_paid', 5_650, 'NPR', 'ch_p3', raw: ['id' => 're_p3_paid'], reference: (string) $pending->id);
    $answer = app(GatewayCallbackHandler::class)->process('sandbox_card', $event);

    expect($answer['status'])->toBe(200)->and(Transaction::query()->where('type', 'refund')->where('status', 'succeeded')->count())->toBe(1)
        ->and(TaxNote::query()->sole())->total->toBe(5_650)->reason_code->toBe('refunded_at_provider')
        ->and($payment->invoice->fresh()->amount_paid)->toBe(5_650);
});

test('P3b: the same callback delivered twice books the released refund once', function () {
    $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
    $payment = payInvoice(issueInvoice($client, [10_000]), 11_300, 'ch_p3b', 'sandbox_card');
    VatRefundProbeDriver::$answer = ChargeResult::ERROR;
    $pending = app(RefundTransaction::class)->handle($payment, 5_650, RefundMode::Gateway, false, $this->staff, reason: 'outage');
    app(RefundTransaction::class)->release($pending->fresh());
    $event = new CallbackEvent(CallbackEvent::REFUND, null, 're_p3b', 5_650, 'NPR', 'ch_p3b', raw: ['id' => 're_p3b'], reference: (string) $pending->id);

    app(GatewayCallbackHandler::class)->process('sandbox_card', $event);
    $again = app(GatewayCallbackHandler::class)->process('sandbox_card', $event);

    expect($again['result'])->toBe('duplicate')->and(TaxNote::query()->count())->toBe(1);
});

test('P5: a recorded refund is two transactions: a failure while finalising leaves a succeeded refund row and a pending reservation', function () {
    $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
    $payment = payInvoice(issueInvoice($client, [10_000]), 11_300, 'ch_p5', 'sandbox_card');
    $issuer = Mockery::mock(app(TaxNoteIssuer::class))->makePartial();
    $issuer->shouldReceive('issueReserved')->andThrow(new RuntimeException('worker died'));
    app()->instance(TaxNoteIssuer::class, $issuer);
    app()->forgetInstance(RefundTransaction::class);

    try {
        app(RefundTransaction::class)->handle($payment, 5_650, RefundMode::RecordOnly, false, $this->staff, reason: 'outage');
    } catch (RuntimeException) {
    }

    $refund = Transaction::query()->where('type', 'refund')->first();

    // Nothing is left behind: no refund row, no reservation, the refundable amount and the invoice untouched.
    expect($refund)->toBeNull()->and(DB::table('tax_note_reservations')->count())->toBe(0)->and($payment->fresh()->refundable_remaining)->toBe(11_300)
        ->and($payment->invoice->fresh()->amount_paid)->toBe(11_300);
});

test('P6: credit spent while the refund is pending is still taken back at finalise (balance goes negative)', function () {
    $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
    $payment = payInvoice(issueInvoice($client, [10_000]), 12_000, 'ch_p6', 'sandbox_card');
    VatRefundProbeDriver::$answer = ChargeResult::ERROR;
    $pending = app(RefundTransaction::class)->handle($payment, 1_000, RefundMode::Gateway, false, $this->staff, reason: 'first');

    $other = issueInvoice($client, [1_000]);
    app(ApplyCredit::class)->handleAvailable($other);
    $spent = vatRefundProbeCredit($client->id);

    app(RefundTransaction::class)->settle($pending->fresh(), 're_p6');

    // The 700 was taken back (held) when the refund was reserved, before the client could spend it: the spend found 0.
    expect($spent)->toBe(0)->and(vatRefundProbeCredit($client->id))->toBe(0);
});

test('P7: provider refund through the real callback on an overpaid VAT invoice (AC 33 via callback) and a replay', function () {
    $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
    $payment = payInvoice(issueInvoice($client, [10_000]), 12_000, 'ch_p7', 'sandbox_card');
    $event = new CallbackEvent(CallbackEvent::REFUND, null, 're_p7', 1_000, 'NPR', 'ch_p7', raw: ['id' => 're_p7']);

    $first = app(GatewayCallbackHandler::class)->process('sandbox_card', $event);
    $second = app(GatewayCallbackHandler::class)->process('sandbox_card', $event);

    expect(TaxNote::query()->sum('total'))->toEqual(300)->and(vatRefundProbeCredit($client->id))->toBe(0)->and(Transaction::query()->where('type', 'refund')->count())->toBe(1);
});

test('P8: a refund of a payment kept on a numbered invoice cancelled in the mode issues no credit note', function () {
    $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
    $invoice = issueInvoice($client, [10_000]);
    $payment = payInvoice($invoice, 5_000, 'ch_p8', 'sandbox_card');
    app(CancelInvoice::class)->handle($invoice->fresh(), $this->staff, 'client left');
    $afterCancel = TaxNote::query()->pluck('total')->all();

    $refund = app(RefundTransaction::class)->handle($payment->fresh(), 5_000, RefundMode::Gateway, false, $this->staff, reason: 'refund the part paid');
    $inv = $invoice->fresh();

    // vat-register §5.4 (staff cancel row): payments stay on the invoice; refunding them later is a further note.
    expect(TaxNote::query()->count())->toBe(2)->and($inv)->balance->toBe(0)->amount_paid->toBe(0)->and($refund->status)->toBe(TransactionStatus::Succeeded);
});
