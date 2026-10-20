<?php

/*
| Security review probes for PR #152 (late provider successes on closed attempts, #130).
| Each test pins a defence: only a provider-confirmed lookup books a closed attempt, at most once, onto the
| attempt's own invoice and client, and only staff with the sensitive payments permission act on it.
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
use App\Models\User;
use App\Modules\Billing\Actions\SettlePaymentAttempt;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\GatewayLog;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Platform\Services\SettingsCatalog;
use App\Support\Billing\AbstractGatewayDriver;
use App\Support\Dashboard\Dashboard;
use Carbon\CarbonInterval;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

require_once __DIR__.'/../Billing/helpers.php';

/**
 * Lookup-only NPR wallet whose provider side the test controls; $beforeAnswer runs inside lookup(), i.e. after
 * the caller read the attempt but before it takes the row lock, which is where a concurrent request interleaves.
 */
class LateSecurityProbeWalletDriver extends AbstractGatewayDriver implements HostedPaymentDriver
{
    /** @var array<string, LookupResult> */
    public static array $provider = [];

    /** @var array<string, int> */
    public static array $lookups = [];

    /** @var (Closure(string): void)|null */
    public static ?Closure $beforeAnswer = null;

    public function key(): string
    {
        return 'probe_wallet';
    }

    public function name(): string
    {
        return 'Probe wallet';
    }

    public function capabilities(): array
    {
        return [self::REDIRECT, self::LOOKUP];
    }

    public function currencies(): array
    {
        return ['NPR'];
    }

    public function settingsFields(): array
    {
        return [['key' => 'secret_key', 'label' => 'Secret key', 'type' => 'secret']];
    }

    public function limits(GatewayConfig $gateway): AmountLimits
    {
        return new AmountLimits(min: 1000, max: 10_000_000);
    }

    public function attemptTtl(GatewayConfig $gateway): CarbonInterval
    {
        return CarbonInterval::minutes(15);
    }

    public function initiate(GatewayConfig $gateway, PaymentIntent $intent): RedirectInstruction|FormPost|QrPayload
    {
        return new FormPost('https://wallet.test/form', ['transaction_uuid' => $intent->reference]);
    }

    public function parseReturn(GatewayConfig $gateway, Request $request): ReturnHint
    {
        return new ReturnHint($request->string('ref')->toString() ?: null);
    }

    public function lookup(GatewayConfig $gateway, PaymentAttemptRef $attempt): LookupResult
    {
        self::$lookups[$attempt->reference] = (self::$lookups[$attempt->reference] ?? 0) + 1;

        if (self::$beforeAnswer !== null) {
            $hook = self::$beforeAnswer;
            self::$beforeAnswer = null;
            $hook($attempt->reference);
        }

        return self::$provider[$attempt->reference] ?? new LookupResult(LookupResult::NOT_FOUND);
    }
}

beforeEach(function () {
    LateSecurityProbeWalletDriver::$provider = [];
    LateSecurityProbeWalletDriver::$lookups = [];
    LateSecurityProbeWalletDriver::$beforeAnswer = null;
    app(GatewayRegistry::class)->register(new LateSecurityProbeWalletDriver);
    Gateway::query()->create(['key' => 'probe_wallet', 'display_name' => 'Probe wallet', 'is_active' => true, 'show_on_order_form' => true, 'config' => ['secret_key' => 'probe-wallet-secret-9f3a']]);
    Currency::query()->where('code', 'NPR')->exists() || Currency::factory()->npr()->create();
});

/**
 * A real attempt for a fresh NPR invoice of a new client, closed by the real flow (TTL plus grace, provider never
 * saw it), so expires_at is in the past as in production.
 *
 * @return array{0: Invoice, 1: PaymentAttempt, 2: Client}
 */
function probeClosedAttempt(int $amount = 50_000): array
{
    $client = Client::factory()->create(['currency' => 'NPR', 'country' => 'NP']);
    probeActAsClient($client);
    $invoice = issueInvoice($client, [$amount]);
    test()->post(route('client.invoices.pay', $invoice->id), ['gateway' => 'probe_wallet']);
    $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->latest('id')->firstOrFail();
    test()->travel(16 + 121)->minutes();
    test()->artisan('billing:reconcile-payments');
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Expired);

    return [$invoice, $attempt->fresh(), $client];
}

