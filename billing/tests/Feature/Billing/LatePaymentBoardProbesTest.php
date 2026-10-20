<?php

/*
| Review-board probes for PR #152 (#130, late provider success on a closed attempt). They pin the outcomes the
| PR's own tests leave out: a cancelled invoice, a converted payment, two late payments on one invoice, a partial
| refund found on a closed attempt, and the index the late sweep needs.
*/

use App\Contracts\Billing\GatewayRegistry;
use App\Contracts\Billing\HostedPaymentDriver;
use App\Data\Billing\AmountLimits;
use App\Data\Billing\FormPost;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\LookupResult;
use App\Data\Billing\PaymentAttemptRef;
use App\Data\Billing\PaymentIntent;
use App\Data\Billing\QrPayload;
use App\Data\Billing\RedirectInstruction;
use App\Data\Billing\ReturnHint;
use App\Modules\Billing\Actions\CancelInvoice;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Currency;
use App\Support\Billing\AbstractGatewayDriver;
use App\Support\Dashboard\Dashboard;
use Carbon\CarbonInterval;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/helpers.php';

/**
 * Lookup-only wallet whose provider answers are set per attempt reference.
 */
class LateProbeWalletDriver extends AbstractGatewayDriver implements HostedPaymentDriver
{
    /** @var array<string, LookupResult> */
    public static array $provider = [];

    public function key(): string
    {
        return 'late_probe_wallet';
    }

    public function name(): string
    {
        return 'Late probe wallet';
    }

    public function capabilities(): array
    {
        return [self::REDIRECT, self::LOOKUP];
    }

    public function currencies(): array
    {
        return ['NPR', 'USD'];
    }

    public function settingsFields(): array
    {
        return [];
    }

    public function limits(GatewayConfig $gateway): AmountLimits
    {
        return new AmountLimits(min: 1, max: 100_000_000);
    }

    public function attemptTtl(GatewayConfig $gateway): CarbonInterval
    {
        return CarbonInterval::minutes(15);
    }

    public function initiate(GatewayConfig $gateway, PaymentIntent $intent): RedirectInstruction|FormPost|QrPayload
    {
        return new FormPost('https://wallet.test/form', ['ref' => $intent->reference]);
    }

    public function parseReturn(GatewayConfig $gateway, Request $request): ReturnHint
    {
        return new ReturnHint($request->string('ref')->toString() ?: null);
    }

    public function lookup(GatewayConfig $gateway, PaymentAttemptRef $attempt): LookupResult
    {
        return self::$provider[$attempt->reference] ?? new LookupResult(LookupResult::NOT_FOUND);
    }
}

beforeEach(function () {
    LateProbeWalletDriver::$provider = [];
    app(GatewayRegistry::class)->register(new LateProbeWalletDriver);
    Gateway::query()->create(['key' => 'late_probe_wallet', 'display_name' => 'Wallet', 'is_active' => true, 'show_on_order_form' => true, 'config' => []]);
});

/**
 * A closed attempt for the invoice that the provider now reports as paid.
 *
 * @param  array<string, mixed>  $attributes
 */
function lateProbeAttempt(Invoice $invoice, string $txnId, array $attributes = [], ?LookupResult $answer = null): PaymentAttempt
{
    $attempt = PaymentAttempt::query()->create([
        'reference' => PaymentAttempt::newReference(),
        'gateway' => 'late_probe_wallet',
        'client_id' => $invoice->client_id,
        'invoice_id' => $invoice->id,
        'amount' => $invoice->balance,
        'currency' => $invoice->currency,
        'document_amount' => $invoice->balance,
        'document_currency' => $invoice->currency,
        'status' => AttemptStatus::Expired,
        'expires_at' => now()->subHour(),
        ...$attributes,
    ]);
    LateProbeWalletDriver::$provider[$attempt->reference] = $answer
        ?? new LookupResult(LookupResult::SUCCEEDED, $txnId, $attempt->amount, $attempt->currency);

    return $attempt;
}

function lateProbeTodo(): ?int
{
    return collect(app(Dashboard::class)->resolveTodos('admin', billingStaff()))->firstWhere('key', 'billing.late_payments')['count'] ?? null;
}

