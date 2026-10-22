<?php

use App\Models\User;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\GatewayLog;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\Client;
use App\Modules\PaymentsStripe\Drivers\StripeDriver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

/*
| #123: after a declined card the Checkout Session stays open. The client can go back to it ("Continue payment") or
| close it ("Use another method"), which expires it at Stripe before the invoice is free again.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    stripeGateway();
});

/**
 * Fake Stripe: the retrieve answers with $session (a closure of the current state), the expire with $expire.
 *
 * @param  Closure(): array<string, mixed>  $session
 * @param  Closure(): mixed  $expire
 */
function fakeStripeSession(Closure $session, ?Closure $expire = null): void
{
    Http::fake(function (Request $request) use ($session, $expire) {
        if ($request->method() === 'POST' && $request->url() === STRIPE_API.'checkout/sessions/'.STRIPE_SESSION.'/expire') {
            return $expire !== null ? $expire() : Http::response(stripeFixture('error_resource_missing'), 400);
        }

        if ($request->method() === 'GET' && str_starts_with($request->url(), STRIPE_API.'checkout/sessions/'.STRIPE_SESSION)) {
            return Http::response($session());
        }

        return null;
    });
}

function closeUrl(PaymentAttempt $attempt): string
{
    return route('client.invoices.attempts.close', [$attempt->invoice_id, $attempt->reference]);
}

function resumeUrl(PaymentAttempt $attempt): string
{
    return route('client.invoices.attempts.resume', [$attempt->invoice_id, $attempt->reference]);
}

test('after a declined card the invoice offers to continue the open session or use another method', function () {
    [, $invoice, $attempt] = startStripe();
    $open = stripeCreatedSession($attempt->reference, $invoice->id);
    fakeStripeSession(fn () => $open);

    $this->get(route('client.invoices.show', $invoice->id))->assertInertia(fn (Assert $page) => $page
        ->where('payment_in_progress.can_resume', true)
        ->where('payment_in_progress.can_close', true)
        ->where('payment_in_progress.can_pay_another_way', false));

    // The session URL is fetched from Stripe on click, never sent as a prop.
    expect(json_encode($this->get(route('client.invoices.show', $invoice->id))->viewData('page')))->not->toContain('checkout.stripe.com');

    $this->get(resumeUrl($attempt), ['X-Inertia' => 'true'])->assertStatus(409)->assertHeader('X-Inertia-Location', $open['url']);
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Pending);
});

test('continue payment never redirects to a url off Stripe\'s checkout host', function (string $url) {
    [, $invoice, $attempt] = startStripe();
    fakeStripeSession(fn () => stripeCreatedSession($attempt->reference, $invoice->id, ['url' => $url]));

    $response = $this->from(route('client.invoices.show', $invoice->id))->get(resumeUrl($attempt))
        ->assertRedirect(route('client.invoices.show', $invoice->id))
        ->assertSessionHasErrors('billing');

    expect($response->headers->get('Location'))->not->toContain('evil');
})->with([
    'another host' => ['https://evil.example/c/pay/cs_test_a1MoharSession0001'],
    'plain http' => ['http://checkout.stripe.com/c/pay/cs_test_a1MoharSession0001'],
    'userinfo' => ['https://checkout.stripe.com@evil.example/c/pay/x'],
]);

test('a session that is no longer open is looked up instead of reopened', function () {
    [, $invoice, $attempt] = startStripe();
    fakeStripeSession(fn () => stripeCreatedSession($attempt->reference, $invoice->id, ['status' => 'expired', 'url' => null]));

    $this->get(resumeUrl($attempt))->assertRedirect(route('client.invoices.show', $invoice->id));

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Expired);
});

