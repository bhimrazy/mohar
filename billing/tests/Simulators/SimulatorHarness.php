<?php

namespace Tests\Simulators;

use App\Modules\PaymentsStripe\Drivers\StripeDriver;
use App\Support\Integrations\Endpoints;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Kernel\Scenario;
use Mohar\Simulators\Providers\Stripe\StripeSimulator;
use Mohar\Simulators\Providers\Whm\WhmSimulator;
use RuntimeException;

/**
 * The end-to-end harness (ADR 0016 §4): the app runs in process, the `whm` and `stripe` simulators run as real TLS
 * processes, callbacks are pulled from the outbox and posted to the app with their exact bytes and headers, and the
 * customer's browser is an HTTP client that trusts the scenario CA and shows each redirect.
 *
 * It refuses to run against anything but a throwaway database (`billing_e2e_*` from `simulate:e2e`, or SQLite in
 * memory): never the preview database or the development one.
 */
trait SimulatorHarness
{
    public Scenario $whm;

    public Scenario $stripe;

    /** @var list<array{id: int, url: string, body: string, headers: array<string, string>}> */
    public array $lastDeliveries = [];

    public function startSimulators(): void
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if ($database !== ':memory:' && preg_match('/^billing_e2e_[a-z0-9]+(\.sqlite)?$/', basename($database)) !== 1) {
            throw new RuntimeException("The end-to-end scenario runs only on a throwaway database (billing_e2e_* or SQLite in memory), not {$database}.");
        }

        $this->whm = Processes::start(WhmSimulator::class);
        $this->stripe = Processes::start(StripeSimulator::class);
        config([
            'integrations.outbound.allow_loopback' => true,
            'integrations.endpoints.overrides' => $this->stripe->simulator()->appConfiguration($this->stripe)['env']['INTEGRATION_ENDPOINTS'],
            'integrations.endpoints.ca_bundle' => $this->stripe->ca()->bundlePath(),
        ]);
        app()->forgetInstance(Endpoints::class);
        $this->stripe->simulator()->actions()['webhook_url'](['url' => route('billing.webhooks', StripeDriver::KEY)], $this->stripe);
    }

    public function stopSimulators(): void
    {
        foreach (['whm', 'stripe'] as $name) {
            if (isset($this->{$name})) {
                Processes::stop($this->{$name});
            }
        }
    }

    /** The customer's browser on a simulator: trusts its CA and shows us each redirect instead of following it. */
    public function browser(Scenario $scenario): PendingRequest
    {
        return Http::withOptions(['verify' => $scenario->ca()->bundlePath(), 'allow_redirects' => false]);
    }

    /**
     * Follow an absolute app URL (a redirect back from a simulator) inside the app.
     */
    public function followIntoApp(string $url): TestResponse
    {
        return $this->get($url);
    }

    /**
     * Deliver the Stripe webhooks due now, as the simulator signed them (`stale` or `wrong_secret` to forge them), or
     * the last batch again (`$replay`). Returns the app's `result` for each.
     *
     * @return list<string>
     */
    public function deliverStripeWebhooks(?string $variant = null, bool $replay = false): array
    {
        $outbox = $this->stripe->outbox();
        $deliveries = $replay ? $this->lastDeliveries : $outbox->pending();
        $this->lastDeliveries = $deliveries;
        $results = [];

        foreach ($deliveries as $delivery) {
            $outbox->deliver($delivery, function (string $url, string $body, array $headers) use (&$results): int {
                $response = $this->call('POST', $url, server: ['HTTP_STRIPE_SIGNATURE' => $headers['Stripe-Signature'], 'CONTENT_TYPE' => $headers['Content-Type']], content: $body);
                $results[] = $response->getStatusCode() >= 400 ? 'refused '.$response->getStatusCode() : (string) $response->json('result');

                return $response->getStatusCode();
            }, $variant);
        }

        return $results;
    }

    /**
     * The checkout page the app sent the customer to, without its fragment.
     */
    public function checkoutUrl(TestResponse $redirect): string
    {
        $location = (string) $redirect->headers->get('Location');

        if (! str_starts_with($location, $this->stripe->apiUrl().'/c/pay/')) {
            throw new RuntimeException("The app did not send the customer to the simulator's checkout: {$location}");
        }

        return strstr($location, '#', true) ?: $location;
    }

    /**
     * The customer pays on the hosted page; returns where Stripe sends the browser back to.
     */
    public function payOnHostedPage(string $checkout): string
    {
        $paid = $this->browser($this->stripe)->post($checkout.'/pay');

        if ($paid->status() !== 303) {
            throw new RuntimeException("The hosted page did not take the payment: HTTP {$paid->status()}");
        }

        return (string) $paid->header('Location');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function whmAccount(string $username): ?array
    {
        $account = $this->whm->state()->get('accounts', $username);

        return is_array($account) ? $account : null;
    }
}
