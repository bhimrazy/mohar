<?php

use App\Modules\Billing\Actions\SettlePaymentAttempt;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\PaymentsNepal\Drivers\KhaltiDriver;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

/*
| Review board probes for #328 (rb328). B1: the kept Khalti payment_url must be cleared once the attempt settles
| (CTO decision). B2: a Pending (bank confirming) resume must not tell the client to "use another method".
*/

beforeEach(function () {
    Http::preventStrayRequests();
    nepalGateway(KhaltiDriver::KEY, ['sandbox' => '1', 'secret_key' => 'test_secret_key_resume0000000000000000000']);
    $this->lookupStatus = 'Initiated';
    Http::fake([
        'https://dev.khalti.com/api/v2/epayment/initiate/' => Http::response(['pidx' => 'PIDXPROBE01', 'payment_url' => 'https://test-pay.khalti.com/?pidx=PIDXPROBE01', 'expires_at' => now()->addHour()->toIso8601String(), 'expires_in' => 3600]),
        'https://dev.khalti.com/api/v2/epayment/lookup/' => fn () => Http::response(['pidx' => 'PIDXPROBE01', 'total_amount' => 113_000, 'status' => $this->lookupStatus, 'transaction_id' => $this->lookupStatus === 'Completed' ? 'TXNPROBE01' : null, 'fee' => 0, 'refunded' => false]),
    ]);
});

function rb328Start(): PaymentAttempt
{
    [, $invoice] = nprInvoice([113_000]);
    test()->post(route('client.invoices.pay', $invoice->id), ['gateway' => KhaltiDriver::KEY]);

    return PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
}

test('B1: the kept payment_url is cleared once the attempt settles', function (string $khalti, AttemptStatus $settled) {
    $attempt = rb328Start();
    expect($attempt->resume_url)->not->toBeNull();

    $this->lookupStatus = $khalti;
    $status = app(SettlePaymentAttempt::class)->handle($attempt);

    expect($status)->toBe($settled)
        ->and($attempt->fresh()->resume_url)->toBeNull();
})->with([
    'completed' => ['Completed', AttemptStatus::Succeeded],
    'expired' => ['Expired', AttemptStatus::Expired],
    'cancelled' => ['User canceled', AttemptStatus::Failed],
]);

test('B2: a Pending (bank confirming) resume does not tell a Khalti client to use another method', function () {
    $attempt = rb328Start();
    $this->lookupStatus = 'Pending';

    $response = $this->get(route('client.invoices.attempts.resume', [$attempt->invoice_id, $attempt->reference]));
    $errors = json_encode(session('errors'));

    expect($errors)->not->toContain('another method');
    $response->assertSessionHasErrors(['billing' => 'Your payment is being confirmed. This invoice updates once it is, so you don\'t need to pay again.']);
});
