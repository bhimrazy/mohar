<?php

/*
| Review probes for PR #266 (#123: Continue payment / Use another method). P1 and P2 fail on 069eabb2 and pass once
| "Use another method" is limited to drivers that implement CancelsHostedPayment (CTO decision), in the prop and in
| CloseHostedPayment. P3 (redirect targets) and P4 (two real processes on PostgreSQL) pass on 069eabb2 and stay as
| regression tests.
*/

use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\PaymentsNepal\Drivers\EsewaDriver;
use App\Modules\PaymentsNepal\Drivers\KhaltiDriver;
use App\Modules\PaymentsStripe\Drivers\StripeDriver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../PaymentsNepal/helpers.php';

const P266_ESEWA_STATUS = 'https://rc.esewa.com.np/api/epay/transaction/status/*';

beforeEach(function () {
    Http::preventStrayRequests();
});

test('P1: a pending eSewa payment does not offer "Use another method" (no CancelsHostedPayment)', function () {
    nepalGateway(EsewaDriver::KEY, ['sandbox' => '1', 'product_code' => 'EPAYTEST', 'secret_key' => 'fake-esewa-secret']);
    [, $invoice] = nprInvoice([10_000]);
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => EsewaDriver::KEY]);

    $this->get(route('client.invoices.show', $invoice->id))->assertInertia(fn (Assert $page) => $page
        ->where('payment_in_progress.status', 'pending')
        ->where('payment_in_progress.can_close', false));
});

test('P1b: a pending Khalti payment does not offer "Use another method" either', function () {
    nepalGateway(KhaltiDriver::KEY, ['sandbox' => '1', 'secret_key' => 'test_secret_key_f59e8b7d18b4499ca40f68195a846e9b']);
    [, $invoice] = nprInvoice([150_000]);
    Http::fake(['https://dev.khalti.com/api/v2/epayment/initiate/' => Http::response(['pidx' => 'PIDX1', 'payment_url' => 'https://test-pay.khalti.com/?pidx=PIDX1', 'expires_at' => now()->addHour()->toIso8601String(), 'expires_in' => 3600])]);
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => KhaltiDriver::KEY]);

    $this->get(route('client.invoices.show', $invoice->id))->assertInertia(fn (Assert $page) => $page
        ->where('payment_in_progress.status', 'pending')
        ->where('payment_in_progress.can_close', false));
});

test('P2: a direct POST to close a pending eSewa payment one minute in does not free the invoice', function () {
    nepalGateway(EsewaDriver::KEY, ['sandbox' => '1', 'product_code' => 'EPAYTEST', 'secret_key' => 'fake-esewa-secret']);
    [, $invoice] = nprInvoice([10_000]);
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => EsewaDriver::KEY]);
    $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
    // The customer is still on eSewa's login page in the first tab: eSewa has not seen the transaction yet.
    Http::fake([P266_ESEWA_STATUS => Http::response(['product_code' => 'EPAYTEST', 'transaction_uuid' => $attempt->reference, 'total_amount' => 100.0, 'status' => 'NOT_FOUND', 'ref_id' => null])]);
    $this->travel(1)->minutes();

    $this->post(route('client.invoices.attempts.close', [$invoice->id, $attempt->reference]));

    // On 069eabb2 the attempt is Failed at once and a second payment can start while the eSewa form can still be paid.
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Pending);
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => EsewaDriver::KEY])->assertSessionHasErrors('billing');
});