function probeActAsClient(Client $client): void
{
    Currency::query()->where('code', 'USD')->exists() || Currency::factory()->create();
    $user = User::query()->where('email', $client->email)->first();

    if ($user === null) {
        $user = User::factory()->create(['email' => $client->email]);
        $client->users()->attach($user, ['is_owner' => true]);
    }

    test()->actingAs($user);
}

function probeCredit(int $clientId): int
{
    return (int) CreditLedgerEntry::query()->where('client_id', $clientId)->sum('amount');
}

function probeLateTodo(): ?array
{
    return collect(app(Dashboard::class)->resolveTodos('admin', billingStaff()))->firstWhere('key', 'billing.late_payments');
}

test('two settles of one closed attempt that interleave between lookup and lock book it once', function () {
    [$invoice, $attempt, $client] = probeClosedAttempt();
    LateSecurityProbeWalletDriver::$provider[$attempt->reference] = new LookupResult(LookupResult::SUCCEEDED, 'probe-txn-race', 50_000, 'NPR');
    // A webhook (or the return, or "Check now") settles the attempt while the sweep is waiting on its own lookup.
    LateSecurityProbeWalletDriver::$beforeAnswer = fn () => app(SettlePaymentAttempt::class)->handle($attempt->fresh());

    $this->travel(61)->minutes();
    $this->artisan('billing:reconcile-payments');

    expect(Transaction::query()->where('gateway', 'probe_wallet')->count())->toBe(1)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(probeCredit($client->id))->toBe(0)
        ->and(ActivityLog::query()->where('event', 'billing.payment.late')->count())->toBe(1)
        ->and($attempt->fresh()->status)->toBe(AttemptStatus::Succeeded);
});

test('a provider txn id already booked for client A never books or credits client B', function () {
    [$invoiceA, $attemptA, $clientA] = probeClosedAttempt(50_000);
    [$invoiceB, $attemptB, $clientB] = probeClosedAttempt(70_000);
    LateSecurityProbeWalletDriver::$provider[$attemptA->reference] = new LookupResult(LookupResult::SUCCEEDED, 'probe-txn-shared', 50_000, 'NPR');
    LateSecurityProbeWalletDriver::$provider[$attemptB->reference] = new LookupResult(LookupResult::SUCCEEDED, 'probe-txn-shared', 70_000, 'NPR');

    $this->travel(61)->minutes();
    $this->artisan('billing:reconcile-payments');
    $this->travel(61)->minutes();
    $this->artisan('billing:reconcile-payments');

    expect(Transaction::query()->where('gateway', 'probe_wallet')->get(['client_id', 'invoice_id'])->toArray())->toBe([['client_id' => $clientA->id, 'invoice_id' => $invoiceA->id]])
        ->and($invoiceB->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(probeCredit($clientA->id))->toBe(0)
        ->and(probeCredit($clientB->id))->toBe(0)
        ->and($attemptB->fresh())->status->toBe(AttemptStatus::Ambiguous)->transaction_id->toBeNull()
        ->review_reason->toBe('Provider transaction already used by another attempt');
});

test('replays after Mark checked through the sweep, a settle and Check now book nothing and keep the to-do closed', function () {
    [$invoice, $attempt] = probeClosedAttempt();
    LateSecurityProbeWalletDriver::$provider[$attempt->reference] = new LookupResult(LookupResult::SUCCEEDED, 'probe-txn-replay', 50_000, 'NPR');
    $this->travel(61)->minutes();
    $this->artisan('billing:reconcile-payments');
    billingStaff();
    confirmPassword();
    $this->post(route('admin.billing.payment-attempts.late-checked', $attempt))->assertRedirect();
    $checkedAt = $attempt->fresh()->late_checked_at;

    $this->travel(2)->hours();
    $this->artisan('billing:reconcile-payments');
    app(SettlePaymentAttempt::class)->handle($attempt->fresh());
    $this->post(route('admin.billing.payment-attempts.check', $attempt))->assertRedirect();
    $this->get(route('billing.gateways.return', ['probe_wallet', 'ref' => $attempt->reference]))->assertRedirect();

    expect(Transaction::query()->where('gateway', 'probe_wallet')->count())->toBe(1)
        ->and(ActivityLog::query()->where('event', 'billing.payment.late')->count())->toBe(1)
        ->and($attempt->fresh()->late_checked_at?->toIso8601String())->toBe($checkedAt?->toIso8601String())
        ->and(probeLateTodo())->toBeNull()
        // A succeeded attempt is never looked up again.
        ->and(LateSecurityProbeWalletDriver::$lookups[$attempt->reference])->toBe(2);
});

test('a late success for another amount or currency books nothing and does not lock the invoice', function (int $amount, string $currency) {
    [$invoice, $attempt, $client] = probeClosedAttempt(50_000);
    LateSecurityProbeWalletDriver::$provider[$attempt->reference] = new LookupResult(LookupResult::SUCCEEDED, 'probe-txn-tamper', $amount, $currency);

    $this->travel(61)->minutes();
    $this->artisan('billing:reconcile-payments');

    expect(Transaction::query()->count())->toBe(0)
        ->and(probeCredit($client->id))->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and($attempt->fresh()->status)->toBe(AttemptStatus::Ambiguous);

    // Held for staff review, but the customer can still pay the invoice another way.
    probeActAsClient($client);
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => 'probe_wallet']);
    expect(PaymentAttempt::query()->where('invoice_id', $invoice->id)->count())->toBe(2);
})->with([
    'more' => [50_001, 'NPR'],
    'less' => [49_999, 'NPR'],
    'zero' => [0, 'NPR'],
    'negative' => [-50_000, 'NPR'],
    'other currency' => [50_000, 'USD'],
]);

