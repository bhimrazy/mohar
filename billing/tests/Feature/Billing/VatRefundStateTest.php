<?php

use App\Contracts\Billing\LooksUpRefunds;
use App\Data\Billing\ChargeResult;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\RefundRequest;
use App\Models\User;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Data\NoteRequest;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\GatewayManager;
use App\Modules\Billing\Services\NepalVatMode;
use App\Modules\Billing\Services\TaxNoteIssuer;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

/*
| The refund reservation state machine (ADR 0024 §5; AC 28-30, 49-51, 54): a refund of a payment on a numbered tax invoice
| reserves its credit note, and becomes the note only when the provider has paid. NPR, VAT 13%, Nepal time. The invoice is
| numbered at publish and paid in full (11,300), the legacy case that exists when the mode starts.
*/

/** A sandbox driver whose refund answer is scripted; `$lookup` answers a status check on the same reference. */
class VatScriptedDriver extends SandboxCardDriver implements LooksUpRefunds
{
    public static string $answer = ChargeResult::SUCCESS;

    public static ChargeResult $lookup;

    public function refund(GatewayConfig $gateway, RefundRequest $request): ChargeResult
    {
        return self::$answer === ChargeResult::SUCCESS ? parent::refund($gateway, $request) : new ChargeResult(self::$answer, declineReason: 'scripted_'.self::$answer);
    }

    public function lookupRefund(GatewayConfig $gateway, RefundRequest $request): ChargeResult
    {
        return self::$lookup;
    }
}

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-20 06:00:00');
    inCompanyTimezone();
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR']);
    useNepalVatRule(TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']));
    VatScriptedDriver::$answer = ChargeResult::SUCCESS;
    VatScriptedDriver::$lookup = new ChargeResult(ChargeResult::ERROR, declineReason: 'unreachable');
    app(GatewayManager::class)->register(new VatScriptedDriver);
    sandboxGateway();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** A paid numbered tax invoice and its payment. */
function vatRefundPayment(): Transaction
{
    $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);

    return payInvoice(issueInvoice($client, [10_000]), 11_300, 'ch_'.uniqid(), 'sandbox_card');
}

function vatRefund(Transaction $payment, int $amount = 5_650, RefundMode $mode = RefundMode::Gateway, ?string $reason = 'Outage in October'): Transaction
{
    return app(RefundTransaction::class)->handle($payment, $amount, $mode, false, User::factory()->staff(['billing.*'])->create()->id, reason: $reason);
}

function vatReservation(Transaction $refund): ?stdClass
{
    return DB::table('tax_note_reservations')->where('transaction_id', $refund->id)->first();
}

function vatReasons(Invoice $invoice): array
{
    return DB::table('invoice_adjustments')->where('invoice_id', $invoice->id)->orderBy('id')->pluck('reason')->all();
}

test('a refund the provider pays becomes exactly the reserved credit note, and no refund adjustment is written (AC 28)', function () {
    $payment = vatRefundPayment();
    $refund = vatRefund($payment);
    $note = TaxNote::query()->sole();
    $invoice = $payment->invoice->fresh();

    expect($refund)->status->toBe(TransactionStatus::Succeeded)->tax_note_id->toBe($note->id)
        ->and($note)->kind->toBe('credit')->reason_code->toBe('refunded')->reason_text->toBe('Outage in October')->total->toBe(5_650)->number->toBe('CN-1')
        ->and(vatReservation($refund))->status->toBe('finalised')->tax_note_id->toBe($note->id)
        ->and($invoice)->status->toBe(InvoiceStatus::Paid)->amount_paid->toBe(5_650)->balance->toBe(0)
        ->and(vatReasons($invoice))->toBe(['credit_note']);
});

test('a refund needs a reason in the mode, and an unreasoned refund leaves no row and no reservation', function () {
    $payment = vatRefundPayment();

    expect(fn () => vatRefund($payment, reason: null))->toThrow(BillingException::class, 'Say why')
        ->and(Transaction::query()->where('type', 'refund')->count())->toBe(0)
        ->and(DB::table('tax_note_reservations')->count())->toBe(0)
        ->and($payment->fresh()->refundable_remaining)->toBe(11_300);
});

