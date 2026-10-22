<?php

use App\Contracts\Billing\ResumesHostedPayment;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\PaymentsNepal\Drivers\EsewaDriver;
use App\Modules\PaymentsNepal\Drivers\KhaltiDriver;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

/*
| #251: Khalti's lookup has no payment_url, so the one from initiate is kept encrypted on the attempt and "Continue
| payment" reopens it while Khalti still says Initiated. The driver's checks run against the simulator
| (tools/simulators/tests/Khalti); these cover the invoice page and storage.
*/

const KHALTI_RESUME_PAGE = 'https://test-pay.khalti.com/?pidx=PIDXRESUME1';

beforeEach(function () {
    Http::preventStrayRequests();
    nepalGateway(KhaltiDriver::KEY, ['sandbox' => '1', 'secret_key' => 'test_secret_key_resume0000000000000000000']);
    Http::fake([
        'https://dev.khalti.com/api/v2/epayment/initiate/' => Http::response(['pidx' => 'PIDXRESUME1', 'payment_url' => KHALTI_RESUME_PAGE, 'expires_at' => now()->addHour()->toIso8601String(), 'expires_in' => 3600]),
        'https://dev.khalti.com/api/v2/epayment/lookup/' => Http::response(['pidx' => 'PIDXRESUME1', 'total_amount' => 113_000, 'status' => 'Initiated', 'transaction_id' => null, 'fee' => 0, 'refunded' => false]),
    ]);
});

/** A client starts paying a 1,130.00 NPR invoice with Khalti and is sent to the payment page. */
function startKhaltiForResume(): PaymentAttempt
{
    [, $invoice] = nprInvoice([113_000]);
    test()->post(route('client.invoices.pay', $invoice->id), ['gateway' => KhaltiDriver::KEY])->assertRedirect(KHALTI_RESUME_PAGE);

    return PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
}

test('the invoice offers Continue payment for an open Khalti attempt, without the page URL in its props', function () {
    $attempt = startKhaltiForResume();
    $show = $this->get(route('client.invoices.show', $attempt->invoice_id));

    $show->assertInertia(fn (Assert $page) => $page
        ->where('payment_in_progress.gateway', KhaltiDriver::KEY)
        ->where('payment_in_progress.can_resume', true)
        ->where('payment_in_progress.can_close', false)
        ->where('payment_in_progress.can_pay_another_way', false));
    expect(json_encode($show->viewData('page')))->not->toContain('test-pay.khalti.com');

    $this->get(route('client.invoices.attempts.resume', [$attempt->invoice_id, $attempt->reference]))->assertRedirect(KHALTI_RESUME_PAGE);
    Http::assertSent(fn ($request) => $request->url() === 'https://dev.khalti.com/api/v2/epayment/lookup/' && $request['pidx'] === 'PIDXRESUME1');
});

test('the kept payment_url is encrypted at rest and never serialised', function () {
    $attempt = startKhaltiForResume();
    $stored = DB::table('payment_attempts')->where('id', $attempt->id)->value('resume_url');

    expect($stored)->toBeString()->not->toContain('khalti')
        ->and(Crypt::decryptString($stored))->toBe(KHALTI_RESUME_PAGE)
        ->and($attempt->fresh()->resume_url)->toBe(KHALTI_RESUME_PAGE)
        ->and($attempt->fresh()->toArray())->not->toHaveKey('resume_url');
});

test('eSewa stays not resumable: its signed form is served once', function () {
    expect(app(EsewaDriver::class))->not->toBeInstanceOf(ResumesHostedPayment::class)
        ->and(app(KhaltiDriver::class))->toBeInstanceOf(ResumesHostedPayment::class);
});
