<?php

/*
| Re-check probes for PR #266 at 9657b18b. R1: the server guard also refuses a direct Khalti close (P2 covers eSewa
| only). R2: an attempt whose gateway is no longer registered is refused cleanly, with no provider call.
*/

use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\PaymentsNepal\Drivers\KhaltiDriver;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../PaymentsNepal/helpers.php';

const R266_REFUSAL = 'This payment can\'t be closed from here. Check its status, or wait until the payment page times out.';

function r266Khalti(): array
{
    nepalGateway(KhaltiDriver::KEY, ['sandbox' => '1', 'secret_key' => 'test_secret_key_f59e8b7d18b4499ca40f68195a846e9b']);
    [, $invoice] = nprInvoice([150_000]);
    Http::fake(['https://dev.khalti.com/api/v2/epayment/initiate/' => Http::response(['pidx' => 'PIDX1', 'payment_url' => 'https://test-pay.khalti.com/?pidx=PIDX1', 'expires_at' => now()->addHour()->toIso8601String(), 'expires_in' => 3600])]);
    test()->post(route('client.invoices.pay', $invoice->id), ['gateway' => KhaltiDriver::KEY]);

    return [$invoice, PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole()];
}

test('R1: a direct POST to close a pending Khalti payment is refused before any lookup', function () {
    [$invoice, $attempt] = r266Khalti();
    Http::fake(fn () => throw new RuntimeException('no provider call expected'));

    $this->from(route('client.invoices.show', $invoice->id))
        ->post(route('client.invoices.attempts.close', [$invoice->id, $attempt->reference]))
        ->assertRedirect(route('client.invoices.show', $invoice->id))
        ->assertSessionHasErrors(['billing' => R266_REFUSAL]);

    expect($attempt->fresh())->status->toBe(AttemptStatus::Pending)->lookup_count->toBe(0)
        ->and(Transaction::query()->count())->toBe(0);
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => KhaltiDriver::KEY])->assertSessionHasErrors('billing');
});

test('R2: an attempt on a gateway that is no longer registered is refused, not a 500', function () {
    [$invoice, $attempt] = r266Khalti();
    $attempt->forceFill(['gateway' => 'removed_gateway'])->save();
    Http::fake(fn () => throw new RuntimeException('no provider call expected'));

    $this->from(route('client.invoices.show', $invoice->id))
        ->post(route('client.invoices.attempts.close', [$invoice->id, $attempt->reference]))
        ->assertRedirect(route('client.invoices.show', $invoice->id))
        ->assertSessionHasErrors(['billing' => R266_REFUSAL]);

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Pending);
});
