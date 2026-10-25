<?php

/*
| Shared helpers for the Razorpay gateway tests (require_once from each test file). FakeRazorpay answers the API calls
| the driver makes from state a test sets, in the shapes of razorpay.com/docs/api and our simulator
| (tools/simulators/src/Providers/Razorpay).
*/

use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\PaymentsRazorpay\Drivers\RazorpayDriver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../Billing/helpers.php';

const RZP_KEY_ID = 'rzp_test_MoharFakeKey01';
const RZP_KEY_SECRET = 'MoharFakeRazorpayKeySecret000001';
const RZP_WEBHOOK_SECRET = 'MoharFakeRazorpayWebhookSecret01';
const RZP_API = 'https://api.razorpay.com/v1/';
const RZP_LINK = 'plink_MoharLink000001';
const RZP_ORDER = 'order_MoharOrder00001';
const RZP_PAYMENT = 'pay_MoharPayment00001';

/**
 * Razorpay's side of a test. Each entry is a body, an HTTP status (answered with Razorpay's error envelope) or a
 * closure that returns either or throws (a timeout).
 */
final class FakeRazorpay
{
    /** @var array<string, mixed> */
    public static array $links = [];

    /** @var array<string, mixed> */
    public static array $payments = [];

    /** @var array<string, mixed> */
    public static array $refunds = [];

    /** @var (Closure(Request): mixed)|null answers POST /payments/{id}/refund; by default a processed refund */
    public static ?Closure $refund = null;

    public static function install(): void
    {
        self::$links = self::$payments = self::$refunds = [];
        self::$refund = null;
        Http::fake(fn (Request $request) => self::answer($request));
    }

