<?php

/*
| Re-check probe R1 for PR #213, adopted as a regression test. The probe expected an early end at minute 6 for every
| window; the fix ends early only when the late window covers the attempt (PaymentReconciler::lateSweepCovers(): 60 min
| lifetime + 60 min lookup interval + 60 min run spacing = 3 hours), so the minute-6 status is now part of the dataset.
| Every case must still book the payment once.
*/

use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\PaymentsNepal\Drivers\EsewaDriver;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
    nepalGateway(EsewaDriver::KEY, ['sandbox' => '1', 'product_code' => 'EPAYTEST', 'secret_key' => 'fake-esewa-secret']);
});

/**
 * @return array<string, mixed>
 */
function rr213Body(PaymentAttempt $attempt, string $status, ?string $refId = null): array
{
    return ['product_code' => 'EPAYTEST', 'transaction_uuid' => $attempt->reference, 'total_amount' => 100.0, 'status' => $status, 'ref_id' => $refId];
}

test('R1: a short late window still books a payment taken inside the booking window after an early NOT_FOUND end', function (int $hours, AttemptStatus $atMinuteSix) {
    billingSettings(['gateways.late_lookup_hours' => $hours]);
    [, $invoice] = nprInvoice([10_000]);
    $checkout = $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => EsewaDriver::KEY])->headers->get('Location');
    $this->get((string) $checkout);
    $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->firstOrFail();

    $paid = false;
    Http::fake(['https://rc.esewa.com.np/api/epay/transaction/status/*' => function () use (&$paid, $attempt) {
        return Http::response(rr213Body($attempt, $paid ? 'COMPLETE' : 'NOT_FOUND', $paid ? '0009RR1' : null));
    }]);

    // Poller sees NOT_FOUND at minute 6 (form not yet posted). With a covering late window the attempt ends as
    // expired with lookups open; otherwise it stays pending and the open poller keeps checking it.
    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments');
    expect($attempt->fresh()->status)->toBe($atMinuteSix);

    // Customer posts the stale form and pays at minute 30; the browser never comes back. Scheduler keeps running.
    $this->travel(24)->minutes();
    $paid = true;
    foreach (range(1, 30) as $_) {
        $this->travel(5)->minutes();
        $this->artisan('billing:reconcile-payments');
    }

    expect(Transaction::query()->where('gateway', EsewaDriver::KEY)->count())->toBe(1);
})->with([
    '1 hour (no early end)' => [1, AttemptStatus::Pending],
    '2 hours (no early end)' => [2, AttemptStatus::Pending],
    '3 hours (ends early, late sweep books it)' => [3, AttemptStatus::Expired],
]);