test('a closed attempt refunded at the provider stays closed and books nothing', function (string $status) {
    [$invoice, $attempt] = probeClosedAttempt();
    LateSecurityProbeWalletDriver::$provider[$attempt->reference] = new LookupResult($status, 'probe-txn-refund', 50_000, 'NPR');

    $this->travel(61)->minutes();
    $this->artisan('billing:reconcile-payments');

    expect(Transaction::query()->count())->toBe(0)
        ->and($attempt->fresh())->status->toBe(AttemptStatus::Expired)->late_payment_at->toBeNull();
})->with([LookupResult::REFUNDED, LookupResult::AMBIGUOUS, LookupResult::PROCESSING, LookupResult::CANCELLED]);
// Adopted for #152: PARTIALLY_REFUNDED left the dataset. Board M1 sends it to staff review (the provider kept the
// rest); LatePaymentBoardProbesTest pins that.

test('a late payment on a cancelled invoice is credited in full, in the invoice currency, to the invoice client', function () {
    [$invoice, $attempt, $client] = probeClosedAttempt(50_000);
    $invoice->update(['status' => InvoiceStatus::Cancelled]);
    LateSecurityProbeWalletDriver::$provider[$attempt->reference] = new LookupResult(LookupResult::SUCCEEDED, 'probe-txn-cancelled', 50_000, 'NPR');

    $this->travel(61)->minutes();
    $this->artisan('billing:reconcile-payments');

    $entry = CreditLedgerEntry::query()->where('client_id', $client->id)->sole();
    expect($entry)->amount->toBe(50_000)->currency->toBe('NPR')->transaction_id->toBe($attempt->fresh()->transaction_id)
        ->and($invoice->fresh())->status->toBe(InvoiceStatus::Cancelled)->amount_paid->toBe(0)
        ->and(ActivityLog::query()->where('event', 'billing.payment.late')->sole()->properties)->toMatchArray(['applied' => 0, 'credited' => 50_000])
        ->and(probeLateTodo()['count'])->toBe(1);
});

