<?php

namespace Tests\Simulators;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\PaymentsNepal\Drivers\EsewaDriver;
use App\Modules\PaymentsNepal\Drivers\KhaltiDriver;
use App\Modules\PaymentsStripe\Drivers\StripeDriver;
use App\Support\Integrations\Endpoints;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Kernel\Scenario;
use Mohar\Simulators\Providers\Esewa\EsewaSimulator;
use Mohar\Simulators\Providers\Khalti\KhaltiSimulator;
use Mohar\Simulators\Providers\Stripe\StripeSimulator;
use Mohar\Simulators\Providers\Whm\WhmSimulator;
use RuntimeException;

/**
 * The end-to-end harness (ADR 0016 §4): the app runs in process, the `whm`, `stripe`, `khalti` and `esewa` simulators
 * run as real TLS processes, callbacks are pulled from the outbox and posted to the app with their exact bytes and headers, and the
 * customer's browser is an HTTP client that trusts the scenario CA and shows each redirect.
 *
 * It refuses to run against anything but a throwaway database (`billing_e2e_*` from `simulate:e2e`, or SQLite in
 * memory): never the preview database or the development one.
 */
trait SimulatorHarness
{
    public Scenario $whm;

    public Scenario $stripe;

    public Scenario $khalti;

    public Scenario $esewa;

    /** @var list<array{id: int, url: string, body: string, headers: array<string, string>}> */
    public array $lastDeliveries = [];

    public function startSimulators(): void
    {
        $this->refuseAnyDatabaseButAThrowawayOne();

        $this->whm = Processes::start(WhmSimulator::class);
        $this->stripe = Processes::start(StripeSimulator::class);
        $this->khalti = Processes::start(KhaltiSimulator::class);
        $this->esewa = Processes::start(EsewaSimulator::class);
        $payments = [$this->stripe, $this->khalti, $this->esewa];
        // One CA bundle that trusts each payment scenario's CA (WHM is trusted through its pin instead).
        $bundle = $this->stripe->directory.'/payments-ca.pem';
        file_put_contents($bundle, implode('', array_map(fn (Scenario $scenario): string => (string) file_get_contents($scenario->ca()->bundlePath()), $payments)));
        $endpoints = array_merge(...array_map(fn (Scenario $scenario): array => json_decode($scenario->simulator()->appConfiguration($scenario)['env']['INTEGRATION_ENDPOINTS'], true), $payments));
        config([
            'integrations.outbound.allow_loopback' => true,
            'integrations.endpoints.overrides' => json_encode($endpoints, JSON_UNESCAPED_SLASHES),
            'integrations.endpoints.ca_bundle' => $bundle,
        ]);
        app()->forgetInstance(Endpoints::class);
        $this->stripe->simulator()->actions()['webhook_url'](['url' => route('billing.webhooks', StripeDriver::KEY)], $this->stripe);
        // Stripe's retry schedule without the waiting: a non-2xx answer is retried at once, up to six attempts.
        $this->stripe->outbox()->retryPolicy([0], 6);
    }

    /**
     * Judged on the connection Laravel really opens (a DB_URL wins over DB_DATABASE), and on PostgreSQL on what the
     * server says it is connected to: never the development, preview or shared test database.
     */
    private function refuseAnyDatabaseButAThrowawayOne(): void
    {
        // Resolved afresh from the configuration in force (runs before anything is migrated).
        DB::purge();
        $connection = DB::connection();
        $name = (string) $connection->getDatabaseName();
        $throwaway = fn (string $database): bool => preg_match('/^billing_e2e_[0-9a-f]{8}(\.sqlite)?$/', basename($database)) === 1;
        $allowed = match ($connection->getDriverName()) {
            'sqlite' => $name === ':memory:' || $throwaway($name),
            'pgsql' => $throwaway($name) && $throwaway((string) $connection->selectOne('select current_database() as name')?->name),
            default => false,
        };

        if (! $allowed) {
            throw new RuntimeException("The end-to-end scenario runs only on a throwaway database (billing_e2e_* or SQLite in memory), not {$connection->getDriverName()}:{$name}.");
        }
    }

    public function stopSimulators(): void
    {
        foreach (['whm', 'stripe', 'khalti', 'esewa'] as $name) {
            if (isset($this->{$name})) {
                Processes::stop($this->{$name});
            }
        }
    }

    /**
     * The customer pays the invoice with Khalti or eSewa on the simulator's page, and the browser follows the return into
     * the app. Returns that return URL, so a test can follow it again.
     */
    public function payWithWallet(Invoice $invoice, string $gateway): string
    {
        $scenario = $gateway === KhaltiDriver::KEY ? $this->khalti : $this->esewa;
        $redirect = (string) $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => $gateway])->headers->get('Location');

        if ($gateway === EsewaDriver::KEY) {
            // The app's checkout page auto-posts the signed form to eSewa, which books the session and opens its page.
            $form = $this->get($redirect)->inertiaProps('form');
            $redirect = (string) $this->browser($scenario)->asForm()->post($form['action'], (array) $form['fields'])->header('Location');
        }

        if (! str_starts_with($redirect, $scenario->apiUrl().'/')) {
            $why = json_encode([app('session.store')->get('errors'), app('session.store')->get('inertia.flash_data.toast')]);

            throw new RuntimeException("The app did not send the customer to the {$gateway} simulator: {$redirect} {$why}");
        }

        $choice = $gateway === KhaltiDriver::KEY ? $scenario->apiUrl().'/pay/'.PaymentAttempt::query()->where('invoice_id', $invoice->id)->latest('id')->firstOrFail()->provider_ref.'/pay' : $redirect.'/pay';
        $return = (string) $this->browser($scenario)->post($choice)->header('Location');
        $this->get($return)->assertRedirect();

        return $return;
    }

    /** The customer's browser on a simulator: trusts its CA and shows us each redirect instead of following it. */
    public function browser(Scenario $scenario): PendingRequest
    {
        return Http::withOptions(['verify' => $scenario->ca()->bundlePath(), 'allow_redirects' => false]);
    }

    /**
     * Deliver the Stripe webhooks as Stripe does: drain the outbox, retrying any non-2xx answer, until nothing
     * retryable is left. With a variant (`stale`, `wrong_secret`, `unsigned`) each due delivery is sent once, forged;
     * with `$replay` the last batch is sent again. Returns the app's `result` for each attempt.
     *
     * @return list<string>
     */
    public function deliverStripeWebhooks(?string $variant = null, bool $replay = false): array
    {
        $outbox = $this->stripe->outbox();
        $results = [];
        $transport = function (string $url, string $body, array $headers) use (&$results): int {
            $server = ['CONTENT_TYPE' => $headers['Content-Type']];

            if (isset($headers['Stripe-Signature'])) {
                $server['HTTP_STRIPE_SIGNATURE'] = $headers['Stripe-Signature'];
            }

            $response = $this->call('POST', $url, server: $server, content: $body);
            $results[] = $response->getStatusCode() >= 400 ? 'refused '.$response->getStatusCode() : (string) $response->json('result');

            return $response->getStatusCode();
        };

        if ($replay || $variant !== null) {
            $deliveries = $replay ? $this->lastDeliveries : $outbox->pending();
            $this->lastDeliveries = $deliveries;

            foreach ($deliveries as $delivery) {
                $outbox->deliver($delivery, $transport, $variant);
            }

            return $results;
        }

        $this->lastDeliveries = $outbox->pending();
        $outbox->drain($transport);

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
