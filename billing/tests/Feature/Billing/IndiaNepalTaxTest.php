<?php

use App\Modules\Billing\Models\TaxRule;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;

require_once __DIR__.'/helpers.php';

/** Sensitive staff actions require a recent password confirmation (password.recent, App\Http\Middleware\RequireRecentPasswordConfirmation). */
beforeEach(fn () => $this->withSession(['auth.password_confirmed_at' => time()]));

beforeEach(function () {
    billingSettings(['tax.enabled' => true, 'tax.home_country' => 'IN', 'tax.home_state' => 'MH']);
    Currency::factory()->inr()->create();
    Currency::factory()->npr()->create();
    TaxRule::factory()->create(['name' => 'CGST', 'level' => 1, 'country' => 'IN', 'scope' => TaxRule::HOME_STATE, 'rate' => '9.000']);
    TaxRule::factory()->create(['name' => 'SGST', 'level' => 2, 'country' => 'IN', 'scope' => TaxRule::HOME_STATE, 'rate' => '9.000']);
    TaxRule::factory()->create(['name' => 'IGST', 'level' => 1, 'country' => 'IN', 'scope' => TaxRule::OTHER_STATE, 'rate' => '18.000']);
    TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']);
});

function indianClient(array $attributes): Client
{
    return Client::factory()->create(['country' => 'IN', 'currency' => 'INR', ...$attributes]);
}

test('intra-state supply charges CGST and SGST as two levels', function () {
    $invoice = issueInvoice(indianClient(['state' => 'Maharashtra']), [100_000]);

    expect($invoice)
        ->tax1_name->toBe('CGST')->tax1_amount->toBe(9_000)
        ->tax2_name->toBe('SGST')->tax2_amount->toBe(9_000)
        ->total->toBe(118_000);
});

test('inter-state supply charges IGST only', function () {
    $invoice = issueInvoice(indianClient(['state' => 'KA']), [100_000]);

    expect($invoice)->tax1_name->toBe('IGST')->tax1_amount->toBe(18_000)
        ->tax2_name->toBeNull()->tax2_amount->toBe(0)
        ->total->toBe(118_000);
});

test('a registered business is taxed by the state of its GSTIN', function () {
    $intra = issueInvoice(indianClient(['state' => 'Karnataka', 'tax_id' => '27AAPFU0939F1ZV']), [100_000]);
    $inter = issueInvoice(indianClient(['state' => 'Maharashtra', 'tax_id' => '29AAGCB7383J1Z4']), [100_000]);

    expect($intra->tax1_name)->toBe('CGST')->and($inter->tax1_name)->toBe('IGST')
        ->and($intra->client_snapshot['tax_id'])->toBe('27AAPFU0939F1ZV');
});

test('customers outside India get no GST', function () {
    expect(issueInvoice(billingClient(['country' => 'US']), [100_000])->total)->toBe(100_000);
});

test('Nepal VAT is 13%', function () {
    $invoice = issueInvoice(Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']), [100_000]);

    expect($invoice)->tax1_name->toBe('VAT')->tax1_amount->toBe(13_000)->currency->toBe('NPR');
});

test('staff create scoped GST rules', function () {
    actingAsStaff(['billing.tax.manage']);

    $this->post(route('admin.billing.tax-rules.store'), ['name' => 'UTGST', 'level' => 2, 'country' => 'IN', 'scope' => 'home_state', 'rate' => '9'])->assertRedirect();
    $this->post(route('admin.billing.tax-rules.store'), ['name' => 'X', 'level' => 1, 'scope' => 'everywhere', 'rate' => '1'])->assertSessionHasErrors('scope');

    expect(TaxRule::query()->where('name', 'UTGST')->value('scope'))->toBe('home_state');
});