test('a late payment larger than the remaining balance splits exactly, with no negative or lost amount', function () {
    [$invoice, $attempt, $client] = probeClosedAttempt(50_000);
    payInvoice($invoice->fresh(), 12_345, 'bank-partial-1');
    LateSecurityProbeWalletDriver::$provider[$attempt->reference] = new LookupResult(LookupResult::SUCCEEDED, 'probe-txn-split', 50_000, 'NPR');

    $this->travel(61)->minutes();
    $this->artisan('billing:reconcile-payments');

    expect($invoice->fresh())->status->toBe(InvoiceStatus::Paid)->balance->toBe(0)
        ->and(probeCredit($client->id))->toBe(12_345)
        ->and(CreditLedgerEntry::query()->where('client_id', $client->id)->where('amount', '<', 0)->exists())->toBeFalse()
        ->and(ActivityLog::query()->where('event', 'billing.payment.late')->sole()->properties)->toMatchArray(['applied' => 50_000 - 12_345, 'credited' => 12_345]);
});

test('superseded attempts paid twice are both booked: the later booking goes to credit and the late one is flagged', function () {
    [$invoice, $first, $client] = probeClosedAttempt(50_000);
    probeActAsClient($client);
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => 'probe_wallet']);
    $second = PaymentAttempt::query()->where('invoice_id', $invoice->id)->whereKeyNot($first->id)->sole();
    LateSecurityProbeWalletDriver::$provider[$second->reference] = new LookupResult(LookupResult::SUCCEEDED, 'probe-txn-second', 50_000, 'NPR');
    $this->artisan('billing:reconcile-payments');
    LateSecurityProbeWalletDriver::$provider[$first->reference] = new LookupResult(LookupResult::SUCCEEDED, 'probe-txn-first', 50_000, 'NPR');

    $this->travel(61)->minutes();
    $this->artisan('billing:reconcile-payments');

    expect(Transaction::query()->where('gateway', 'probe_wallet')->sum('amount_in'))->toEqual(100_000)
        ->and(probeCredit($client->id))->toBe(50_000)
        ->and($first->fresh()->late_payment_at)->not->toBeNull()
        ->and(probeLateTodo()['count'])->toBe(1);
});

test('the late payment audit and gateway log carry no gateway secret', function () {
    [, $attempt] = probeClosedAttempt();
    LateSecurityProbeWalletDriver::$provider[$attempt->reference] = new LookupResult(LookupResult::SUCCEEDED, 'probe-txn-secret', 50_000, 'NPR',
        raw: ['secret_key' => 'probe-wallet-secret-9f3a', 'authorization' => 'Key probe-wallet-secret-9f3a', 'status' => 'COMPLETE']);

    $this->travel(61)->minutes();
    $this->artisan('billing:reconcile-payments');

    expect(Transaction::query()->count())->toBe(1)
        ->and(json_encode(GatewayLog::query()->get()->toArray()))->not->toContain('probe-wallet-secret-9f3a')
        ->and(json_encode(ActivityLog::query()->get()->toArray()))->not->toContain('probe-wallet-secret-9f3a')
        ->and(json_encode(PaymentAttempt::query()->get()->toArray()))->not->toContain('probe-wallet-secret-9f3a')
        ->and(json_encode(Transaction::query()->get()->toArray()))->not->toContain('probe-wallet-secret-9f3a');
});

test('the sensitive payments permission gates Check now, Mark checked and the late filter; a billing wildcard does not grant it', function () {
    [, $attempt] = probeClosedAttempt();
    LateSecurityProbeWalletDriver::$provider[$attempt->reference] = new LookupResult(LookupResult::SUCCEEDED, 'probe-txn-perm', 50_000, 'NPR');
    $this->travel(61)->minutes();
    $this->artisan('billing:reconcile-payments');
    expect($attempt->fresh()->late_payment_at)->not->toBeNull();

    // With the permission but no recent password, Mark checked is refused.
    billingStaff();
    $this->post(route('admin.billing.payment-attempts.late-checked', $attempt));
    expect($attempt->fresh()->late_checked_at)->toBeNull();

    actingAsStaff(['billing.*']);
    confirmPassword();
    $this->get(route('admin.billing.payment-attempts.index', ['late' => 1]))->assertForbidden();
    $this->post(route('admin.billing.payment-attempts.check', $attempt))->assertForbidden();
    $this->post(route('admin.billing.payment-attempts.late-checked', $attempt))->assertForbidden();
    expect($attempt->fresh()->late_checked_at)->toBeNull();
});