test('a refund of a numbered tax invoice sent to account credit issues the credit note and credits the account once', function () {
    $payment = vatRefundPayment();
    $refund = vatRefund($payment, mode: RefundMode::ToCredit);

    expect(TaxNote::query()->sole())->total->toBe(5_650)->and($refund)->status->toBe(TransactionStatus::Succeeded)->tax_note_id->not->toBeNull()
        ->and(app(CreditLedgerService::class)->balance($payment->client_id, 'NPR')->amount)->toBe(5_650)
        ->and($payment->invoice->fresh()->amount_paid)->toBe(5_650)
        ->and(DB::table('credit_ledger')->where('reason', 'refund_to_credit')->count())->toBe(1);
});

test('a refund recorded as made outside is one transaction: the note is issued at once', function () {
    $refund = vatRefund(vatRefundPayment(), mode: RefundMode::RecordOnly);

    expect(TaxNote::query()->sole())->total->toBe(5_650)
        ->and($refund)->status->toBe(TransactionStatus::Succeeded)->tax_note_id->not->toBeNull();
});

test('DECLINED releases the reservation: no note, no number, the amount refundable again (AC 28)', function () {
    VatScriptedDriver::$answer = ChargeResult::DECLINED;
    $payment = vatRefundPayment();

    expect(fn () => vatRefund($payment))->toThrow(BillingException::class, 'rejected');

    $refund = Transaction::query()->where('type', 'refund')->sole();

    expect($refund)->status->toBe(TransactionStatus::Failed)
        ->and(vatReservation($refund))->status->toBe('released')
        ->and(TaxNote::query()->count())->toBe(0)
        ->and($payment->fresh()->refundable_remaining)->toBe(11_300);

    VatScriptedDriver::$answer = ChargeResult::SUCCESS;
    expect(vatRefund($payment->fresh()))->status->toBe(TransactionStatus::Succeeded)
        ->and(TaxNote::query()->sole()->number)->toBe('CN-1');
});

test('ERROR keeps the reservation pending and holds its cap; the callback then finalises once, and a late answer changes nothing (AC 49, 50)', function () {
    VatScriptedDriver::$answer = ChargeResult::ERROR;
    $payment = vatRefundPayment();
    $refund = vatRefund($payment, 8_000);

    expect($refund)->status->toBe(TransactionStatus::Pending)->and(vatReservation($refund))->status->toBe('pending')->and(TaxNote::query()->count())->toBe(0);

    // The reserved 8,000 is out of reach of a note on the same invoice, and a second refund is refused while one is waiting.
    $request = NoteRequest::creditAmount($payment->invoice_id, 5_000, 'goodwill', 'Too much');
    expect(fn () => app(TaxNoteIssuer::class)->preview($request))->toThrow(BillingException::class, 'At most');
    expect(fn () => vatRefund($payment->fresh(), 1_000))->toThrow(BillingException::class, 'being confirmed');

    app(RefundTransaction::class)->settle($refund, 're_1');
    app(RefundTransaction::class)->settle($refund->fresh(), 're_1');
    app(RefundTransaction::class)->release($refund->fresh());

    expect(TaxNote::query()->sole())->total->toBe(8_000)
        ->and($refund->fresh())->status->toBe(TransactionStatus::Succeeded)
        ->and(vatReservation($refund))->status->toBe('finalised')
        ->and($payment->fresh()->refundable_remaining)->toBe(3_300)
        ->and(Transaction::query()->where('type', 'refund')->count())->toBe(1);
});

test('Check refund answering DECLINED releases a pending reservation, and Retry is a new reservation and reference (AC 51)', function () {
    VatScriptedDriver::$answer = ChargeResult::PENDING;
    $payment = vatRefundPayment();
    $refund = vatRefund($payment);

    VatScriptedDriver::$lookup = new ChargeResult(ChargeResult::DECLINED, declineReason: 'no such refund');
    app(RefundTransaction::class)->check($refund->fresh()->forceFill(['created_at' => now()->subHour()]));

    expect($refund->fresh())->status->toBe(TransactionStatus::Failed)
        ->and(vatReservation($refund))->status->toBe('released')
        ->and($payment->fresh()->refundable_remaining)->toBe(11_300);

    VatScriptedDriver::$answer = ChargeResult::SUCCESS;
    $retry = vatRefund($payment->fresh());

    expect($retry->id)->not->toBe($refund->id)->and(vatReservation($retry))->status->toBe('finalised')->and(TaxNote::query()->count())->toBe(1);
});

