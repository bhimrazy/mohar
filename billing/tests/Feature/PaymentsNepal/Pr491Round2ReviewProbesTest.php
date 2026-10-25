<?php

/*
 * Re-review probes for #491 at 5417838f.
 */

use App\Modules\Billing\Actions\SettlePaymentAttempt;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\PaymentsNepal\Drivers\EsewaDriver;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/connectips-helpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
    nepalGateway('connectips', connectipsSettings());
});

// validatetxn says SUCCESS (money left the customer) but gettxndetail stays down: the invoice stays locked, the attempt
// goes to staff review after the grace period, and it books once gettxndetail answers.
test('R1: validatetxn SUCCESS with gettxndetail down for hours keeps the invoice locked, then goes to review', function () {
    [$invoice, $attempt] = startConnectips();
    Http::fake([CIPS_VALIDATE => Http::response(cipsValidate($attempt, 'SUCCESS', 'TRANSACTION SUCCESSFUL')), CIPS_DETAIL => Http::sequence()
        ->whenEmpty(Http::response('', 503))]);

    $this->get(cipsReturn($attempt));
    foreach (range(1, 8) as $i) {
        $this->travel(31)->minutes();
        $this->artisan('billing:reconcile-payments', ['--only' => 'open'])->assertSuccessful();
    }

    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => 'connectips'])->assertSessionHasErrors('billing');

    expect(Transaction::query()->count())->toBe(0)
        ->and(PaymentAttempt::query()->where('invoice_id', $invoice->id)->count())->toBe(1)
        ->and($attempt->fresh())->status->toBe(AttemptStatus::Ambiguous)->expires_at->toBeNull()
        ->review_reason->toBe('Still pending at the provider after the grace period');
});

test('R2: the stored connectIPS form keeps TXNDATE and no token, and survives a lookup', function () {
    [, $attempt, $fields] = startConnectips();
    $form = $attempt->fresh()->raw[SettlePaymentAttempt::PROVIDER_FORM];

    expect($form['TXNDATE'])->toBe($fields['TXNDATE'])->and($form['TXNID'])->toBe($attempt->provider_ref)
        ->and(json_encode($attempt->fresh()->raw))->not->toContain($fields['TOKEN'])->not->toContain(substr($fields['TOKEN'], 0, 40));

    Http::fake([CIPS_VALIDATE => Http::response(cipsValidate($attempt, 'ERROR', 'TRANSACTION INCOMPLETE'))]);
    $this->get(cipsReturn($attempt));
    expect($attempt->fresh()->raw[SettlePaymentAttempt::PROVIDER_FORM]['TXNDATE'] ?? null)->toBe($fields['TXNDATE']);
});

test('R3: the stored eSewa form keeps no signature or secret', function () {
    nepalGateway(EsewaDriver::KEY, ['sandbox' => '1', 'product_code' => 'EPAYTEST', 'secret_key' => 'r3-esewa-secret-value']);
    [, $invoice] = nprInvoice([113_050]);
    $checkout = test()->post(route('client.invoices.pay', $invoice->id), ['gateway' => EsewaDriver::KEY])->assertRedirect()->headers->get('Location');
    $fields = (array) test()->get((string) $checkout)->assertOk()->inertiaProps('form.fields');
    $raw = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole()->raw;

    expect($fields['signature'] ?? null)->not->toBeNull()
        ->and(json_encode($raw))->not->toContain($fields['signature'])->not->toContain('r3-esewa-secret-value');
});

test('R4: amounts by form — paisa digits, a JSON int and a rupee decimal match', function (mixed $validate, mixed $detail) {
    [$invoice, $attempt] = startConnectips();
    Http::fake([CIPS_VALIDATE => Http::response(cipsValidate($attempt, 'SUCCESS', 'TRANSACTION SUCCESSFUL', ['txnAmt' => $validate])), CIPS_DETAIL => Http::response(cipsDetail($attempt, ['txnAmt' => $detail]))]);
    $this->get(cipsReturn($attempt));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
})->with([
    'strings' => ['113000', '1130.00'],
    'detail in paisa' => ['113000', '113000'],
    'json int' => [113000, '1130.00'],
]);

test('R5: amounts by form — wrong forms never match', function (mixed $validate, mixed $detail) {
    [, $attempt] = startConnectips();
    Http::fake([CIPS_VALIDATE => Http::response(cipsValidate($attempt, 'SUCCESS', 'TRANSACTION SUCCESSFUL', ['txnAmt' => $validate])), CIPS_DETAIL => Http::response(cipsDetail($attempt, ['txnAmt' => $detail]))]);
    $this->get(cipsReturn($attempt));

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Ambiguous)->and(Transaction::query()->count())->toBe(0);
})->with([
    'validate 1130' => ['1130', '1130.00'],
    'detail 1130' => ['113000', '1130'],
    'validate 113000.00' => ['113000.00', '1130.00'],
    'validate 1,130.00' => ['1,130.00', '1130.00'],
]);
