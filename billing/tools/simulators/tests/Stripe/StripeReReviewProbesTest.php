<?php

/*
 * PR #113 re-review probes (head 93bde8bb): #124 end to end through the outbox's own retry (`drain()`), with no manual
 * replay, against the real app, driver and webhook route. Passes at 93bde8bb; adopt it as the drain() regression test.
 */

use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\PaymentsStripe\Drivers\StripeDriver;
use App\Support\Integrations\Endpoints;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

test('#124 through drain(): a dispute refused with 409 before its payment is booked by the outbox retry', function () {
    [, $invoice] = usdInvoice([2_500]);
    $location = (string) $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => StripeDriver::KEY])->headers->get('Location');
    Http::withOptions(['verify' => $this->scenario->ca()->bundlePath(), 'allow_redirects' => false])->post((strstr($location, '#', true) ?: $location).'/pay');
    $intent = (string) $this->scenario->state()->get('sessions', PaymentAttempt::query()->sole()->provider_ref)['payment_intent'];
    $this->scenario->simulator()->actions()['dispute'](['payment_intent' => $intent], $this->scenario);

    $results = [];
    $app = function (string $url, string $body, array $headers) use (&$results): int {
        $response = test()->call('POST', $url, server: ['HTTP_STRIPE_SIGNATURE' => $headers['Stripe-Signature'], 'CONTENT_TYPE' => $headers['Content-Type'] ?? 'application/json'], content: $body);
        $results[] = [json_decode($body, true)['type'], $response->getStatusCode(), (string) $response->json('result')];

        return $response->getStatusCode();
    };
    $outbox = $this->scenario->outbox();
    $dispute = array_values(array_filter($outbox->pending(), fn (array $delivery): bool => json_decode($delivery['body'], true)['type'] === 'charge.dispute.created'))[0];

    // Stripe processed the dispute first: the app answers 409 (no payment yet), then the retry books it.
    expect($outbox->deliver($dispute, $app))->toBe(409);

    $started = microtime(true);
    $attempts = $outbox->drain($app, maxSeconds: 10);

    expect(array_column($attempts, 1))->each->toBe(200)
        ->and(end($results))->toBe(['charge.dispute.created', 200, 'chargeback recorded'])
        ->and($outbox->exhausted())->toBe([])
        ->and($outbox->pending())->toBe([])
        ->and(microtime(true) - $started)->toBeLessThan(3.0)
        ->and(Transaction::query()->where('type', TransactionType::Chargeback)->count())->toBe(1)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Collections);
});
