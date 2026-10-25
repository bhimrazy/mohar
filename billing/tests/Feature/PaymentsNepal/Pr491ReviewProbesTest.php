<?php

/*
 * Review board probes for #491 (connectIPS driver). Each test states the behaviour the review expects; a failure
 * reproduces a finding.
 */

use App\Events\Billing\InvoicePaid;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\GatewayLog;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Transaction;
use App\Modules\PaymentsNepal\Drivers\ConnectipsDriver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/connectips-helpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
    nepalGateway(ConnectipsDriver::KEY, connectipsSettings());
});

// Finding 1: an attempt held for review because NCHL says the credit leg failed is re-polled (Ambiguous is polled
// for 14 days); one later gettxndetail outage books it as paid, bypassing criterion 11.
test('PROBE 1: a credit-failed attempt in review is not booked by a later lookup whose gettxndetail fails', function () {
    Event::fake([InvoicePaid::class]);
    [$invoice, $attempt] = startConnectips();
    Http::fake([
        CIPS_VALIDATE => Http::response(cipsValidate($attempt, 'SUCCESS', 'TRANSACTION SUCCESSFUL')),
        CIPS_DETAIL => Http::sequence()->push(cipsDetail($attempt, ['creditStatus' => 'F01']))->push('', 503)->push('', 503)->push('', 503),
    ]);

    $this->get(cipsReturn($attempt));
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Ambiguous);

    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments', ['--only' => 'open'])->assertSuccessful();

    expect(Transaction::query()->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and($attempt->fresh()->status)->toBe(AttemptStatus::Ambiguous);
    Event::assertNotDispatched(InvoicePaid::class);
});

// Finding 1b: on the customer's return (one try, 6 s timeout), a slow gettxndetail settles without any credit check.
test('PROBE 1b: a gettxndetail timeout on the return does not settle without the credit leg', function () {
    [$invoice, $attempt] = startConnectips();
    Http::fake([
        CIPS_VALIDATE => Http::response(cipsValidate($attempt, 'SUCCESS', 'TRANSACTION SUCCESSFUL')),
        CIPS_DETAIL => fn () => throw new ConnectionException('timed out'),
    ]);

    $this->get(cipsReturn($attempt));

    expect(Transaction::query()->count())->toBe(0);
});

// Finding 2: validatetxn's integer echo is accepted as either paisa or whole rupees, so "1130" (Rs 11.30 in paisa,
// NCHL's validatetxn unit) passes for a stored Rs 1,130.00.
test('PROBE 2: validatetxn echoing 1130 for a stored 113000 paisa is a mismatch', function () {
    [$invoice, $attempt] = startConnectips();
    Http::fake([CIPS_VALIDATE => Http::response(cipsValidate($attempt, 'SUCCESS', 'TRANSACTION SUCCESSFUL', ['txnAmt' => '1130'])), CIPS_DETAIL => Http::response(cipsDetail($attempt))]);

    $this->get(cipsReturn($attempt));

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Ambiguous);
});

// Finding 3: a SUCCESS body on a non-2xx validatetxn settles.
test('PROBE 3: a SUCCESS body on HTTP 500 does not settle', function () {
    [$invoice, $attempt] = startConnectips();
    Http::fake([CIPS_VALIDATE => Http::response(cipsValidate($attempt, 'SUCCESS', 'TRANSACTION SUCCESSFUL'), 500), CIPS_DETAIL => Http::response(cipsDetail($attempt))]);

    $this->get(cipsReturn($attempt));

    expect(Transaction::query()->count())->toBe(0);
});

// Expected pass: return, poller and a second return in any order give one transaction and one InvoicePaid, even
// when the first settle fell back to our TXNID and a later one would have read NCHL's id.
test('PROBE 4: return + poller + return book exactly once across the gettxndetail fallback', function () {
    Event::fake([InvoicePaid::class]);
    [$invoice, $attempt] = startConnectips();
    Http::fake([
        CIPS_VALIDATE => Http::response(cipsValidate($attempt, 'SUCCESS', 'TRANSACTION SUCCESSFUL')),
        CIPS_DETAIL => Http::sequence()->push('', 502)->push(cipsDetail($attempt))->push(cipsDetail($attempt)),
    ]);

    $this->get(cipsReturn($attempt));
    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments')->assertSuccessful();
    $this->get(cipsReturn($attempt));

    expect(Transaction::query()->count())->toBe(1)->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
    Event::assertDispatchedTimes(InvoicePaid::class, 1);
});

// Expected pass: a forged return with another gateway's reference format, or a TXNID of another attempt on another
// gateway, never reaches NCHL with that attempt.
test('PROBE 5: a khalti pidx on the connectips return matches nothing', function () {
    [, $attempt] = startConnectips();
    $this->get(route('billing.gateways.return', [ConnectipsDriver::KEY, 'TXNID' => 'Lw9y5TqKkrcbS3nWGaNGtc', 'pidx' => 'x']))
        ->assertSessionHas('inertia.flash_data.toast.message', 'We couldn\'t match this payment. If you were charged, contact us.');
    $this->get(route('billing.gateways.return', ['khalti', 'pidx' => $attempt->provider_ref]));
    Http::assertNothingSent();
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Pending);
});

// Expected pass: no secret in the gateway log after a 401, a ProviderException and a test connection.
test('PROBE 6: secrets stay out of the gateway log on error paths', function () {
    [, $attempt] = startConnectips();
    Http::fake([CIPS_VALIDATE => Http::response(['error' => 'Unauthorized', 'echo' => 'x'], 401)]);
    $this->get(cipsReturn($attempt));
    app(ConnectipsDriver::class)->testConnection(connectipsConfig());

    $stored = json_encode([GatewayLog::query()->get()->toArray(), $attempt->fresh()->toArray()]);
    expect($stored)->not->toContain(CIPS_PASSWORD)->not->toContain('PRIVATE KEY')->not->toContain(base64_encode('MER-550-APP-1:'.CIPS_PASSWORD));
});

// Expected pass: the admin gateway form and the invoice page props never carry the password or the PEM key.
test('PROBE 7: admin gateway form and invoice props hold no app password or private key', function () {
    [$invoice] = startConnectips();
    actingAsStaff();
    $edit = $this->get(route('admin.billing.gateways.edit', ConnectipsDriver::KEY))->assertOk()->getContent();
    $show = $this->get(route('admin.billing.invoices.show', $invoice->id))->assertOk()->getContent();
    $pemBody = explode("\n", connectipsKey()['key'])[1];

    expect($edit.$show)->not->toContain(CIPS_PASSWORD)->not->toContain('PRIVATE KEY')->not->toContain($pemBody);
});