test('a refund whose callback comes after midnight on the start day becomes a note dated that day, with no refund adjustment (AC 54)', function () {
    billingSettings(['nepal_vat.enabled_from' => '2026-11-17']);
    CarbonImmutable::setTestNow('2026-11-16 18:14:00 UTC');
    VatScriptedDriver::$answer = ChargeResult::ERROR;
    $payment = vatRefundPayment();
    $refund = vatRefund($payment);

    CarbonImmutable::setTestNow('2026-11-16 18:20:00 UTC');
    app(RefundTransaction::class)->settle($refund, 're_2');

    expect(TaxNote::query()->sole())->issue_date->toDateString()->toBe('2026-11-17')
        ->and(vatReasons($payment->invoice->fresh()))->toBe(['credit_note']);
});

test('a refund the provider pays before the start day is the old-style refund, dated before it, and the reservation holds no note', function () {
    billingSettings(['nepal_vat.enabled_from' => '2026-11-17']);
    CarbonImmutable::setTestNow('2026-11-16 18:10:00 UTC');
    $payment = vatRefundPayment();
    $refund = vatRefund($payment);

    expect(TaxNote::query()->count())->toBe(0)
        ->and($refund)->status->toBe(TransactionStatus::Succeeded)->tax_note_id->toBeNull()
        ->and(vatReservation($refund))->status->toBe('finalised')->tax_note_id->toBeNull()
        ->and(vatReasons($payment->invoice->fresh()))->toBe(['refund'])
        ->and(DB::table('invoice_adjustments')->where('reason', 'refund')->value('created_at'))->toBe('2026-11-16 18:10:00');
});

test('a refund takes back the account credit the payment placed first, and only the rest goes through a credit note (AC 33)', function () {
    $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
    $payment = payInvoice(issueInvoice($client, [10_000]), 12_000, 'ch_over', 'sandbox_card');
    $invoice = $payment->invoice->fresh();

    expect(app(CreditLedgerService::class)->balance($client->id, 'NPR')->amount)->toBe(700)
        ->and($invoice->amount_paid)->toBe(12_000);

    $refund = vatRefund($payment, 1_000);
    $note = TaxNote::query()->sole();

    // 700 was the overpayment kept as credit: a plain refund with no note. The other 300 is the sale.
    expect($note->total)->toBe(300)->and($refund)->status->toBe(TransactionStatus::Succeeded)->tax_note_id->toBe($note->id)
        ->and(app(CreditLedgerService::class)->balance($client->id, 'NPR')->amount)->toBe(0)
        ->and($invoice->fresh())->amount_paid->toBe(11_700)->balance->toBe(0)
        ->and(vatReasons($invoice))->toBe(['overpayment', 'credit_note']);
});

test('a refund that is only the credit the payment placed issues no note and leaves the invoice alone', function () {
    $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
    $payment = payInvoice(issueInvoice($client, [10_000]), 12_000, 'ch_over2', 'sandbox_card');

    $refund = vatRefund($payment, 700);

    expect(TaxNote::query()->count())->toBe(0)->and($refund)->status->toBe(TransactionStatus::Succeeded)->tax_note_id->toBeNull()
        ->and(app(CreditLedgerService::class)->balance($client->id, 'NPR')->amount)->toBe(0)
        ->and($payment->invoice->fresh())->amount_paid->toBe(12_000)->balance->toBe(0);
});

/** A refund the provider reports on its own (the callback books it record-only, with no staff member). */
function vatProviderRefund(Transaction $payment, int $amount, string $providerId): Transaction
{
    return app(RefundTransaction::class)->handle($payment, $amount, RefundMode::RecordOnly, false, null, $providerId, 'Refunded at the gateway');
}

test('a refund the provider reports becomes an automatic credit note in the same transaction, once per provider refund id (AC 31, 53)', function () {
    VatScriptedDriver::$answer = ChargeResult::DECLINED;
    $payment = vatRefundPayment();
    expect(fn () => vatRefund($payment))->toThrow(BillingException::class);

    // The reservation was released; the provider paid anyway.
    $paid = vatProviderRefund($payment->fresh(), 5_650, 're_late_1');
    $again = vatProviderRefund($payment->fresh(), 5_650, 're_late_1');
    $note = TaxNote::query()->sole();

    expect($again->id)->toBe($paid->id)
        ->and($note)->automatic->toBeTrue()->reason_code->toBe('refunded_at_provider')->total->toBe(5_650)
        ->and($paid)->tax_note_id->toBe($note->id)->review_amount->toBeNull()
        ->and($payment->invoice->fresh())->amount_paid->toBe(5_650)->balance->toBe(0);
});

