<?php

/*
 * PR #113 review, round 2: the simulator against what the real Stripe sandbox did on 2026-10-20 (issues #123, #124):
 * decline, expiry by webhook, forged and unsigned webhooks refused with 403, replay de-duplication, a refund made in
 * the Stripe dashboard booked once, and a dispute that arrives before the payment answered 409 and booked on Stripe's
 * retry. Two probes fail at 8aa7fa2 (Stripe's retry of a non-2xx delivery; a session past its expires_at).
 */

use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\PaymentsStripe\Drivers\StripeDriver;
use App\Support\Integrations\Endpoints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Providers\Stripe\StripeSimulator;
use Tests\TestCase;

require_once dirname(__DIR__, 4).'/tests/Feature/PaymentsStripe/helpers.php';

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->scenario = Processes::start(StripeSimulator::class);
    config([
        'integrations.outbound.allow_loopback' => true,
        'integrations.endpoints.overrides' => $this->scenario->simulator()->appConfiguration($this->scenario)['env']['INTEGRATION_ENDPOINTS'],
        'integrations.endpoints.ca_bundle' => $this->scenario->ca()->bundlePath(),
    ]);
    app()->forgetInstance(Endpoints::class);
    stripeGateway(['secret_key' => StripeSimulator::SECRET_KEY, 'webhook_secret' => StripeSimulator::WEBHOOK_SECRET]);
    $this->scenario->simulator()->actions()['webhook_url'](['url' => route('billing.webhooks', StripeDriver::KEY)], $this->scenario);
});

afterEach(fn () => Processes::stop($this->scenario));

function parityBrowser(): PendingRequest
{
    return Http::withOptions(['verify' => test()->scenario->ca()->bundlePath(), 'allow_redirects' => false]);
}

/** The Stripe API as the dashboard or another integration uses it: the account's own key, not the app. */
function parityStripe(): PendingRequest
{
    return Http::withOptions(['verify' => test()->scenario->ca()->bundlePath()])->withToken(StripeSimulator::SECRET_KEY)->asForm();
}

function parityCheckout(Invoice $invoice): string
{
    $location = (string) test()->post(route('client.invoices.pay', $invoice->id), ['gateway' => StripeDriver::KEY])->headers->get('Location');

    return strstr($location, '#', true) ?: $location;
}

/**
 * Post one queued delivery to the app with the bytes and headers given; returns [status, result].
 *
 * @param  array{id: int, url: string, body: string, headers: array<string, string>}  $delivery
 * @param  (Closure(array<string, string>): array<string, string>)|null  $headers
 * @return array{0: int, 1: string}
 */
function parityDeliver(array $delivery, ?Closure $headers = null, ?string $variant = null): array
{
    $answer = [0, ''];
    test()->scenario->outbox()->deliver($delivery, function (string $url, string $body, array $sent) use (&$answer, $headers): int {
        $sent = $headers === null ? $sent : $headers($sent);
        $server = ['CONTENT_TYPE' => $sent['Content-Type'] ?? 'application/json'];

        if (isset($sent['Stripe-Signature'])) {
            $server['HTTP_STRIPE_SIGNATURE'] = $sent['Stripe-Signature'];
        }

        $response = test()->call('POST', $url, server: $server, content: $body);
        $answer = [$response->getStatusCode(), (string) $response->json('result')];

        return $response->getStatusCode();
    }, $variant);

    return $answer;
}

/**
 * @return array{id: int, url: string, body: string, headers: array<string, string>}
 */
function parityEvent(string $type): array
{
    foreach (test()->scenario->outbox()->pending() as $delivery) {
        if ((json_decode($delivery['body'], true)['type'] ?? null) === $type) {
            return $delivery;
        }
    }

    throw new RuntimeException("No pending {$type} delivery.");
}

test('a dispute that arrives before the payment is answered 409, and booked as a chargeback when Stripe retries', function () {
    [, $invoice] = usdInvoice([2_500]);
    parityBrowser()->post(parityCheckout($invoice).'/pay');
    $intent = (string) $this->scenario->state()->get('sessions', PaymentAttempt::query()->sole()->provider_ref)['payment_intent'];
    $this->scenario->simulator()->actions()['dispute'](['payment_intent' => $intent], $this->scenario);
    $dispute = parityEvent('charge.dispute.created');

    expect(parityDeliver($dispute)[0])->toBe(409);

    parityDeliver(parityEvent('checkout.session.completed'));
    parityDeliver(parityEvent('payment_intent.succeeded'));

    expect(parityDeliver($dispute)[1])->toBe('chargeback recorded')
        ->and(Transaction::query()->where('type', TransactionType::Chargeback)->count())->toBe(1)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Collections);
});