test('use another method expires the session at Stripe first, outside any transaction, then frees the invoice', function () {
    [, $invoice, $attempt] = startStripe();
    $state = 'open';
    $levels = [];
    $baseline = DB::transactionLevel();
    fakeStripeSession(
        function () use (&$state, $attempt, $invoice) {
            return stripeCreatedSession($attempt->reference, $invoice->id, ['status' => $state]);
        },
        function () use (&$state, &$levels, $attempt, $invoice) {
            $levels[] = DB::transactionLevel();
            $state = 'expired';

            return Http::response(stripeCreatedSession($attempt->reference, $invoice->id, ['status' => 'expired']));
        },
    );

    $this->post(closeUrl($attempt))->assertRedirect(route('client.invoices.show', $invoice->id))->assertSessionHasNoErrors();

    expect($levels)->toBe([$baseline])
        ->and($attempt->fresh())->status->toBe(AttemptStatus::Expired)->lookups_closed_at->not->toBeNull()
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(GatewayLog::query()->where('result', 'hosted session close: closed')->count())->toBe(1);

    $sent = Http::recorded()->map(fn (array $pair) => $pair[0]->method().' '.strtok($pair[0]->url(), '?'))->values()->all();
    expect(array_slice($sent, -2))->toBe(['POST '.STRIPE_API.'checkout/sessions/'.STRIPE_SESSION.'/expire', 'GET '.STRIPE_API.'checkout/sessions/'.STRIPE_SESSION]);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/expire') && $request->header('Idempotency-Key') === ['mohar-expire-'.$attempt->reference]);

    // Free for a new payment straight away, and the closed attempt is no longer shown.
    $this->get(route('client.invoices.show', $invoice->id))->assertInertia(fn (Assert $page) => $page->where('payment_in_progress', null)->whereNot('payment', null));
});

test('when Stripe says the session was already paid, use another method records the payment instead of freeing the invoice', function () {
    [, $invoice, $attempt] = startStripe();
    fakeStripeSession(fn () => stripePaidSession($attempt), fn () => Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'not open']], 400));

    $this->post(closeUrl($attempt))->assertRedirect(route('client.invoices.show', $invoice->id))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Payment received. Thank you.');

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Succeeded)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(Transaction::query()->where('gateway', StripeDriver::KEY)->count())->toBe(1)
        ->and(GatewayLog::query()->where('result', 'hosted session close: already_final')->count())->toBe(1);
});

test('a double submit expires the session once and changes nothing the second time', function () {
    [, $invoice, $attempt] = startStripe();
    $state = 'open';
    fakeStripeSession(function () use (&$state, $attempt, $invoice) {
        return stripeCreatedSession($attempt->reference, $invoice->id, ['status' => $state]);
    }, function () use (&$state, $attempt, $invoice) {
        $state = 'expired';

        return Http::response(stripeCreatedSession($attempt->reference, $invoice->id, ['status' => 'expired']));
    });

    $this->post(closeUrl($attempt))->assertSessionHasNoErrors();
    $this->post(closeUrl($attempt))->assertRedirect(route('client.invoices.show', $invoice->id))->assertSessionHasNoErrors();

    Http::assertSentCount(2); // expire, then the lookup; the second click sends nothing
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Expired);
});

test('when Stripe can\'t be reached the attempt stays open and the client is told why', function () {
    [, $invoice, $attempt] = startStripe();
    fakeStripeSession(fn () => stripeCreatedSession($attempt->reference, $invoice->id), fn () => throw new ConnectionException('timeout'));

    $this->from(route('client.invoices.show', $invoice->id))->post(closeUrl($attempt))
        ->assertSessionHasErrors(['billing' => 'Stripe still has this payment open, so it could still be paid there. Finish it there, or try again in a few minutes.']);

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Pending)
        ->and(GatewayLog::query()->where('result', 'hosted session close: unreachable')->count())->toBe(1);
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => StripeDriver::KEY])->assertSessionHasErrors('billing');
});

test('another client can neither reopen nor close an attempt', function () {
    [, $invoice, $attempt] = startStripe();
    $other = Client::factory()->create(['currency' => 'USD']);
    $user = User::factory()->create(['email' => $other->email]);
    $other->users()->attach($user, ['is_owner' => true]);
    $this->actingAs($user);
    $sent = Http::recorded()->count();

    $this->get(resumeUrl($attempt))->assertNotFound();
    $this->post(closeUrl($attempt))->assertNotFound();
    // Their own invoice id with the victim's reference is refused too.
    $own = issueInvoice($other, [2_500]);
    $this->post(route('client.invoices.attempts.close', [$own->id, $attempt->reference]))->assertNotFound();

    expect(Http::recorded()->count())->toBe($sent)->and($attempt->fresh()->status)->toBe(AttemptStatus::Pending);
});