test('a provider refund of a sale that notes already credited to account credit takes that credit back and issues no note (AC 32)', function () {
    $payment = vatRefundPayment();
    $request = NoteRequest::creditAmount($payment->invoice_id, 11_300, 'goodwill', 'All to credit', settlement: 'credit');
    app(TaxNoteIssuer::class)->issue($request, ($preview = app(TaxNoteIssuer::class)->preview($request))->version, $preview->digest);
    $credit = fn () => app(CreditLedgerService::class)->balance($payment->client_id, 'NPR')->amount;
    expect($credit())->toBe(11_300);

    $refund = vatProviderRefund($payment->fresh(), 11_300, 're_over_1');

    expect(TaxNote::query()->count())->toBe(1)->and($refund)->tax_note_id->toBeNull()->review_amount->toBeNull()
        ->and($credit())->toBe(0)->and($payment->invoice->fresh())->amount_paid->toBe(11_300);
});

test('a provider refund larger than the cap left takes the note up to the cap and the rest from the credit the notes placed', function () {
    $payment = vatRefundPayment();
    $request = NoteRequest::creditAmount($payment->invoice_id, 5_000, 'goodwill', 'Part to credit', settlement: 'credit');
    app(TaxNoteIssuer::class)->issue($request, ($preview = app(TaxNoteIssuer::class)->preview($request))->version, $preview->digest);

    $refund = vatProviderRefund($payment->fresh(), 11_300, 're_over_2');
    $notes = TaxNote::query()->orderBy('id')->get();

    expect($notes)->toHaveCount(2)->and($notes[1])->total->toBe(6_300)->reason_code->toBe('refunded_at_provider')
        ->and($refund)->tax_note_id->toBe($notes[1]->id)->review_amount->toBeNull()
        ->and(app(CreditLedgerService::class)->balance($payment->client_id, 'NPR')->amount)->toBe(0)
        ->and($payment->invoice->fresh())->amount_paid->toBe(11_300 - 6_300)->credited_total->toBe(11_300);
});

test('a DECLINED that arrives after the provider confirmed the refund changes nothing and tells staff (ADR 0024 §5, conflicting outcome)', function () {
    $payment = vatRefundPayment();
    $refund = vatRefund($payment);
    expect($refund)->status->toBe(TransactionStatus::Succeeded)->review_amount->toBeNull();

    $released = app(RefundTransaction::class)->release($refund->fresh());

    expect($released)->toBeFalse()
        ->and($refund->fresh())->status->toBe(TransactionStatus::Succeeded)->review_amount->toBe(5_650)->tax_note_id->not->toBeNull()
        ->and(Transaction::toReview()->count())->toBe(1)->and(TaxNote::query()->count())->toBe(1);
});

test('the mode cannot be switched off while a refund is waiting for the provider', function () {
    VatScriptedDriver::$answer = ChargeResult::ERROR;
    $refund = vatRefund(vatRefundPayment());
    $mode = app(NepalVatMode::class);
    $apply = fn () => $mode->apply(User::factory()->staff(['billing.*'])->create(), ['enabled' => false, 'enabled_from' => null, 'vat_rules' => $mode->vatRules(), 'export_rule' => null]);

    expect($apply(...))->toThrow(ValidationException::class, 'waiting for the provider');

    // Once it settles it is a note, and a mode with a note cannot be switched off either.
    app(RefundTransaction::class)->settle($refund, 're_off');

    expect($apply(...))->toThrow(ValidationException::class, 'credit or debit notes');
});

test('a DECLINED after SUCCESS does not overwrite what is already on the review list, and does not reopen a reviewed entry', function () {
    $refund = vatRefund(vatRefundPayment());
    $refund->forceFill(['review_amount' => 300, 'reviewed_at' => now(), 'description' => 'Part to review'])->save();

    app(RefundTransaction::class)->release($refund->fresh());

    expect($refund->fresh())->review_amount->toBe(300)->reviewed_at->not->toBeNull()->description->toBe('Part to review');
});