test('P3: Continue payment redirects only to the exact Stripe checkout host', function (string $url) {
    stripeGateway();
    [, $invoice, $attempt] = startStripe();
    Http::fake(fn (Request $request) => $request->method() === 'GET' && str_contains($request->url(), 'checkout/sessions/'.STRIPE_SESSION)
        ? Http::response(stripeCreatedSession($attempt->reference, $invoice->id, ['url' => $url]))
        : null);

    $location = (string) $this->from(route('client.invoices.show', $invoice->id))
        ->get(route('client.invoices.attempts.resume', [$invoice->id, $attempt->reference]))->headers->get('Location');

    expect($location)->toBe(route('client.invoices.show', $invoice->id));
})->with([
    'trailing dot' => ['https://checkout.stripe.com./c/pay/x'],
    'explicit 443' => ['https://checkout.stripe.com:443/c/pay/x'],
    'other port' => ['https://checkout.stripe.com:8443/c/pay/x'],
    'suffix host' => ['https://checkout.stripe.com.evil.example/c/pay/x'],
    'empty userinfo' => ['https://@evil.example/c/pay/x'],
    'colon userinfo' => ['https://checkout.stripe.com:@evil.example/c/pay/x'],
    'encoded at' => ['https://checkout.stripe.com%40evil.example/c/pay/x'],
    'fragment at' => ['https://evil.example#@checkout.stripe.com/'],
    'query at' => ['https://evil.example?@checkout.stripe.com/'],
    'protocol relative' => ['//checkout.stripe.com/c/pay/x'],
    'protocol relative evil' => ['//evil.example/c/pay/x'],
    'scheme without slashes' => ['https:checkout.stripe.com/c/pay/x'],
    'triple slash' => ['https:///evil.example/c/pay/x'],
    'backslash' => ['https://checkout.stripe.com\\@evil.example/'],
    'slash backslash' => ['https:/\\evil.example/'],
    'tab' => ["https://checkout.stripe.com\t.evil.example/"],
    'IDN cyrillic o' => ['https://checkout.stripe.cоm/c/pay/x'],
    'punycode' => ['https://xn--checkout-stripe-com.example/c/pay/x'],
    'javascript' => ['javascript:alert(document.domain)//https://checkout.stripe.com/'],
    'data' => ['data:text/html,<script>alert(1)</script>'],
    'http' => ['http://checkout.stripe.com/c/pay/x'],
    'ftp' => ['ftp://checkout.stripe.com/c/pay/x'],
    'uppercase scheme' => ['HTTPS://evil.example/'],
]);

test('P3b: an uppercase checkout host is the same host and is accepted', function () {
    stripeGateway();
    [, $invoice, $attempt] = startStripe();
    Http::fake(fn (Request $request) => $request->method() === 'GET' && str_contains($request->url(), 'checkout/sessions/'.STRIPE_SESSION)
        ? Http::response(stripeCreatedSession($attempt->reference, $invoice->id, ['url' => 'https://CHECKOUT.STRIPE.COM/c/pay/x']))
        : null);

    $this->get(route('client.invoices.attempts.resume', [$invoice->id, $attempt->reference]))->assertRedirect('https://CHECKOUT.STRIPE.COM/c/pay/x');
});

/**
 * Start the workers, each after its delay (ms), and collect their JSON lines.
 *
 * @param  array<string, array{0: string, 1: int, 2: int}>  $workers  label => [mode, start delay ms, expire pause ms]
 * @return array<string, array<string, mixed>>
 */
