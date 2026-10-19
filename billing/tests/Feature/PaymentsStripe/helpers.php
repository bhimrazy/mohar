<?php

/*
| Shared helpers for the Stripe gateway tests (require_once from each test file). Fixtures: tests/Fixtures/Stripe.
*/

use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Core\Models\Client;
use App\Modules\PaymentsStripe\Drivers\StripeDriver;
use App\Modules\PaymentsStripe\Support\WebhookSignature;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../Billing/helpers.php';

const STRIPE_SECRET = 'sk_test_51MoharFakeSecretKeyForTests0000000000';
const STRIPE_WEBHOOK_SECRET = 'whsec_MoharFakeWebhookSecretForTests00';
const STRIPE_API = 'https://api.stripe.com/v1/';
const STRIPE_SESSION = 'cs_test_a1MoharSession0001';
const STRIPE_INTENT = 'pi_3MoharIntent0001';

/**
 * A fixture body with fields replaced (nested arrays merge).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function stripeFixture(string $name, array $overrides = []): array
{
    $fixture = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/Stripe/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);

    return array_replace_recursive($fixture, $overrides);
}

/**
 * The active Stripe gateway in test mode with the fake keys.
 *
 * @param  array<string, string>  $config
 */
function stripeGateway(array $config = []): Gateway
{
    return Gateway::query()->create([
        'key' => StripeDriver::KEY,
        'display_name' => 'Stripe',
        'is_active' => true,
        'show_on_order_form' => true,
        'sort_order' => 5,
        'config' => ['sandbox' => '1', 'secret_key' => STRIPE_SECRET, 'webhook_secret' => STRIPE_WEBHOOK_SECRET, ...$config],
    ]);
}

/**
 * A signed-in USD client with one unpaid invoice of the given lines (cents).
 *
 * @param  list<int>  $lines
 * @return array{0: Client, 1: Invoice}
 */
function usdInvoice(array $lines = [2_500]): array
{
    [, $client] = actingAsClient(Client::factory()->create(['currency' => 'USD']));

    return [$client, issueInvoice($client, $lines)];
}

/**
 * Session create response for an attempt reference (Stripe echoes our reference and metadata).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function stripeCreatedSession(string $reference, int $invoiceId, array $overrides = []): array
{
    return stripeFixture('checkout_session_created', [
        'client_reference_id' => $reference,
        'metadata' => ['mohar_reference' => $reference, 'mohar_invoice_id' => (string) $invoiceId],
        'expires_at' => now()->addMinutes(35)->getTimestamp(),
        ...$overrides,
    ]);
}

/**
 * A paid session (expanded PaymentIntent, charge and balance transaction) for an attempt.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function stripePaidSession(PaymentAttempt $attempt, array $overrides = []): array
{
    $metadata = ['mohar_reference' => $attempt->reference, 'mohar_invoice_id' => (string) $attempt->invoice_id];

    return stripeFixture('checkout_session_paid', array_replace_recursive([
        'client_reference_id' => $attempt->reference,
        'metadata' => $metadata,
        'payment_intent' => ['metadata' => $metadata],
    ], $overrides));
}

/**
 * The client starts a Stripe payment for a fresh USD invoice; Stripe answers with STRIPE_SESSION.
 *
 * @return array{0: Client, 1: Invoice, 2: PaymentAttempt}
 */
function startStripe(int $amount = 2_500): array
{
    [$client, $invoice] = usdInvoice([$amount]);
    Http::fake([STRIPE_API.'checkout/sessions' => fn (Request $request) => Http::response(stripeCreatedSession($request['client_reference_id'], $invoice->id))]);

    test()->post(route('client.invoices.pay', $invoice->id), ['gateway' => StripeDriver::KEY])
        ->assertRedirect(stripeFixture('checkout_session_created')['url']);

    return [$client, $invoice, PaymentAttempt::query()->where('invoice_id', $invoice->id)->firstOrFail()];
}

/**
 * The event envelope Stripe posts for an object.
 *
 * @param  array<string, mixed>  $object  posted object fields (never trusted by the driver)
 * @return array<string, mixed>
 */
function stripeEvent(string $type, string $objectId, array $object = [], string $eventId = 'evt_1MoharEvent0001', bool $livemode = false): array
{
    return stripeFixture('event', ['id' => $eventId, 'type' => $type, 'livemode' => $livemode, 'data' => ['object' => ['id' => $objectId, ...$object]]]);
}

/**
 * POST a webhook to Billing's endpoint, signed like Stripe (or with the given header).
 *
 * @param  array<string, mixed>|string  $event
 */
function postStripeWebhook(array|string $event, ?int $timestamp = null, string $secret = STRIPE_WEBHOOK_SECRET, ?string $header = null): TestResponse
{
    $body = is_string($event) ? $event : (string) json_encode($event);
    $header ??= WebhookSignature::header($body, $secret, $timestamp ?? time());

    return test()->call('POST', route('billing.webhooks', StripeDriver::KEY), server: ['HTTP_STRIPE_SIGNATURE' => $header, 'CONTENT_TYPE' => 'application/json'], content: $body);
}
