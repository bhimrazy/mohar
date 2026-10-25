<?php

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Providers\Razorpay\RazorpayApi;
use Mohar\Simulators\Providers\Razorpay\RazorpaySimulator;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The razorpay simulator over its TLS port, called the way a driver would: JSON with Basic auth, amounts in paise (INR
 * 1,130.55 is 113055). There is no Razorpay driver yet, so these tests are the contract it will be built against.
 */
beforeEach(function () {
    $this->scenario = Processes::start(RazorpaySimulator::class);
});

afterEach(function () {
    Processes::stop($this->scenario);
});

/**
 * @param  array<string, mixed>  $body
 */
function rzp(string $method, string $path, array $body = []): Response
{
    $request = Http::withOptions(['verify' => test()->scenario->ca()->bundlePath(), 'allow_redirects' => false])
        ->withBasicAuth(RazorpaySimulator::KEY_ID, RazorpaySimulator::KEY_SECRET);

    return $method === 'GET' ? $request->get(test()->scenario->apiUrl().'/v1'.$path, $body) : $request->asJson()->post(test()->scenario->apiUrl().'/v1'.$path, $body);
}

function rzpBrowser(): PendingRequest
{
    return Http::withOptions(['verify' => test()->scenario->ca()->bundlePath(), 'allow_redirects' => false]);
}

/**
 * @param  array<string, mixed>  $input
 * @return array<string, mixed>
 */
function rzpAct(string $action, array $input): array
{
    return test()->scenario->simulator()->actions()[$action]($input, test()->scenario);
}

/** @return array<string, mixed> */
function rzpOrder(int $amount = 113_055): array
{
    return rzp('POST', '/orders', ['amount' => $amount, 'currency' => 'INR', 'receipt' => 'INV-1001', 'notes' => ['invoice' => '1001']])->json();
}

/** An order paid and captured; returns [order id, payment]. */
function rzpPaid(int $amount = 113_055): array
{
    $order = rzpOrder($amount);
    $paid = rzpAct('pay', ['order_id' => $order['id']]);

    return [$order['id'], rzp('GET', '/payments/'.$paid['payment_id'])->json()];
}

/**
 * Deliver every pending webhook; returns [event, signature header, event id header, body] per delivery.
 *
 * @return list<array{0: string, 1: string, 2: string, 3: string}>
 */
function rzpDeliver(?string $variant = null): array
{
    $outbox = test()->scenario->outbox();
    $seen = [];

    foreach ($outbox->pending() as $delivery) {
        $outbox->deliver($delivery, function (string $url, string $body, array $headers) use (&$seen): int {
            $seen[] = [json_decode($body, true)['event'], $headers['X-Razorpay-Signature'] ?? '', $headers['X-Razorpay-Event-Id'], $body];

            return 200;
        }, $variant);
    }

    return $seen;
}

test('an order is paid, captured and fetched in paise, and the checkout signature verifies', function () {
    $order = rzpOrder();
    expect($order)->toMatchArray(['entity' => 'order', 'amount' => 113_055, 'amount_due' => 113_055, 'currency' => 'INR', 'status' => 'created', 'receipt' => 'INV-1001'])
        ->and($order['id'])->toStartWith('order_');

    $paid = rzpAct('pay', ['order_id' => $order['id'], 'callback_url' => 'https://shop.example.test/razorpay/return']);
    $payment = rzp('GET', '/payments/'.$paid['payment_id'])->json();
    $fields = $paid['callback']['fields'];

    expect($payment)->toMatchArray(['status' => 'captured', 'captured' => true, 'amount' => 113_055, 'order_id' => $order['id'], 'method' => 'upi', 'fee' => 2_261, 'tax' => 407, 'amount_refunded' => 0, 'refund_status' => null])
        ->and($fields['razorpay_signature'])->toBe(hash_hmac('sha256', $order['id'].'|'.$payment['id'], RazorpaySimulator::KEY_SECRET))
        ->and($fields['razorpay_order_id'])->toBe($order['id'])
        ->and(rzp('GET', '/orders/'.$order['id'])->json())->toMatchArray(['status' => 'paid', 'amount_paid' => 113_055, 'amount_due' => 0, 'attempts' => 1])
        ->and(rzp('GET', '/orders/'.$order['id'].'/payments')->json())->toMatchArray(['entity' => 'collection', 'count' => 1]);
});

