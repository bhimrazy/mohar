<?php

use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\PaymentsRazorpay\Drivers\RazorpayDriver;
use App\Support\Integrations\Endpoints;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Kernel\Scenario;
use Mohar\Simulators\Providers\Razorpay\RazorpaySimulator;
use Tests\Simulators\SimulatorHarness;
use Tests\TestCase;

require_once dirname(__DIR__).'/Feature/Billing/helpers.php';

uses(TestCase::class, SimulatorHarness::class);

/**
 * The Razorpay driver against the razorpay simulator as a real TLS process (ADR 0016 §4, spec §13 criterion 6): a
 * customer pays a payment link on the simulator's page, the browser returns, webhooks arrive with their exact bytes,
 * and staff and the dashboard refund. Run with `php artisan simulate:e2e --filter=Razorpay` (Linux).
 */
function razorpaySimulator(?Scenario $scenario = null): Scenario
{
    static $current;

    return $current = $scenario ?? $current;
}

/**
 * Post every due webhook to the app as Razorpay would, with a variant (`wrong_secret`, `unsigned`) when given.
 *
 * @return list<string> the app's result for each delivery
 */
function deliverRazorpayWebhooks(?string $variant = null): array
{
    $outbox = razorpaySimulator()->outbox();
    $results = [];
    $transport = function (string $url, string $body, array $headers) use (&$results): int {
        $server = [];

        foreach ($headers as $name => $value) {
            $server[$name === 'Content-Type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $response = test()->call('POST', $url, server: $server, content: $body);
        $results[] = $response->getStatusCode() >= 400 ? 'refused '.$response->getStatusCode() : (string) $response->json('result');

        return $response->getStatusCode();
    };

    if ($variant !== null) {
        foreach ($outbox->pending() as $delivery) {
            $outbox->deliver($delivery, $transport, $variant);
        }

        return $results;
    }

    $outbox->drain($transport);

    return $results;
}

beforeEach(function () {
    $this->refuseAnyDatabaseButAThrowawayOne();
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $scenario = razorpaySimulator(Processes::start(RazorpaySimulator::class));
    config([
        'integrations.outbound.allow_loopback' => true,
        'integrations.endpoints.overrides' => $scenario->simulator()->appConfiguration($scenario)['env']['INTEGRATION_ENDPOINTS'],
        'integrations.endpoints.ca_bundle' => $scenario->ca()->bundlePath(),
    ]);
    app()->forgetInstance(Endpoints::class);
    $scenario->simulator()->actions()['webhook_url'](['url' => route('billing.webhooks', RazorpayDriver::KEY)], $scenario);
    $scenario->outbox()->retryPolicy([0], 3);

    Gateway::query()->create(['key' => RazorpayDriver::KEY, 'display_name' => 'Razorpay', 'is_active' => true, 'show_on_order_form' => true, 'sort_order' => 5,
        'config' => ['sandbox' => '1', 'key_id' => RazorpaySimulator::KEY_ID, 'key_secret' => RazorpaySimulator::KEY_SECRET, 'webhook_secret' => RazorpaySimulator::WEBHOOK_SECRET]]);
});

afterEach(function () {
    Processes::stop(razorpaySimulator());
});

test('a customer pays a payment link on the simulator, webhooks and refunds book exactly once', function () {
    Currency::factory()->inr()->create();
    [, $client] = actingAsClient(Client::factory()->create(['currency' => 'INR', 'country' => 'IN']));
    $invoice = issueInvoice($client, [113_000]);
    $scenario = razorpaySimulator();

    // Pay: the app sends the browser to the link page; Pay there redirects back with the signed query.
    $page = (string) $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => RazorpayDriver::KEY])->headers->get('Location');
    $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
    expect($page)->toBe($scenario->apiUrl().'/l/'.$attempt->provider_ref);

    $return = (string) $this->browser($scenario)->post($page.'/pay')->header('Location');
    expect($return)->toStartWith(route('billing.gateways.return', RazorpayDriver::KEY).'?');
    $this->get($return)->assertRedirect(route('client.invoices.show', $invoice->id));

    // 2% fee plus 18% GST on it, as the simulator charges.
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($attempt->fresh()->status)->toBe(AttemptStatus::Succeeded)
        ->and(Transaction::query()->where('gateway', RazorpayDriver::KEY)->sole())->amount_in->toBe(113_000)->fee->toBe(2_667);

    // Forged deliveries are refused; the genuine ones book nothing more (payment_link.paid is a duplicate lookup).
    expect(deliverRazorpayWebhooks('wrong_secret'))->each->toBe('refused 403');
    expect(deliverRazorpayWebhooks())->toContain('duplicate')->not->toContain('payment recorded');

    // Staff refund part; the dashboard refunds the rest. Each refund is booked once.
    $payment = Transaction::query()->where('gateway', RazorpayDriver::KEY)->where('type', TransactionType::Payment)->sole();
    app(RefundTransaction::class)->handle($payment, 13_000, RefundMode::Gateway);
    expect(deliverRazorpayWebhooks())->toContain('duplicate')->not->toContain('refund recorded');

    $scenario->simulator()->actions()['refund'](['payment_id' => $payment->gateway_txn_id], $scenario);
    expect(deliverRazorpayWebhooks())->toContain('refund recorded');

    expect(Transaction::query()->where('type', TransactionType::Refund)->pluck('amount_out')->sort()->values()->all())->toBe([13_000, 100_000])
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Refunded);
});
