<?php

use App\Contracts\Billing\Invoicer;
use App\Contracts\Billing\ResumesHostedPayment;
use App\Modules\Billing\Actions\SettlePaymentAttempt;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Models\GatewayLog;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\PaymentsNepal\Drivers\EsewaDriver;
use App\Modules\PaymentsNepal\Drivers\KhaltiDriver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

/*
| #251: Khalti's lookup has no payment_url, so the one from initiate is kept encrypted on the attempt and "Continue
| payment" reopens it while Khalti still says Initiated. The driver's checks run against the simulator
| (tools/simulators/tests/Khalti); these cover the invoice page, storage, the one lookup per click and the messages.
*/

const KHALTI_RESUME_PAGE = 'https://test-pay.khalti.com/?pidx=PIDXRESUME1';
const KHALTI_RESUME_LOOKUP = 'https://dev.khalti.com/api/v2/epayment/lookup/';

beforeEach(function () {
    Http::preventStrayRequests();
    nepalGateway(KhaltiDriver::KEY, ['sandbox' => '1', 'secret_key' => 'test_secret_key_resume0000000000000000000']);
    $this->khalti = ['pidx' => 'PIDXRESUME1', 'status' => 'Initiated'];
    Http::fake(function (Request $request) {
        if ($request->url() === 'https://dev.khalti.com/api/v2/epayment/initiate/') {
            return Http::response(['pidx' => 'PIDXRESUME1', 'payment_url' => KHALTI_RESUME_PAGE, 'expires_at' => now()->addHour()->toIso8601String(), 'expires_in' => 3600]);
        }

        if ($this->khalti['status'] === 'down') {
            throw new ConnectionException('Khalti is down');
        }

        return Http::response([...$this->khalti, 'total_amount' => 113_000, 'transaction_id' => null, 'fee' => 0, 'refunded' => false]);
    });
});

/** A client starts paying a 1,130.00 NPR invoice with Khalti and is sent to the payment page. */
function startKhaltiForResume(): PaymentAttempt
{
    [, $invoice] = nprInvoice([113_000]);
    test()->post(route('client.invoices.pay', $invoice->id), ['gateway' => KhaltiDriver::KEY])->assertRedirect(KHALTI_RESUME_PAGE);

    return PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
}

function khaltiResumeRoute(PaymentAttempt $attempt): string
{
    return route('client.invoices.attempts.resume', [$attempt->invoice_id, $attempt->reference]);
}

function khaltiLookupsSent(): int
{
    return Http::recorded(fn (Request $request) => $request->url() === KHALTI_RESUME_LOOKUP)->count();
}

test('the invoice offers Continue payment for an open Khalti attempt, which costs one lookup and logs no URL', function () {
    $attempt = startKhaltiForResume();
    $show = $this->get(route('client.invoices.show', $attempt->invoice_id));

    $show->assertInertia(fn (Assert $page) => $page
        ->where('payment_in_progress.gateway', KhaltiDriver::KEY)
        ->where('payment_in_progress.can_resume', true)
        ->where('payment_in_progress.can_close', false)
        ->where('payment_in_progress.can_pay_another_way', false));
    expect(json_encode($show->viewData('page')))->not->toContain('test-pay.khalti.com');

    $this->get(khaltiResumeRoute($attempt))->assertRedirect(KHALTI_RESUME_PAGE);
    expect(khaltiLookupsSent())->toBe(1)
        ->and(GatewayLog::query()->where('result', 'hosted payment reopened')->count())->toBe(1)
        ->and(GatewayLog::query()->get()->toJson())->not->toContain('test-pay.khalti.com');
});

test('a Pending (bank confirming) session is settled from the same lookup and the client is told it is being confirmed', function () {
    $attempt = startKhaltiForResume();
    $this->khalti['status'] = 'Pending';

    $this->get(khaltiResumeRoute($attempt))
        ->assertSessionHasErrors(['billing' => 'Your payment is being confirmed. This invoice updates once it is, so you don\'t need to pay again.']);

    $log = GatewayLog::query()->where('result', 'hosted payment not reopened')->sole();
    expect(khaltiLookupsSent())->toBe(1)
        ->and($attempt->fresh())->status->toBe(AttemptStatus::Pending)->lookup_count->toBe(1)
        ->and($log->payload)->toMatchArray(['reason' => 'confirming', 'status' => 'pending']);
});

test('when Khalti can\'t be reached the client is asked to try again, never to use another method', function () {
    $attempt = startKhaltiForResume();
    $this->khalti['status'] = 'down';

    $this->get(khaltiResumeRoute($attempt))
        ->assertSessionHasErrors(['billing' => 'We couldn\'t reach the payment provider. Try again in a few minutes.']);
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Pending);
});

test('an invoice that no longer has anything to pay is never reopened', function () {
    $attempt = startKhaltiForResume();
    app(Invoicer::class)->cancel($attempt->invoice_id);

    $this->get(route('client.invoices.show', $attempt->invoice_id))->assertInertia(fn (Assert $page) => $page->where('payment_in_progress', fn (mixed $card): bool => $card === null || data_get($card, 'can_resume') === false));
    $this->get(khaltiResumeRoute($attempt))
        ->assertSessionHasErrors(['billing' => 'This invoice has nothing left to pay, so the payment page wasn\'t reopened. If you were charged, contact us.']);
});

test('an attempt sent to review drops the kept URL, and lookup refs never carry it', function () {
    $attempt = startKhaltiForResume();
    expect($attempt->toRef()->resumeUrl)->toBeNull()
        ->and($attempt->toRef(interactive: true, withResumeUrl: true)->resumeUrl)->toBe(KHALTI_RESUME_PAGE);

    $this->khalti['pidx'] = 'PIDXSOMEONEELSE';
    expect(app(SettlePaymentAttempt::class)->handle($attempt))->toBe(AttemptStatus::Ambiguous)
        ->and($attempt->fresh()->resume_url)->toBeNull();
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