test('requests are refused with Razorpay\'s envelope: wrong key, bad amount, bad currency, unknown id', function () {
    $wrong = Http::withOptions(['verify' => $this->scenario->ca()->bundlePath()])->withBasicAuth(RazorpaySimulator::KEY_ID, 'nope')->post($this->scenario->apiUrl().'/v1/orders', ['amount' => 500, 'currency' => 'INR']);
    $small = rzp('POST', '/orders', ['amount' => 99, 'currency' => 'INR']);
    $decimal = rzp('POST', '/orders', ['amount' => 11.5, 'currency' => 'INR']);
    $usd = rzp('POST', '/orders', ['amount' => 500, 'currency' => 'USD']);
    $missing = rzp('GET', '/orders/order_doesnotexist01');

    expect($wrong->status())->toBe(401)->and($wrong->json('error.code'))->toBe('BAD_REQUEST_ERROR')
        ->and($small->status())->toBe(400)->and($small->json('error.field'))->toBe('amount')
        ->and($decimal->json('error.field'))->toBe('amount')
        ->and($usd->json('error.field'))->toBe('currency')
        ->and($missing->status())->toBe(400)->and($missing->json('error.description'))->toContain('does not exist')
        ->and((string) json_encode($this->scenario->log()->entries()))->not->toContain(RazorpaySimulator::KEY_SECRET);
});

test('the hosted checkout posts a signed result to the callback URL, fails on request and refuses a second payment', function () {
    $order = rzpOrder(50_000);
    $query = http_build_query(['order_id' => $order['id'], 'callback_url' => 'https://shop.example.test/razorpay/return', 'cancel_url' => 'https://shop.example.test/cancelled']);
    $base = $this->scenario->apiUrl();

    expect(rzpBrowser()->get("{$base}/checkout?{$query}")->body())->toContain('Rs 500.00');

    $form = ['callback_url' => 'https://shop.example.test/razorpay/return', 'cancel_url' => 'https://shop.example.test/cancelled'];
    $failed = rzpBrowser()->asForm()->post("{$base}/checkout/{$order['id']}/fail", $form)->body();
    $cancel = rzpBrowser()->asForm()->post("{$base}/checkout/{$order['id']}/cancel", $form);
    $pay = rzpBrowser()->asForm()->post("{$base}/checkout/{$order['id']}/pay", $form)->body();
    $again = rzpBrowser()->asForm()->post("{$base}/checkout/{$order['id']}/pay", $form);

    $payment = rzp('GET', '/orders/'.$order['id'].'/payments')->json('items');
    expect($failed)->toContain('name="error[code]"')->not->toContain('razorpay_signature')
        ->and($cancel->status())->toBe(303)->and($cancel->header('Location'))->toBe('https://shop.example.test/cancelled')
        ->and($pay)->toContain('action="https://shop.example.test/razorpay/return"')->toContain(RazorpayApi::checkoutSignature($order['id'], $payment[1]['id']))
        ->and($payment)->toHaveCount(2)->and($payment[0]['status'])->toBe('failed')->and($payment[0]['error_code'])->toBe('BAD_REQUEST_ERROR')
        ->and($again->status())->toBe(410);
});

test('a failed attempt leaves the order attempted and a retry can still pay it', function () {
    $order = rzpOrder();
    $failed = rzpAct('pay', ['order_id' => $order['id'], 'vpa' => 'failure@razorpay']);

    expect($failed['status'])->toBe('failed')
        ->and(rzp('GET', '/orders/'.$order['id'])->json())->toMatchArray(['status' => 'attempted', 'amount_paid' => 0, 'attempts' => 1]);

    $declined = rzpAct('pay', ['order_id' => $order['id'], 'method' => 'card', 'card' => '4100280000080001']);
    expect(rzp('GET', '/payments/'.$declined['payment_id'])->json())->toMatchArray(['status' => 'failed', 'error_reason' => 'insufficient_funds', 'card_id' => rzp('GET', '/payments/'.$declined['payment_id'])->json('card_id')])
        ->and((string) json_encode($this->scenario->state()->all('payments')))->not->toContain('4100280000080001');

    $retry = rzpAct('pay', ['order_id' => $order['id'], 'method' => 'card', 'card' => '4100280000001007']);
    expect($retry['status'])->toBe('captured')
        ->and(rzp('GET', '/orders/'.$order['id'])->json())->toMatchArray(['status' => 'paid', 'attempts' => 3])
        ->and(fn () => rzpAct('pay', ['order_id' => $order['id']]))->toThrow(InvalidArgumentException::class);
});