function p266Race(array $workers, string $database, int $attemptId, string $store, string $stateFile): array
{
    $script = __DIR__.'/../Billing/Support/pr266-close-worker.php';
    $running = [];
    $begin = microtime(true);

    foreach ($workers as $label => [$mode, $delay, $pause]) {
        $wait = $begin + $delay / 1000 - microtime(true);
        $wait > 0 && usleep((int) ($wait * 1_000_000));
        $process = proc_open([PHP_BINARY, $script, $label, $mode, $database, (string) $attemptId, $store, $stateFile, (string) $pause], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $running[$label] = [$process, $pipes];
    }

    $results = [];

    foreach ($running as $label => [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($process);
        $results[$label] = json_decode((string) $out, true) ?? ['error' => 'no output: '.$out.$err];
    }

    return $results;
}

describe('P4: two real processes on one Stripe attempt (PostgreSQL)', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
        }
    });

    /** @return array{0: string, 1: PaymentAttempt, 2: string} */
    function p266Seed(string $name, string $state): array
    {
        test()->useSeededDatabase($name, function () use ($name): void {
            stripeGateway();
            $client = billingClient(['email' => $name.'@example.test', 'currency' => 'USD']);
            $invoice = issueInvoice($client, [2_500]);
            PaymentAttempt::query()->create([
                'reference' => 'inv-'.$invoice->id.'-race266', 'gateway' => StripeDriver::KEY, 'provider_ref' => STRIPE_SESSION,
                'client_id' => $client->id, 'invoice_id' => $invoice->id, 'amount' => 2_500, 'currency' => 'USD',
                'document_amount' => 2_500, 'document_currency' => 'USD', 'status' => AttemptStatus::Pending,
                'expires_at' => now()->addMinutes(30),
            ]);
        });
        $stateFile = tempnam(sys_get_temp_dir(), 'p266');
        file_put_contents($stateFile, $state);
        @unlink($stateFile.'.expires');

        return [DB::connection()->getDatabaseName(), PaymentAttempt::query()->where('reference', 'like', '%-race266')->sole(), $stateFile];
    }

    function p266Expires(string $stateFile): int
    {
        return is_file($stateFile.'.expires') ? count(file($stateFile.'.expires')) : 0;
    }

    test('two "Use another method" clicks with the shared (database) lock store: one expire, one close', function () {
        [$database, $attempt, $state] = p266Seed('p266_close_close', 'open');

        $results = p266Race(['a' => ['close', 0, 1_500], 'b' => ['close', 300, 0]], $database, $attempt->id, 'database', $state);

        expect(array_column($results, 'error'))->toBe([])
            ->and(array_column($results, 'status'))->toBe(['expired', 'expired'])
            ->and(p266Expires($state))->toBe(1)
            ->and($results['b']['ended'])->toBeGreaterThan($results['a']['ended'] - 0.05)
            ->and($attempt->fresh()->status)->toBe(AttemptStatus::Expired)
            ->and(Transaction::query()->count())->toBe(0);
    });

    test('with an array lock store (no shared lock, #260) both clicks expire, and settlement is still single', function () {
        [$database, $attempt, $state] = p266Seed('p266_close_array', 'open');

        $results = p266Race(['a' => ['close', 0, 1_500], 'b' => ['close', 300, 0]], $database, $attempt->id, 'array', $state);

        fwrite(STDERR, "\n[P4 array store] expire calls: ".p266Expires($state).' '.json_encode(array_column($results, 'status', 'label'))."\n");
        expect(array_column($results, 'error'))->toBe([])
            ->and($attempt->fresh()->status)->toBe(AttemptStatus::Expired)
            ->and(Transaction::query()->count())->toBe(0);
    });

    test('"Use another method" racing the webhook settlement of a paid session books the payment once', function () {
        [$database, $attempt, $state] = p266Seed('p266_close_webhook', 'paid');

        $results = p266Race(['close' => ['close', 0, 800], 'webhook' => ['settle', 200, 0]], $database, $attempt->id, 'database', $state);

        expect(array_column($results, 'error'))->toBe([])
            ->and(array_column($results, 'status'))->toBe(['succeeded', 'succeeded'])
            ->and($attempt->fresh()->status)->toBe(AttemptStatus::Succeeded)
            ->and(Transaction::query()->where('gateway', StripeDriver::KEY)->count())->toBe(1)
            ->and(Invoice::query()->whereKey($attempt->invoice_id)->sole()->status)->toBe(InvoiceStatus::Paid);
    });

    test('a Stripe success after the close is booked once as a late payment', function () {
        [$database, $attempt, $state] = p266Seed('p266_late', 'open');

        $first = p266Race(['close' => ['close', 0, 0]], $database, $attempt->id, 'database', $state);
        expect($first['close']['status'] ?? $first)->toBe('expired');

        // Not possible at real Stripe for an expired session; this is the #130 backstop if the provider says otherwise.
        file_put_contents($state, 'paid');
        $results = p266Race(['webhook-1' => ['settle', 0, 0], 'webhook-2' => ['settle', 50, 0]], $database, $attempt->id, 'database', $state);

        expect(array_column($results, 'error'))->toBe([])
            ->and(Transaction::query()->where('gateway', StripeDriver::KEY)->count())->toBe(1)
            ->and($attempt->fresh())->status->toBe(AttemptStatus::Succeeded)->late_payment_at->not->toBeNull();
    });
});
