<?php

/*
 * payments-gateways-expansion §9.1 and §9.3 (B1): a provider reference is unique per gateway, a return carrying only
 * it finds its attempt, and hosted gateway forms show the return address to register.
 */

use App\Contracts\Billing\GatewayRegistry;
use App\Modules\Billing\Actions\StartHostedPayment;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Billing\FakeSigningGatewayDriver;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    FakeSigningGatewayDriver::$providerRefs = [];
    FakeSigningGatewayDriver::$initiated = 0;
    app(GatewayRegistry::class)->register(new FakeSigningGatewayDriver);
    Gateway::query()->create(['key' => 'fake_signing', 'display_name' => 'connectIPS', 'is_active' => true, 'show_on_order_form' => true, 'config' => ['merchant_id' => 'M-100']]);
    Currency::query()->where('code', 'NPR')->exists() || Currency::factory()->npr()->create();
});

function storedAttempt(array $attributes): PaymentAttempt
{
    return PaymentAttempt::query()->create([
        'reference' => PaymentAttempt::newReference(), 'client_id' => Client::factory()->create()->id, 'amount' => 113_000, 'currency' => 'NPR',
        'document_amount' => 113_000, 'document_currency' => 'NPR', 'status' => AttemptStatus::Pending, ...$attributes,
    ]);
}

function signingInvoice(): Invoice
{
    return issueInvoice(Client::factory()->create(['currency' => 'NPR', 'country' => 'NP']), [113_000]);
}

test('a return with only the provider reference finds the attempt of that gateway', function () {
    FakeSigningGatewayDriver::$providerRefs = ['abcdefghij2345abcdef'];
    $invoice = signingInvoice();
    [$attempt] = app(StartHostedPayment::class)->handle($invoice, 'fake_signing');

    expect($attempt->fresh()->provider_ref)->toBe('abcdefghij2345abcdef');

    $this->get(route('billing.gateways.return', ['fake_signing', 'TXNID' => 'abcdefghij2345abcdef']))
        ->assertRedirect(route('client.invoices.show', $invoice->id));
    expect($attempt->fresh()->last_lookup_at)->not->toBeNull();

    // A reference we never issued, or one issued by another gateway, matches nothing.
    PaymentAttempt::query()->whereKey($attempt->id)->update(['gateway' => 'sandbox_card']);
    $this->get(route('billing.gateways.return', ['fake_signing', 'TXNID' => 'abcdefghij2345abcdef']))->assertRedirect(route('client.invoices.index'));
    $this->get(route('billing.gateways.return', ['fake_signing', 'TXNID' => 'neverissued234567abc']))->assertRedirect(route('client.invoices.index'))
        ->assertSessionHas('inertia.flash_data.toast.message', 'We couldn\'t match this payment. If you were charged, contact us.');
});

test('a provider reference that is already taken is drawn once more, and a second collision fails the start', function () {
    storedAttempt(['gateway' => 'fake_signing', 'provider_ref' => 'takentakentaken23456']);
    FakeSigningGatewayDriver::$providerRefs = ['takentakentaken23456', 'freshfreshfresh23456'];

    [$attempt] = app(StartHostedPayment::class)->handle(signingInvoice(), 'fake_signing');

    expect($attempt->fresh()->provider_ref)->toBe('freshfreshfresh23456')
        ->and(FakeSigningGatewayDriver::$initiated)->toBe(2);

    FakeSigningGatewayDriver::$providerRefs = ['takentakentaken23456', 'takentakentaken23456'];
    $invoice = signingInvoice();

    expect(fn () => app(StartHostedPayment::class)->handle($invoice, 'fake_signing'))->toThrow(BillingException::class);
    expect(PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole())
        ->status->toBe(AttemptStatus::Failed)->provider_ref->toBeNull();
});

test('the database refuses a second attempt with the same provider reference on one gateway', function () {
    storedAttempt(['gateway' => 'fake_signing', 'provider_ref' => 'sameref']);
    storedAttempt(['gateway' => 'sandbox_card', 'provider_ref' => 'sameref']);
    storedAttempt(['gateway' => 'fake_signing', 'provider_ref' => null]);
    storedAttempt(['gateway' => 'fake_signing', 'provider_ref' => null]);

    expect(fn () => storedAttempt(['gateway' => 'fake_signing', 'provider_ref' => 'sameref']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('the migration resolves existing duplicate provider references before building the index', function () {
    // Plain DDL, which may run in the test's transaction (a CONCURRENTLY build may not).
    Schema::table('payment_attempts', fn (Blueprint $table) => $table->dropIndex('payment_attempts_gateway_provider_ref_unique'));
    $paid = storedAttempt(['gateway' => 'fake_signing', 'provider_ref' => 'dup', 'status' => AttemptStatus::Succeeded]);
    $older = storedAttempt(['gateway' => 'fake_signing', 'provider_ref' => 'dup', 'status' => AttemptStatus::Pending]);
    $closed = storedAttempt(['gateway' => 'fake_signing', 'provider_ref' => 'dup', 'status' => AttemptStatus::Expired, 'raw' => ['a' => 1]]);
    $other = storedAttempt(['gateway' => 'sandbox_card', 'provider_ref' => 'dup']);

    (require base_path('app/Modules/Billing/Database/Migrations/2026_11_02_300790_add_provider_ref_unique_to_payment_attempts.php'))->resolveDuplicates();
    Schema::table('payment_attempts', fn (Blueprint $table) => $table->unique(['gateway', 'provider_ref'], 'payment_attempts_gateway_provider_ref_unique'));

    expect($paid->fresh()->provider_ref)->toBe('dup')
        ->and($older->fresh())->provider_ref->toBeNull()->status->toBe(AttemptStatus::Ambiguous)->raw->toBe(['duplicate_provider_ref' => 'dup'])
        ->review_reason->toBe('Another payment had the same provider reference. Check this one at the provider.')
        ->and($closed->fresh())->provider_ref->toBeNull()->status->toBe(AttemptStatus::Expired)->raw->toBe(['a' => 1, 'duplicate_provider_ref' => 'dup'])
        ->and($other->fresh()->provider_ref)->toBe('dup');
    expect(fn () => DB::table('payment_attempts')->where('id', $older->id)->update(['provider_ref' => 'dup']))->toThrow(UniqueConstraintViolationException::class);
});

test('a hosted gateway form shows the return address to register; other gateways do not', function () {
    actingAsStaff(['billing.gateways.manage']);

    $this->get(route('admin.billing.gateways.edit', 'fake_signing'))
        ->assertInertia(fn (Assert $page) => $page->where('return_url', route('billing.gateways.return', 'fake_signing')));
    $this->get(route('admin.billing.gateways.edit', 'bank_transfer'))
        ->assertInertia(fn (Assert $page) => $page->where('return_url', null));
});