test('with manual capture a payment stays authorized until captured for exactly its amount, once', function () {
    rzpAct('capture_mode', ['mode' => 'manual']);
    $order = rzpOrder(20_000);
    $paymentId = rzpAct('pay', ['order_id' => $order['id']])['payment_id'];

    expect(rzp('GET', '/payments/'.$paymentId)->json())->toMatchArray(['status' => 'authorized', 'captured' => false, 'fee' => null])
        ->and(rzp('GET', '/orders/'.$order['id'])->json('status'))->toBe('attempted')
        ->and(rzp('POST', "/payments/{$paymentId}/refund")->json('error.description'))->toContain('not been captured');

    $short = rzp('POST', "/payments/{$paymentId}/capture", ['amount' => 10_000, 'currency' => 'INR']);
    $captured = rzp('POST', "/payments/{$paymentId}/capture", ['amount' => 20_000, 'currency' => 'INR']);
    $twice = rzp('POST', "/payments/{$paymentId}/capture", ['amount' => 20_000, 'currency' => 'INR']);

    expect($short->status())->toBe(400)->and($short->json('error.field'))->toBe('amount')
        ->and($captured->json())->toMatchArray(['status' => 'captured', 'captured' => true])
        ->and($twice->json('error.description'))->toContain('already been captured')
        ->and(rzp('GET', '/orders/'.$order['id'])->json('status'))->toBe('paid');
});

test('refunds are full or partial in paise, bounded by what is left, and fetchable', function () {
    [, $payment] = rzpPaid(100_000);

    $partial = rzp('POST', "/payments/{$payment['id']}/refund", ['amount' => 30_000, 'notes' => ['why' => 'goodwill']]);
    $over = rzp('POST', "/payments/{$payment['id']}/refund", ['amount' => 80_000]);
    $afterPartial = rzp('GET', '/payments/'.$payment['id'])->json();
    $rest = rzp('POST', "/payments/{$payment['id']}/refund");
    $none = rzp('POST', "/payments/{$payment['id']}/refund", ['amount' => 100]);

    expect($partial->json())->toMatchArray(['entity' => 'refund', 'amount' => 30_000, 'payment_id' => $payment['id'], 'currency' => 'INR', 'status' => 'processed'])
        ->and($over->status())->toBe(400)->and($over->json('error.description'))->toContain('70000')
        ->and($afterPartial)->toMatchArray(['status' => 'captured', 'amount_refunded' => 30_000, 'refund_status' => 'partial'])
        ->and($rest->json('amount'))->toBe(70_000)
        ->and(rzp('GET', '/payments/'.$payment['id'])->json())->toMatchArray(['status' => 'refunded', 'amount_refunded' => 100_000, 'refund_status' => 'full'])
        ->and($none->json('error.description'))->toContain('fully refunded')
        ->and(rzp('GET', '/refunds/'.$partial->json('id'))->json('amount'))->toBe(30_000)
        ->and(rzp('GET', "/payments/{$payment['id']}/refunds")->json('count'))->toBe(2);
});

test('a refund the bank rejects fails and gives the amount back to the refundable balance', function () {
    [, $payment] = rzpPaid(40_000);
    $refund = rzp('POST', "/payments/{$payment['id']}/refund")->json();

    expect(rzpAct('refund_failed', ['refund_id' => $refund['id']])['status'])->toBe('failed')
        ->and(rzp('GET', '/refunds/'.$refund['id'])->json('status'))->toBe('failed')
        ->and(rzp('GET', '/payments/'.$payment['id'])->json())->toMatchArray(['status' => 'captured', 'amount_refunded' => 0, 'refund_status' => null]);
});

test('webhook events are signed over the raw body, carry a stable event id and follow the subscription', function () {
    rzpAct('webhook_url', ['url' => 'https://app.example.test/webhooks/razorpay', 'enabled_events' => ['payment.captured', 'order.paid', 'refund.processed', 'payment.failed']]);
    $order = rzpOrder(60_000);
    rzpAct('pay', ['order_id' => $order['id'], 'outcome' => 'failed']);
    $paid = rzpAct('pay', ['order_id' => $order['id']]);
    rzp('POST', "/payments/{$paid['payment_id']}/refund", ['amount' => 10_000]);

    $deliveries = rzpDeliver();
    [$event, $signature, $eventId, $body] = $deliveries[1];

    expect(array_column($deliveries, 0))->toBe(['payment.failed', 'payment.captured', 'order.paid', 'refund.processed'])
        ->and($signature)->toBe(hash_hmac('sha256', $body, RazorpaySimulator::WEBHOOK_SECRET))
        ->and($eventId)->toStartWith('evt_')
        ->and(json_decode($deliveries[2][3], true))->toMatchArray(['entity' => 'event', 'event' => 'order.paid', 'contains' => ['payment', 'order']])
        ->and(json_decode($deliveries[2][3], true)['payload']['order']['entity']['status'])->toBe('paid')
        ->and(json_decode($body, true)['payload']['payment']['entity'])->toMatchArray(['id' => $paid['payment_id'], 'status' => 'captured', 'amount' => 60_000]);

    // A forged delivery is signed with another secret, so the receiver's check fails.
    $this->scenario->outbox()->enqueue('https://app.example.test/webhooks/razorpay', $body, ['X-Razorpay-Event-Id' => $eventId]);
    $forged = $this->scenario->outbox()->pending()[0];
    $sent = [];
    $this->scenario->outbox()->deliver($forged, function (string $url, string $b, array $headers) use (&$sent): int {
        $sent = $headers;

        return 200;
    }, 'wrong_secret');

    expect($sent['X-Razorpay-Signature'])->not->toBe($signature)->and($sent['X-Razorpay-Event-Id'])->toBe($eventId);
});