test('a late payment for a cancelled invoice is kept whole as credit, the invoice stays cancelled and staff are told', function () {
    $invoice = issueInvoice(billingClient(), [5_000]);
    $attempt = lateProbeAttempt($invoice, 'probe-cancelled-1');
    app(CancelInvoice::class)->handle($invoice);

    $this->artisan('billing:reconcile-payments')->assertSuccessful();

    expect(Transaction::query()->where('gateway', 'late_probe_wallet')->sole())->amount_in->toBe(5_000)->refundable_remaining->toBe(0)
        ->and($invoice->fresh())->status->toBe(InvoiceStatus::Cancelled)->amount_paid->toBe(0)
        ->and(app(CreditLedgerService::class)->balance($invoice->client_id, 'USD')->amount)->toBe(5_000)
        ->and($attempt->fresh())->status->toBe(AttemptStatus::Succeeded)->late_payment_at->not->toBeNull()
        ->and(ActivityLog::query()->where('event', 'billing.payment.late')->sole()->properties)->toMatchArray(['applied' => 0, 'credited' => 5_000])
        ->and(lateProbeTodo())->toBe(1);
});

test('a converted late payment on a paid invoice is credited in the invoice currency at the attempt rate', function () {
    Currency::query()->where('code', 'NPR')->exists() || Currency::factory()->npr()->create();
    $invoice = issueInvoice(billingClient(), [10_000]);
    // Charged NPR 13,350.00 for USD 100.00 at the rate locked when the attempt started.
    $attempt = lateProbeAttempt($invoice, 'probe-fx-1', ['amount' => 1_335_000, 'currency' => 'NPR', 'fx_rate' => '133.5']);
    payInvoice($invoice, 10_000, 'bank-fx-1');

    $this->artisan('billing:reconcile-payments')->assertSuccessful();

    expect(Transaction::query()->where('gateway', 'late_probe_wallet')->sole())
        ->amount_in->toBe(10_000)->currency->toBe('USD')
        ->gateway_amount->toBe(1_335_000)->gateway_currency->toBe('NPR')
        ->and((float) Transaction::query()->where('gateway', 'late_probe_wallet')->sole()->exchange_rate)->toBe(133.5)
        ->and(app(CreditLedgerService::class)->balance($invoice->client_id, 'USD')->amount)->toBe(10_000)
        ->and(app(CreditLedgerService::class)->balance($invoice->client_id, 'NPR')->amount)->toBe(0)
        ->and($attempt->fresh()->status)->toBe(AttemptStatus::Succeeded);
});

test('two closed attempts paid late on one invoice pay it once and credit the second in full', function () {
    $invoice = issueInvoice(billingClient(), [4_000]);
    $first = lateProbeAttempt($invoice, 'probe-twice-1');
    $second = lateProbeAttempt($invoice, 'probe-twice-2', ['status' => AttemptStatus::Failed]);

    $this->artisan('billing:reconcile-payments')->assertSuccessful();
    $this->travel(2)->hours();
    $this->artisan('billing:reconcile-payments')->assertSuccessful();

    expect(Transaction::query()->where('gateway', 'late_probe_wallet')->count())->toBe(2)
        ->and($invoice->fresh())->status->toBe(InvoiceStatus::Paid)->amount_paid->toBe(4_000)->balance->toBe(0)
        ->and(app(CreditLedgerService::class)->balance($invoice->client_id, 'USD')->amount)->toBe(4_000)
        ->and($first->fresh()->status)->toBe(AttemptStatus::Succeeded)
        ->and($second->fresh()->status)->toBe(AttemptStatus::Succeeded)
        ->and(ActivityLog::query()->where('event', 'billing.payment.late')->count())->toBe(2)
        ->and(lateProbeTodo())->toBe(2);
});

test('a closed attempt the provider reports as partly refunded is not silently dropped: staff review it', function () {
    // The customer paid after we closed the attempt and part of it was refunded at the provider before our next
    // lookup: the rest is money the provider kept. An open attempt in this state goes to review; a closed one must too.
    $invoice = issueInvoice(billingClient(), [5_000]);
    $attempt = lateProbeAttempt($invoice, 'probe-partial-1', answer: new LookupResult(LookupResult::PARTIALLY_REFUNDED, 'probe-partial-1', 5_000, 'USD'));

    $this->artisan('billing:reconcile-payments')->assertSuccessful();

    expect($attempt->fresh())->status->toBe(AttemptStatus::Ambiguous)->review_reason->not->toBeNull();

    $this->travel(31)->minutes();
    expect(collect(app(Dashboard::class)->resolveTodos('admin', billingStaff()))->firstWhere('key', 'billing.payment_review')['count'] ?? 0)->toBe(1);
});

test('the late sweep window column is indexed, so the 5-minute run does not scan every closed attempt ever made', function () {
    // PaymentReconciler filters closed attempts on status + created_at; the only status index is
    // (status, last_lookup_at), and old closed attempts all match last_lookup_at <= now - 60 min.
    $indexes = collect(Schema::getIndexes('payment_attempts'));

    expect($indexes->contains(fn (array $index): bool => $index['columns'][0] === 'created_at'
        || ($index['columns'][0] === 'status' && ($index['columns'][1] ?? null) === 'created_at')))->toBeTrue();
});
