<?php

use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Core\Models\Currency;
use App\Modules\PaymentsNepal\Drivers\EsewaDriver;
use App\Modules\PaymentsNepal\Drivers\KhaltiDriver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

/*
| What customers and staff see around a hosted payment: an unconfirmed payment stays on the invoice, methods that
| can't take the amount say why, conversions are shown before leaving, and staff know which refunds go through
| the provider.
*/

const STATES_INITIATE = 'https://dev.khalti.com/api/v2/epayment/initiate/';
const STATES_LOOKUP = 'https://dev.khalti.com/api/v2/epayment/lookup/';

beforeEach(function () {
    Http::preventStrayRequests();
    Gateway::factory()->create();
    nepalGateway(KhaltiDriver::KEY, ['sandbox' => '1', 'secret_key' => 'test_secret_key_states_0123456789']);
    nepalGateway(EsewaDriver::KEY, ['sandbox' => '1', 'product_code' => 'EPAYTEST', 'secret_key' => 'states-esewa-secret']);
});

function fakeKhaltiInitiate(): void
{
    Http::fake([STATES_INITIATE => Http::response(['pidx' => 'PIDX1', 'payment_url' => 'https://test-pay.khalti.com/?pidx=PIDX1'])]);
}

test('a started payment stays on the invoice and blocks a second one until its session ends', function () {
    [, $invoice] = nprInvoice([150_000]);
    fakeKhaltiInitiate();

    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => KhaltiDriver::KEY])->assertRedirect();
    $attempt = PaymentAttempt::query()->sole();

    $this->get(route('client.invoices.show', $invoice->id))->assertInertia(fn (Assert $page) => $page
        ->where('payment_in_progress.gateway', KhaltiDriver::KEY)
        ->where('payment_in_progress.gateway_name', 'Khalti')
        ->where('payment_in_progress.status', 'pending')
        ->where('payment_in_progress.amount', 150_000)
        ->where('payment_in_progress.can_pay_another_way', false)
        ->where('payment_in_progress.check_url', route('billing.gateways.return', [KhaltiDriver::KEY, 'ref' => $attempt->reference]))
        ->where('payment_in_progress.id', null)
        ->where('payment_in_progress.review_reason', null));

    $this->from(route('client.invoices.show', $invoice->id))
        ->post(route('client.invoices.pay', $invoice->id), ['gateway' => EsewaDriver::KEY])
        ->assertSessionHasErrors(['billing' => 'A payment with Khalti is already in progress for this invoice. Check its status before paying again.']);
    expect(PaymentAttempt::query()->count())->toBe(1);

    $this->travel(61)->minutes();
    $this->get(route('client.invoices.show', $invoice->id))->assertInertia(fn (Assert $page) => $page
        ->where('payment_in_progress.can_pay_another_way', true));
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => KhaltiDriver::KEY])->assertRedirect('https://test-pay.khalti.com/?pidx=PIDX1');

    expect(PaymentAttempt::query()->count())->toBe(2);
});

test('the invoice stays locked until the session expiry Khalti reports, not a fixed hour', function () {
    [, $invoice] = nprInvoice([150_000]);
    $this->freezeTime();
    Http::fake([STATES_INITIATE => Http::response(['pidx' => 'PIDX1', 'payment_url' => 'https://test-pay.khalti.com/?pidx=PIDX1', 'expires_at' => now()->addMinutes(30)->toIso8601String(), 'expires_in' => 1800])]);

    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => KhaltiDriver::KEY])->assertRedirect();
    expect(PaymentAttempt::query()->sole()->expires_at->toDateTimeString())->toBe(now()->addMinutes(30)->toDateTimeString());

    $this->travel(29)->minutes();
    $this->get(route('client.invoices.show', $invoice->id))->assertInertia(fn (Assert $page) => $page->where('payment_in_progress.can_pay_another_way', false));
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => EsewaDriver::KEY])->assertSessionHasErrors('billing');

    $this->travel(2)->minutes();
    $this->get(route('client.invoices.show', $invoice->id))->assertInertia(fn (Assert $page) => $page->where('payment_in_progress.can_pay_another_way', true));
});

test('an implausible session expiry from Khalti falls back to the configured lifetime', function () {
    [, $invoice] = nprInvoice([150_000]);
    $this->freezeTime();
    Http::fake([STATES_INITIATE => Http::response(['pidx' => 'PIDX1', 'payment_url' => 'https://test-pay.khalti.com/?pidx=PIDX1', 'expires_at' => now()->addYear()->toIso8601String()])]);

    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => KhaltiDriver::KEY]);

    expect(PaymentAttempt::query()->sole()->expires_at->toDateTimeString())->toBe(now()->addMinutes(60)->toDateTimeString());
});