test('a client cannot reach the staff late-payment routes', function () {
    [, $attempt, $client] = probeClosedAttempt();
    probeActAsClient($client);

    $this->get(route('admin.billing.payment-attempts.index', ['late' => 1]))->assertForbidden();
    $this->post(route('admin.billing.payment-attempts.check', $attempt))->assertForbidden();
    $this->post(route('admin.billing.payment-attempts.late-checked', $attempt))->assertForbidden();
});

test('the late lookup window setting is bounded', function (int $hours) {
    $rules = app(SettingsCatalog::class)->rules('billing');
    $key = 'billing.gateways.late_lookup_hours';

    expect(Validator::make(['billing' => ['gateways' => ['late_lookup_hours' => $hours]]], [$key => $rules[$key]])->fails())->toBeTrue();
})->with(['negative' => -1, 'a year' => 24 * 366, 'absurd' => 2_000_000_000]);

test('an absurd late lookup window stored directly does not break the poller', function () {
    [$invoice, $attempt] = probeClosedAttempt();
    billingSettings(['gateways.late_lookup_hours' => 2_000_000_000]);
    LateSecurityProbeWalletDriver::$provider[$attempt->reference] = new LookupResult(LookupResult::SUCCEEDED, 'probe-txn-huge', 50_000, 'NPR');

    $this->travel(61)->minutes();
    $this->artisan('billing:reconcile-payments')->assertSuccessful();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('the anonymous return route does not look up a closed attempt older than the late window', function () {
    [, $attempt] = probeClosedAttempt();
    $before = LateSecurityProbeWalletDriver::$lookups[$attempt->reference] ?? 0;
    $this->travel(30)->days();
    auth()->logout();

    $this->get(route('billing.gateways.return', ['probe_wallet', 'ref' => $attempt->reference]))->assertRedirect();

    expect(LateSecurityProbeWalletDriver::$lookups[$attempt->reference] ?? 0)->toBe($before);
});

test('a late payment that went wholly to account credit can be refunded, and the refund takes the credit back', function () {
    [$invoice, $attempt, $client] = probeClosedAttempt(50_000);
    payInvoice($invoice->fresh(), 50_000, 'bank-full-1');
    LateSecurityProbeWalletDriver::$provider[$attempt->reference] = new LookupResult(LookupResult::SUCCEEDED, 'probe-txn-refundable', 50_000, 'NPR');
    $this->travel(61)->minutes();
    $this->artisan('billing:reconcile-payments');
    $late = Transaction::query()->where('gateway', 'probe_wallet')->sole();
    expect(probeCredit($client->id))->toBe(50_000);

    // "Check and refund if needed": staff refund it (recorded after refunding at the provider).
    billingStaff();
    confirmPassword();
    $this->post(route('admin.billing.transactions.refund', $late), ['amount' => '500.00', 'mode' => 'record_only'])->assertSessionHasNoErrors();

    expect(probeCredit($client->id))->toBe(0);
})->skip('#154: refunding late credit from inside the app (follow-up from the #152 review). The provider refund webhook takes the credit back.');

test('when the late attempt takes the invoice first, the on-time payment that then goes wholly to credit is flagged too', function () {
    [$invoice, $first, $client] = probeClosedAttempt(50_000);
    probeActAsClient($client);
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => 'probe_wallet']);
    $second = PaymentAttempt::query()->where('invoice_id', $invoice->id)->whereKeyNot($first->id)->sole();
    LateSecurityProbeWalletDriver::$provider[$first->reference] = new LookupResult(LookupResult::SUCCEEDED, 'probe-txn-late-first', 50_000, 'NPR');
    $this->travel(61)->minutes();
    $this->artisan('billing:reconcile-payments');
    LateSecurityProbeWalletDriver::$provider[$second->reference] = new LookupResult(LookupResult::SUCCEEDED, 'probe-txn-ontime-second', 50_000, 'NPR');
    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments');

    // The customer paid twice; the credit sits on the second payment, which nothing flags today.
    expect(probeCredit($client->id))->toBe(50_000)
        ->and(probeLateTodo()['count'])->toBe(2);
});