test('a delivery that is not answered 2xx is retried with the same event id and signature', function () {
    rzpAct('webhook_url', ['url' => 'https://app.example.test/webhooks/razorpay', 'enabled_events' => ['order.paid']]);
    $this->scenario->outbox()->retryPolicy([0], 3);
    rzpPaid();

    $attempts = [];
    $this->scenario->outbox()->drain(function (string $url, string $body, array $headers) use (&$attempts): int {
        $attempts[] = [$headers['X-Razorpay-Event-Id'], $headers['X-Razorpay-Signature']];

        return count($attempts) < 3 ? 500 : 200;
    });

    expect($attempts)->toHaveCount(3)->and(array_unique($attempts, SORT_REGULAR))->toHaveCount(1)
        ->and($this->scenario->outbox()->pending())->toBe([]);
});

test('a payment link pays through its page, redirects with a signed query and fires payment_link.paid', function () {
    rzpAct('webhook_url', ['url' => 'https://app.example.test/webhooks/razorpay', 'enabled_events' => ['payment_link.paid']]);
    $link = rzp('POST', '/payment_links', ['amount' => 113_055, 'currency' => 'INR', 'reference_id' => 'att-26chars-abcdefghij', 'description' => 'Invoice 1001', 'callback_url' => 'https://shop.example.test/return', 'callback_method' => 'get', 'customer' => ['email' => 'c@example.test']]);
    $id = $link->json('id');

    expect($link->json())->toMatchArray(['status' => 'created', 'amount' => 113_055, 'currency' => 'INR', 'reference_id' => 'att-26chars-abcdefghij', 'callback_method' => 'get'])
        ->and($link->json('short_url'))->toBe($this->scenario->apiUrl().'/l/'.$id)
        ->and(rzpBrowser()->get($link->json('short_url'))->body())->toContain('Rs 1,130.55');

    $failed = rzpBrowser()->post($this->scenario->apiUrl()."/l/{$id}/fail");
    $paid = rzpBrowser()->post($this->scenario->apiUrl()."/l/{$id}/pay");
    parse_str((string) parse_url($paid->header('Location'), PHP_URL_QUERY), $query);

    expect($failed->status())->toBe(200)->and($failed->body())->toContain('payment failed')
        ->and($paid->status())->toBe(303)
        ->and($query)->toMatchArray(['razorpay_payment_link_id' => $id, 'razorpay_payment_link_reference_id' => 'att-26chars-abcdefghij', 'razorpay_payment_link_status' => 'paid'])
        ->and($query['razorpay_signature'])->toBe(hash_hmac('sha256', $id.'|att-26chars-abcdefghij|paid|'.$query['razorpay_payment_id'], RazorpaySimulator::KEY_SECRET))
        ->and(rzp('GET', '/payment_links/'.$id)->json())->toMatchArray(['status' => 'paid', 'amount_paid' => 113_055])
        ->and(rzp('GET', '/payments/'.$query['razorpay_payment_id'])->json('order_id'))->toBe($link->json('order_id'))
        ->and(array_column(rzpDeliver(), 0))->toBe(['payment_link.paid'])
        ->and(rzpBrowser()->get($link->json('short_url'))->status())->toBe(410);
});

test('a payment link can be cancelled while open, not after, and expire_by must be 15 minutes out', function () {
    $open = rzp('POST', '/payment_links', ['amount' => 5_000, 'currency' => 'INR'])->json('id');
    $soon = rzp('POST', '/payment_links', ['amount' => 5_000, 'currency' => 'INR', 'expire_by' => time() + 60]);
    $cancelled = rzp('POST', "/payment_links/{$open}/cancel");

    expect($soon->json('error.field'))->toBe('expire_by')
        ->and($cancelled->json('status'))->toBe('cancelled')
        ->and(rzp('POST', "/payment_links/{$open}/cancel")->json('error.description'))->toContain('cannot be cancelled')
        ->and(rzpBrowser()->post($this->scenario->apiUrl()."/l/{$open}/pay")->status())->toBe(410);

    $this->scenario->state()->put('links', $open, [...$this->scenario->state()->get('links', $open), 'status' => 'created', 'expire_by' => time() - 1]);
    expect(rzp('GET', "/payment_links/{$open}")->json('status'))->toBe('expired');
});