test('a confirmed payment clears the in-progress state', function () {
    [, $invoice] = nprInvoice([150_000]);
    fakeKhaltiInitiate();
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => KhaltiDriver::KEY]);
    $attempt = PaymentAttempt::query()->sole();
    Http::fake([STATES_LOOKUP => Http::response(['pidx' => 'PIDX1', 'total_amount' => 150_000, 'status' => 'Completed', 'transaction_id' => 'TXN-9', 'fee' => 0, 'refunded' => false])]);

    $this->get(route('billing.gateways.return', [KhaltiDriver::KEY, 'pidx' => 'PIDX1', 'purchase_order_id' => $attempt->reference]));

    $this->get(route('client.invoices.show', $invoice->id))->assertInertia(fn (Assert $page) => $page->where('payment_in_progress', null));
});

test('staff see the unconfirmed payment on the admin invoice with the review reason', function () {
    [, $invoice] = nprInvoice([150_000]);
    fakeKhaltiInitiate();
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => KhaltiDriver::KEY]);
    $attempt = PaymentAttempt::query()->sole();
    Http::fake([STATES_LOOKUP => Http::response(['detail' => 'Invalid token.'], 401)]);
    $this->get(route('billing.gateways.return', [KhaltiDriver::KEY, 'pidx' => 'PIDX1', 'purchase_order_id' => $attempt->reference]));
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Ambiguous);

    billingStaff();
    $this->get(route('admin.billing.invoices.show', $invoice->id))->assertInertia(fn (Assert $page) => $page
        ->where('payment_in_progress.id', $attempt->id)
        ->where('payment_in_progress.status', 'ambiguous')
        ->where('payment_in_progress.review_reason', fn (string $reason) => str_contains($reason, 'Khalti rejected the secret key')));
});

test('a method that cannot take the amount is shown disabled with the reason and never preselected', function () {
    [, $invoice] = nprInvoice([500]);

    $this->get(route('client.invoices.show', $invoice->id))->assertInertia(fn (Assert $page) => $page
        ->where('payment.gateways', fn ($gateways) => collect($gateways)->firstWhere('key', KhaltiDriver::KEY)['unavailable_reason'] === 'Khalti needs at least Rs 10.00.'
            && collect($gateways)->firstWhere('key', 'bank_transfer')['unavailable_reason'] === null)
        ->where('payment.selected_gateway', 'bank_transfer'));

    $this->from(route('client.invoices.show', $invoice->id))
        ->post(route('client.invoices.pay', $invoice->id), ['gateway' => KhaltiDriver::KEY])
        ->assertSessionHasErrors(['billing' => 'Khalti needs at least Rs 10.00. Choose another way to pay.']);
    expect(PaymentAttempt::query()->count())->toBe(0);
});

test('a converting gateway shows the amount it will charge, and the payment uses exactly that amount', function () {
    Currency::factory()->npr()->create();
    [, $client] = actingAsClient();
    $invoice = issueInvoice($client, [10_000]);
    Gateway::query()->where('key', KhaltiDriver::KEY)->update(['convert_to_currency' => 'NPR']);
    fakeKhaltiInitiate();

    $this->get(route('client.invoices.show', $invoice->id))->assertInertia(fn (Assert $page) => $page
        ->where('payment.gateways', fn ($gateways) => collect($gateways)->firstWhere('key', KhaltiDriver::KEY)['conversion'] === [
            'amount' => 1_344_000, 'currency' => 'NPR', 'rate' => 134.4, 'from' => 'USD',
            'label' => "You'll pay Rs\u{00A0}13,440.00 on Khalti ($1.00 = Rs\u{00A0}134.40).",
        ]));

    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => KhaltiDriver::KEY])->assertRedirect();

    expect(PaymentAttempt::query()->sole())->amount->toBe(1_344_000)->currency->toBe('NPR')->document_amount->toBe(10_000);
    Http::assertSent(fn (Request $request) => $request->url() === STATES_INITIATE && $request['amount'] === 1_344_000);
});

test('staff see which refunds go through the provider and which gateways are in sandbox mode', function () {
    [, $invoice] = nprInvoice([150_000]);
    billingStaff();

    $this->get(route('admin.billing.invoices.show', $invoice->id))->assertInertia(fn (Assert $page) => $page
        ->where('gateways', function ($gateways) {
            $byKey = collect($gateways)->keyBy('value');

            return $byKey['khalti']['refund'] === true && $byKey['khalti']['partial_refund'] === false && $byKey['khalti']['sandbox'] === true
                && $byKey['esewa']['refund'] === false && $byKey['esewa']['sandbox'] === true
                && $byKey['bank_transfer']['sandbox'] === false;
        }));
});