    private static function answer(Request $request): mixed
    {
        $path = substr(strtok($request->url(), '?'), strlen(RZP_API));
        $id = explode('/', $path)[1] ?? '';

        $answer = match (true) {
            $request->method() === 'POST' && $path === 'payment_links' => self::$links[RZP_LINK] = rzpLink($request['reference_id'], $request['amount'], ['notes' => $request['notes'], 'expire_by' => $request['expire_by']]),
            $request->method() === 'POST' && str_ends_with($path, '/cancel') => is_array(self::$links[$id] ?? null) && self::$links[$id]['status'] === 'created'
                ? self::$links[$id] = [...self::$links[$id], 'status' => 'cancelled'] : 400,
            $request->method() === 'POST' && str_ends_with($path, '/refund') => self::$refund !== null ? (self::$refund)($request)
                : self::$refunds['rfnd_MoharRefund'.(count(self::$refunds) + 1)] = rzpRefund('rfnd_MoharRefund'.(count(self::$refunds) + 1), $request['amount'], ['notes' => $request['notes']]),
            str_ends_with($path, '/refunds') => ['entity' => 'collection', 'count' => count(self::$refunds), 'items' => array_values(array_filter(self::$refunds, 'is_array'))],
            str_starts_with($path, 'payment_links/') => self::$links[$id] ?? 400,
            str_starts_with($path, 'payments/') => self::$payments[$id] ?? 400,
            str_starts_with($path, 'refunds/') => self::$refunds[$id] ?? 400,
            default => 404,
        };
        $answer = $answer instanceof Closure ? $answer($request) : $answer;

        return is_int($answer)
            ? Http::response(['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => $answer === 400 ? 'The id provided does not exist' : 'Request failed', 'source' => 'NA', 'step' => 'NA', 'reason' => 'NA']], $answer)
            : Http::response($answer);
    }
}

/**
 * The active Razorpay gateway in test mode with the fake keys.
 *
 * @param  array<string, string>  $config
 */
function razorpayGateway(array $config = []): Gateway
{
    return Gateway::query()->create([
        'key' => RazorpayDriver::KEY,
        'display_name' => 'Razorpay',
        'is_active' => true,
        'show_on_order_form' => true,
        'sort_order' => 5,
        'config' => ['sandbox' => '1', 'key_id' => RZP_KEY_ID, 'key_secret' => RZP_KEY_SECRET, 'webhook_secret' => RZP_WEBHOOK_SECRET, ...$config],
    ]);
}

/**
 * A payment link as Razorpay returns it for an attempt (open unless overridden).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rzpLink(string $reference, int $amount, array $overrides = []): array
{
    return array_replace([
        'id' => RZP_LINK, 'amount' => $amount, 'amount_paid' => 0, 'currency' => 'INR', 'accept_partial' => false, 'status' => 'created',
        'reference_id' => $reference, 'order_id' => RZP_ORDER, 'notes' => ['mohar_reference' => $reference], 'payments' => null,
        'short_url' => 'https://rzp.io/i/MoharLnk', 'expire_by' => now()->addMinutes(30)->getTimestamp(), 'created_at' => now()->getTimestamp(),
    ], $overrides);
}

/**
 * A captured payment (the fee includes GST, as Razorpay reports it).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rzpPayment(int $amount, string $reference, array $overrides = []): array
{
    return array_replace([
        'id' => RZP_PAYMENT, 'entity' => 'payment', 'amount' => $amount, 'currency' => 'INR', 'status' => 'captured', 'order_id' => RZP_ORDER,
        'method' => 'upi', 'amount_refunded' => 0, 'refund_status' => null, 'captured' => true, 'fee' => 2_668, 'tax' => 407,
        'notes' => ['mohar_reference' => $reference], 'vpa' => 'success@razorpay', 'email' => 'payer@example.test', 'contact' => '+919000090000',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rzpRefund(string $id, int $amount, array $overrides = []): array
{
    return array_replace(['id' => $id, 'entity' => 'refund', 'amount' => $amount, 'currency' => 'INR', 'payment_id' => RZP_PAYMENT, 'notes' => [], 'status' => 'processed', 'speed_processed' => 'normal'], $overrides);
}

/**
 * The link paid by RZP_PAYMENT, and that payment, at Razorpay.
 *
 * @param  array<string, mixed>  $link
 * @param  array<string, mixed>  $payment
 */
function razorpayPaid(PaymentAttempt $attempt, array $link = [], array $payment = []): void
{
    FakeRazorpay::$links[RZP_LINK] = rzpLink($attempt->reference, $attempt->amount, ['status' => 'paid', 'amount_paid' => $attempt->amount,
        'payments' => [['payment_id' => RZP_PAYMENT, 'amount' => $attempt->amount, 'status' => 'captured', 'method' => 'upi']], ...$link]);
    FakeRazorpay::$payments[RZP_PAYMENT] = rzpPayment($attempt->amount, $attempt->reference, $payment);
}

/**
 * The client starts a Razorpay payment for a fresh INR invoice (Rs 1,130.00 by default).
 *
 * @return array{0: Client, 1: Invoice, 2: PaymentAttempt}
 */
function startRazorpay(int $amount = 113_000): array
{
    Currency::query()->where('code', 'INR')->exists() || Currency::factory()->inr()->create();
    [, $client] = actingAsClient(Client::factory()->create(['currency' => 'INR', 'country' => 'IN']));
    $invoice = issueInvoice($client, [$amount]);

    test()->post(route('client.invoices.pay', $invoice->id), ['gateway' => RazorpayDriver::KEY])->assertRedirect('https://rzp.io/i/MoharLnk');

    return [$client, $invoice, PaymentAttempt::query()->where('invoice_id', $invoice->id)->firstOrFail()];
}

/**
 * The return URL Razorpay sends the browser to, signed with the key secret (or $secret).
 */
function razorpayReturn(PaymentAttempt $attempt, string $status = 'paid', string $secret = RZP_KEY_SECRET): string
{
    return route('billing.gateways.return', [RazorpayDriver::KEY,
        'razorpay_payment_id' => RZP_PAYMENT, 'razorpay_payment_link_id' => RZP_LINK, 'razorpay_payment_link_reference_id' => $attempt->reference,
        'razorpay_payment_link_status' => $status, 'razorpay_signature' => hash_hmac('sha256', implode('|', [RZP_LINK, $attempt->reference, $status, RZP_PAYMENT]), $secret)]);
}

/**
 * Razorpay's event envelope.
 *
 * @param  array<string, array<string, mixed>>  $entities  entity name => entity
 * @return array<string, mixed>
 */
function rzpEvent(string $event, array $entities): array
{
    return ['entity' => 'event', 'account_id' => 'acc_MoharAccount01', 'event' => $event, 'contains' => array_keys($entities),
        'payload' => array_map(fn (array $entity): array => ['entity' => $entity], $entities), 'created_at' => now()->getTimestamp()];
}

/**
 * POST a webhook to Billing's endpoint, signed like Razorpay (or with the given signature).
 *
 * @param  array<string, mixed>|string  $event
 */
function postRazorpayWebhook(array|string $event, string $eventId = 'MoharEvent0001', string $secret = RZP_WEBHOOK_SECRET, ?string $signature = null): TestResponse
{
    $body = is_string($event) ? $event : (string) json_encode($event);

    return test()->call('POST', route('billing.webhooks', RazorpayDriver::KEY), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_RAZORPAY_SIGNATURE' => $signature ?? hash_hmac('sha256', $body, $secret),
        'HTTP_X_RAZORPAY_EVENT_ID' => $eventId,
    ], content: $body);
}