test('the outbox keeps a delivery the app did not accept, as Stripe retries any non-2xx answer', function () {
    [, $invoice] = usdInvoice([2_500]);
    parityBrowser()->post(parityCheckout($invoice).'/pay');
    $intent = (string) $this->scenario->state()->get('sessions', PaymentAttempt::query()->sole()->provider_ref)['payment_intent'];
    $this->scenario->simulator()->actions()['dispute'](['payment_intent' => $intent], $this->scenario);

    expect(parityDeliver(parityEvent('charge.dispute.created'))[0])->toBe(409)
        ->and(array_map(fn (array $delivery) => json_decode($delivery['body'], true)['type'], $this->scenario->outbox()->pending()))->toContain('charge.dispute.created');
});

test('a refund made in the Stripe dashboard is booked once from its webhooks, and replays book nothing', function () {
    [, $invoice] = usdInvoice([2_500]);
    parityBrowser()->post(parityCheckout($invoice).'/pay');
    array_map(fn (array $delivery) => parityDeliver($delivery), $this->scenario->outbox()->pending());
    $payment = Transaction::query()->where('type', TransactionType::Payment)->sole();

    parityStripe()->withHeaders(['Idempotency-Key' => 'dashboard-refund-1'])->post($this->scenario->apiUrl().'/v1/refunds', ['payment_intent' => $payment->gateway_txn_id, 'amount' => 700])->throw();
    $deliveries = $this->scenario->outbox()->pending();
    $first = array_map(fn (array $delivery) => parityDeliver($delivery)[0], $deliveries);
    $replays = array_map(fn (array $delivery) => parityDeliver($delivery)[1], $deliveries);

    expect($first)->each->toBe(200)
        ->and(Transaction::query()->where('type', TransactionType::Refund)->sole()->amount_out)->toBe(700)
        ->and($replays)->toContain('duplicate')
        ->and(array_diff($replays, ['duplicate', 'ignored event type']))->toBe([])
        ->and(Transaction::query()->where('type', TransactionType::Refund)->count())->toBe(1);
});

test('forged and unsigned webhooks are refused with 403 before anything is retrieved', function () {
    [, $invoice] = usdInvoice([2_500]);
    parityBrowser()->post(parityCheckout($invoice).'/pay');
    $completed = parityEvent('checkout.session.completed');

    expect(parityDeliver($completed, variant: 'wrong_secret')[0])->toBe(403)
        ->and(parityDeliver($completed, fn (array $headers): array => array_diff_key($headers, ['Stripe-Signature' => true]))[0])->toBe(403)
        ->and($this->scenario->log()->count('retrieve_checkout_session') + $this->scenario->log()->count('retrieve_payment_intent'))->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
});

test('a signature header that also carries a v0 element, as test-mode deliveries do, still verifies', function () {
    [, $invoice] = usdInvoice([2_500]);
    parityBrowser()->post(parityCheckout($invoice).'/pay');

    expect(parityDeliver(parityEvent('checkout.session.completed'), fn (array $headers): array => [...$headers, 'Stripe-Signature' => $headers['Stripe-Signature'].',v0='.str_repeat('0', 64)])[0])->toBe(200)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('a session expired at Stripe (dashboard or timeout) frees the invoice through its webhook', function () {
    [, $invoice] = usdInvoice([2_500]);
    parityCheckout($invoice);
    $attempt = PaymentAttempt::query()->sole();

    parityStripe()->withHeaders(['Idempotency-Key' => 'dashboard-expire-1'])->post($this->scenario->apiUrl()."/v1/checkout/sessions/{$attempt->provider_ref}/expire")->throw();

    expect(parityDeliver(parityEvent('checkout.session.expired'))[0])->toBe(200)
        ->and($attempt->fresh()->status)->toBe(AttemptStatus::Expired)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
});

test('a session past its expires_at reads as expired and queues checkout.session.expired, as Stripe does', function () {
    [, $invoice] = usdInvoice([2_500]);
    parityCheckout($invoice);
    $id = PaymentAttempt::query()->sole()->provider_ref;
    $this->scenario->state()->put('sessions', $id, [...$this->scenario->state()->get('sessions', $id), 'expires_at' => time() - 1]);

    expect(parityStripe()->get($this->scenario->apiUrl()."/v1/checkout/sessions/{$id}")->json('status'))->toBe('expired')
        ->and(array_map(fn (array $delivery) => json_decode($delivery['body'], true)['type'], $this->scenario->outbox()->pending()))->toContain('checkout.session.expired');
});
